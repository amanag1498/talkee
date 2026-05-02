<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\LiveRoomPkBattle;
use App\Models\LiveRoomPkEvent;
use Illuminate\Http\Request;

class PkBattleController extends Controller
{
    public function index(Request $request)
    {
        $agency = Agency::query()->where('owner_user_id', $request->user()->id)->firstOrFail();

        $query = $this->baseQuery($agency->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()));

        $battles = $query->latest('id')->paginate(20)->withQueryString();

        $summary = [
            'active' => (clone $this->baseQuery($agency->id))->where('status', 'active')->count(),
            'pending' => (clone $this->baseQuery($agency->id))->where('status', 'pending')->count(),
            'completed' => (clone $this->baseQuery($agency->id))->where('status', 'completed')->count(),
            'total_pk_coins' => (int) LiveRoomPkEvent::query()
                ->whereHas('battle', function ($query) use ($agency) {
                    $query->where(function ($inner) use ($agency) {
                        $inner->whereHas('hostA', fn ($q) => $q->where('agency_id', $agency->id))
                            ->orWhereHas('hostB', fn ($q) => $q->where('agency_id', $agency->id));
                    });
                })
                ->sum('coins'),
        ];

        return view('agency.pk-battles.index', compact('agency', 'battles', 'summary'));
    }

    public function show(Request $request, LiveRoomPkBattle $pk_battle)
    {
        $agency = Agency::query()->where('owner_user_id', $request->user()->id)->firstOrFail();
        abort_unless($this->belongsToAgency($pk_battle, $agency->id), 404);

        $pk_battle->load([
            'roomA.host.user',
            'roomB.host.user',
            'hostA.user',
            'hostB.user',
            'winnerRoom',
            'events.user',
            'events.gift',
        ]);

        return view('agency.pk-battles.show', [
            'agency' => $agency,
            'pk_battle' => $pk_battle,
        ]);
    }

    private function baseQuery(int $agencyId)
    {
        return LiveRoomPkBattle::query()
            ->with(['roomA.host.user', 'roomB.host.user', 'hostA.user', 'hostB.user', 'winnerRoom'])
            ->where(function ($query) use ($agencyId) {
                $query->whereHas('hostA', fn ($q) => $q->where('agency_id', $agencyId))
                    ->orWhereHas('hostB', fn ($q) => $q->where('agency_id', $agencyId));
            })
            ->whereHas('roomA', fn ($q) => $q->where('room_type', 'video'))
            ->whereHas('roomB', fn ($q) => $q->where('room_type', 'video'));
    }

    private function belongsToAgency(LiveRoomPkBattle $battle, int $agencyId): bool
    {
        return (int) $battle->hostA?->agency_id === $agencyId || (int) $battle->hostB?->agency_id === $agencyId;
    }
}
