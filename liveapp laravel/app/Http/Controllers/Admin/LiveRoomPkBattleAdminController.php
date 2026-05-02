<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LiveRoomPkBattle;
use App\Models\LiveRoomPkEvent;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LiveRoomPkBattleAdminController extends Controller
{
    public function index(Request $request)
    {
        $query = LiveRoomPkBattle::query()
            ->with(['roomA.host.user', 'roomB.host.user', 'hostA.user', 'hostB.user', 'winnerRoom'])
            ->whereHas('roomA', fn ($q) => $q->where('room_type', 'video'))
            ->whereHas('roomB', fn ($q) => $q->where('room_type', 'video'));

        if ($status = $request->string('status')->trim()->toString()) {
            $query->where('status', $status);
        }

        $battles = $query->latest('id')->paginate(25);

        $summary = [
            'active' => LiveRoomPkBattle::query()->whereHas('roomA', fn ($q) => $q->where('room_type', 'video'))->whereHas('roomB', fn ($q) => $q->where('room_type', 'video'))->where('status', 'active')->count(),
            'pending' => LiveRoomPkBattle::query()->whereHas('roomA', fn ($q) => $q->where('room_type', 'video'))->whereHas('roomB', fn ($q) => $q->where('room_type', 'video'))->where('status', 'pending')->count(),
            'completed' => LiveRoomPkBattle::query()->whereHas('roomA', fn ($q) => $q->where('room_type', 'video'))->whereHas('roomB', fn ($q) => $q->where('room_type', 'video'))->where('status', 'completed')->count(),
            'failed' => LiveRoomPkBattle::query()->whereHas('roomA', fn ($q) => $q->where('room_type', 'video'))->whereHas('roomB', fn ($q) => $q->where('room_type', 'video'))->whereIn('status', ['cancelled', 'failed', 'expired', 'rejected'])->count(),
            'total_pk_coins' => (int) LiveRoomPkEvent::query()->whereHas('battle.roomA', fn ($q) => $q->where('room_type', 'video'))->whereHas('battle.roomB', fn ($q) => $q->where('room_type', 'video'))->sum('coins'),
        ];

        $topHosts = LiveRoomPkBattle::query()
            ->selectRaw('winner_room_id, host_a_id, host_b_id')
            ->whereHas('roomA', fn ($q) => $q->where('room_type', 'video'))
            ->whereHas('roomB', fn ($q) => $q->where('room_type', 'video'))
            ->where('status', 'completed')
            ->get()
            ->flatMap(function (LiveRoomPkBattle $battle) {
                $winnerHostId = (int) $battle->winner_room_id === (int) $battle->room_a_id
                    ? (int) $battle->host_a_id
                    : ((int) $battle->winner_room_id === (int) $battle->room_b_id ? (int) $battle->host_b_id : null);

                return $winnerHostId ? [$winnerHostId] : [];
            })
            ->countBy()
            ->sortDesc()
            ->take(10);

        return view('admin.pk-battles.index', compact('battles', 'summary', 'topHosts'));
    }

    public function show(LiveRoomPkBattle $pk_battle)
    {
        abort_unless($pk_battle->roomA?->room_type === 'video' && $pk_battle->roomB?->room_type === 'video', 404);

        $pk_battle->load([
            'roomA.host.user',
            'roomB.host.user',
            'hostA.user',
            'hostB.user',
            'winnerRoom',
            'events.user',
            'events.walletTransaction',
            'events.gift',
        ]);

        $contributors = LiveRoomPkEvent::query()
            ->with('user:id,name')
            ->where('pk_battle_id', $pk_battle->id)
            ->where('event_type', 'gift')
            ->selectRaw('user_id, SUM(coins) as total_coins, COUNT(*) as contributions')
            ->groupBy('user_id')
            ->orderByDesc('total_coins')
            ->limit(20)
            ->get();

        $timeline = LiveRoomPkEvent::query()
            ->where('pk_battle_id', $pk_battle->id)
            ->orderBy('id')
            ->get(['id', 'room_id', 'event_type', 'coins', 'created_at', 'wallet_transaction_id'])
            ->map(fn ($event) => [
                'id' => $event->id,
                'room_id' => $event->room_id,
                'event_type' => $event->event_type,
                'coins' => (int) $event->coins,
                'wallet_transaction_id' => $event->wallet_transaction_id,
                'created_at' => optional($event->created_at)->toIso8601String(),
            ]);

        return view('admin.pk-battles.show', compact('pk_battle', 'contributors', 'timeline'));
    }

    public function export(Request $request): StreamedResponse
    {
        $status = $request->string('status')->trim()->toString();
        $query = LiveRoomPkBattle::query()
            ->with(['roomA.host.user', 'roomB.host.user', 'winnerRoom'])
            ->whereHas('roomA', fn ($q) => $q->where('room_type', 'video'))
            ->whereHas('roomB', fn ($q) => $q->where('room_type', 'video'))
            ->latest('id');

        if ($status !== '') {
            $query->where('status', $status);
        }

        $filename = 'pk-battles-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'battle_id', 'room_a', 'host_a', 'room_b', 'host_b', 'status',
                'score_a', 'score_b', 'winner_room_id', 'duration_seconds',
                'started_at', 'ended_at', 'end_reason',
            ]);

            $query->chunk(200, function ($rows) use ($out) {
                foreach ($rows as $battle) {
                    fputcsv($out, [
                        $battle->battle_id,
                        $battle->roomA?->room_id,
                        $battle->roomA?->host?->stage_name ?: $battle->roomA?->host?->user?->name,
                        $battle->roomB?->room_id,
                        $battle->roomB?->host?->stage_name ?: $battle->roomB?->host?->user?->name,
                        $battle->status,
                        $battle->score_a,
                        $battle->score_b,
                        $battle->winnerRoom?->room_id,
                        $battle->duration_seconds,
                        optional($battle->started_at)->toDateTimeString(),
                        optional($battle->ended_at)->toDateTimeString(),
                        $battle->end_reason,
                    ]);
                }
            });
            fclose($out);
        }, $filename);
    }
}
