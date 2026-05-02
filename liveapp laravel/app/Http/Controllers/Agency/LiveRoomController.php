<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\LiveRoom;
use Illuminate\Http\Request;

class LiveRoomController extends Controller
{
    public function index(Request $request, string $roomType)
    {
        abort_unless(in_array($roomType, ['video', 'audio'], true), 404);

        $agency = Agency::query()->where('owner_user_id', $request->user()->id)->firstOrFail();

        $rooms = $this->baseQuery($agency->id, $roomType, $request)->paginate(20)->withQueryString();

        return view('agency.live-rooms.index', [
            'agency' => $agency,
            'rooms' => $rooms,
            'roomType' => $roomType,
        ]);
    }

    public function show(Request $request, string $roomType, LiveRoom $live_room)
    {
        abort_unless(in_array($roomType, ['video', 'audio'], true), 404);
        $agency = Agency::query()->where('owner_user_id', $request->user()->id)->firstOrFail();
        abort_unless((int) $live_room->host?->agency_id === (int) $agency->id, 404);
        abort_unless((string) ($live_room->room_type ?? 'video') === $roomType, 404);

        $live_room->load(['host.user', 'participants.user', 'gifts.sender', 'gifts.gift', 'giftEarningLedgers']);

        $stats = [
            'participants_open' => $live_room->participants->whereNull('left_at')->count(),
            'host_open' => $live_room->participants->whereNull('left_at')->where('role', 'host')->count(),
            'speaker_open' => $live_room->participants->whereNull('left_at')->where('role', 'speaker')->count(),
            'gift_coins' => (int) $live_room->gifts->sum('total_coins'),
            'gift_events' => (int) $live_room->gifts->sum('quantity'),
            'gift_host_earnings' => (int) $live_room->giftEarningLedgers->sum('host_payout_coins'),
            'gift_agency_earnings' => (int) $live_room->giftEarningLedgers->sum('agency_payout_coins'),
            'gift_platform_earnings' => (int) $live_room->giftEarningLedgers->sum('platform_revenue_coins'),
            'duration_min' => $live_room->duration_minutes,
        ];

        return view('agency.live-rooms.show', [
            'agency' => $agency,
            'live_room' => $live_room,
            'roomType' => $roomType,
            'stats' => $stats,
        ]);
    }

    private function baseQuery(int $agencyId, string $roomType, Request $request)
    {
        return LiveRoom::query()
            ->with(['host.user'])
            ->withCount([
                'participants as open_participant_count' => fn ($query) => $query->whereNull('left_at'),
                'participants as open_host_count' => fn ($query) => $query->whereNull('left_at')->where('role', 'host'),
                'participants as open_speaker_count' => fn ($query) => $query->whereNull('left_at')->where('role', 'speaker'),
            ])
            ->where('room_type', $roomType)
            ->whereHas('host', fn ($query) => $query->where('agency_id', $agencyId))
            ->when($request->filled('s'), function ($query) use ($request) {
                $s = $request->string('s')->trim()->toString();
                $query->where(function ($inner) use ($s) {
                    $inner->where('room_id', 'like', "%{$s}%")
                        ->orWhere('title', 'like', "%{$s}%")
                        ->orWhereHas('host.user', fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest('started_at');
    }
}
