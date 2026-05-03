<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\CallSession;
use App\Models\Host;
use App\Models\LiveRoom;
use App\Models\LiveRoomGiftEarningLedger;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;

class AgencyReportService
{
    public function overview(Request $request): array
    {
        [$from, $to] = $this->resolveRange($request);

        $base = CallSession::query()
            ->with(['agency', 'host.user'])
            ->whereBetween('created_at', [$from, $to]);
        $liveBase = LiveRoom::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereHas('host', fn ($query) => $query->whereNotNull('agency_id'));
        $giftBase = LiveRoomGiftEarningLedger::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('agency_id');
        $pkBase = $this->pkGiftBase($from, $to);

        $agencies = Agency::query()
            ->withCount('hosts')
            ->with('owner')
            ->orderBy('name')
            ->get();

        return [
            'from' => $from,
            'to' => $to,
            'agencies' => $agencies,
            'kpis' => [
                'total_agencies' => Agency::query()->count(),
                'active_agencies' => Agency::query()->where('is_blocked', false)->count(),
                'total_hosts' => Host::query()->count(),
                'total_calls' => (clone $base)->count(),
                'audio_calls' => (clone $base)->where('type', 'audio')->count(),
                'video_calls' => (clone $base)->where('type', 'video')->count(),
                'completed_calls' => (clone $base)->where('status', 'ended')->count(),
                'failed_calls' => (clone $base)->whereIn('status', ['failed', 'missed', 'rejected'])->count(),
                'total_minutes' => (int) (clone $base)->sum('billable_minutes'),
                'total_coins' => (int) (clone $base)->sum('total_coins_charged'),
                'host_earnings' => (int) (clone $base)->sum('host_earning'),
                'agency_earnings' => (int) (clone $base)->sum('agency_earning'),
                'live_rooms' => (int) (clone $liveBase)->count(),
                'live_minutes' => (int) (clone $liveBase)->get()->sum(fn (LiveRoom $room) => (int) ($room->duration_minutes ?? 0)),
                'live_gift_coins' => (int) (clone $giftBase)->sum('total_coins'),
                'live_host_earnings' => (int) (clone $giftBase)->sum('host_payout_coins'),
                'live_agency_earnings' => (int) (clone $giftBase)->sum('agency_payout_coins'),
                'pk_gift_coins' => (int) (clone $pkBase)->sum('live_room_gift_earning_ledgers.total_coins'),
                'pk_host_earnings' => (int) (clone $pkBase)->sum('live_room_gift_earning_ledgers.host_payout_coins'),
                'pk_agency_earnings' => (int) (clone $pkBase)->sum('live_room_gift_earning_ledgers.agency_payout_coins'),
                'pk_event_count' => (int) (clone $pkBase)->count(),
            ],
            'charts' => [
                'calls_over_time' => $this->callsOverTime($from, $to),
                'earnings_over_time' => $this->earningsOverTime($from, $to),
                'top_agencies' => $this->topAgencies($from, $to),
                'top_hosts' => $this->topHosts($from, $to),
                'call_type' => $this->callTypeBreakdown($from, $to),
                'call_status' => $this->callStatusBreakdown($from, $to),
                'live_rooms_over_time' => $this->liveRoomsOverTime($from, $to),
                'live_gifts_over_time' => $this->liveGiftsOverTime($from, $to),
            ],
            'weekly_rows' => $this->weeklyRows($from, $to),
        ];
    }

    public function detail(Agency $agency, Request $request): array
    {
        [$from, $to] = $this->resolveRange($request);

        $base = CallSession::query()
            ->with(['caller', 'receiver', 'host.user'])
            ->where('agency_id', $agency->id)
            ->whereBetween('created_at', [$from, $to]);
        $liveBase = LiveRoom::query()
            ->whereHas('host', fn ($query) => $query->where('agency_id', $agency->id))
            ->whereBetween('created_at', [$from, $to]);
        $giftBase = LiveRoomGiftEarningLedger::query()
            ->where('agency_id', $agency->id)
            ->whereBetween('created_at', [$from, $to]);
        $pkBase = $this->pkGiftBase($from, $to, $agency->id);

        $hosts = Host::query()
            ->with('user')
            ->where('agency_id', $agency->id)
            ->withCount('followers')
            ->get()
            ->map(function (Host $host) use ($from, $to) {
                $calls = CallSession::query()
                    ->where('host_id', $host->id)
                    ->whereBetween('created_at', [$from, $to]);
                $liveRooms = LiveRoom::query()
                    ->where('host_id', $host->id)
                    ->whereBetween('created_at', [$from, $to]);
                $liveGifts = LiveRoomGiftEarningLedger::query()
                    ->where('host_id', $host->id)
                    ->whereBetween('created_at', [$from, $to]);
                $pkGifts = $this->pkGiftBase($from, $to, $host->agency_id, $host->id);

                return [
                    'host' => $host,
                    'calls' => (clone $calls)->count(),
                    'minutes' => (int) (clone $calls)->sum('billable_minutes'),
                    'coins' => (int) (clone $calls)->sum('total_coins_charged'),
                    'host_earnings' => (int) (clone $calls)->sum('host_earning'),
                    'agency_earnings' => (int) (clone $calls)->sum('agency_earning'),
                    'live_rooms' => (int) (clone $liveRooms)->count(),
                    'live_gift_coins' => (int) (clone $liveGifts)->sum('total_coins'),
                    'pk_gift_coins' => (int) (clone $pkGifts)->sum('live_room_gift_earning_ledgers.total_coins'),
                    'pk_host_earnings' => (int) (clone $pkGifts)->sum('live_room_gift_earning_ledgers.host_payout_coins'),
                    'pk_agency_earnings' => (int) (clone $pkGifts)->sum('live_room_gift_earning_ledgers.agency_payout_coins'),
                    'pk_event_count' => (int) (clone $pkGifts)->count(),
                ];
            })
            ->sortByDesc('coins')
            ->values();

        return [
            'agency' => $agency->load(['owner', 'hosts.user']),
            'from' => $from,
            'to' => $to,
            'summary' => [
                'hosts' => $agency->hosts()->count(),
                'calls' => (clone $base)->count(),
                'audio_calls' => (clone $base)->where('type', 'audio')->count(),
                'video_calls' => (clone $base)->where('type', 'video')->count(),
                'completed_calls' => (clone $base)->where('status', 'ended')->count(),
                'failed_calls' => (clone $base)->whereIn('status', ['failed', 'missed', 'rejected'])->count(),
                'minutes' => (int) (clone $base)->sum('billable_minutes'),
                'coins' => (int) (clone $base)->sum('total_coins_charged'),
                'host_earnings' => (int) (clone $base)->sum('host_earning'),
                'agency_earnings' => (int) (clone $base)->sum('agency_earning'),
                'live_rooms' => (int) (clone $liveBase)->count(),
                'live_minutes' => (int) (clone $liveBase)->get()->sum(fn (LiveRoom $room) => (int) ($room->duration_minutes ?? 0)),
                'live_gift_coins' => (int) (clone $giftBase)->sum('total_coins'),
                'live_host_earnings' => (int) (clone $giftBase)->sum('host_payout_coins'),
                'live_agency_earnings' => (int) (clone $giftBase)->sum('agency_payout_coins'),
                'pk_gift_coins' => (int) (clone $pkBase)->sum('live_room_gift_earning_ledgers.total_coins'),
                'pk_host_earnings' => (int) (clone $pkBase)->sum('live_room_gift_earning_ledgers.host_payout_coins'),
                'pk_agency_earnings' => (int) (clone $pkBase)->sum('live_room_gift_earning_ledgers.agency_payout_coins'),
                'pk_event_count' => (int) (clone $pkBase)->count(),
            ],
            'hosts_table' => $hosts,
            'weekly_breakdown' => $this->agencyWeeklyBreakdown($agency, $from, $to),
            'recent_calls' => (clone $base)->latest('id')->limit(20)->get(),
            'recent_live_rooms' => (clone $liveBase)->with('host.user')->latest('id')->limit(20)->get(),
        ];
    }

    private function resolveRange(Request $request): array
    {
        $from = $request->date('from') ?: now()->subDays(6)->startOfDay();
        $to = $request->date('to') ?: now()->endOfDay();

        return [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
    }

    private function callsOverTime(Carbon $from, Carbon $to): array
    {
        $rows = CallSession::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as label, COUNT(*) as total')
            ->groupBy('label')
            ->orderBy('label')
            ->get()
            ->pluck('total', 'label');

        return $this->seriesFromDays($from, $to, fn (string $day) => (int) ($rows[$day] ?? 0));
    }

    private function earningsOverTime(Carbon $from, Carbon $to): array
    {
        $rows = CallSession::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as label, SUM(total_coins_charged) as coins, SUM(host_earning) as host_earning, SUM(agency_earning) as agency_earning')
            ->groupBy('label')
            ->orderBy('label')
            ->get()
            ->keyBy('label');

        $days = $this->dayLabels($from, $to);

        return [
            'labels' => $days,
            'coins' => collect($days)->map(fn ($day) => (int) ($rows[$day]->coins ?? 0))->values()->all(),
            'host_earnings' => collect($days)->map(fn ($day) => (int) ($rows[$day]->host_earning ?? 0))->values()->all(),
            'agency_earnings' => collect($days)->map(fn ($day) => (int) ($rows[$day]->agency_earning ?? 0))->values()->all(),
        ];
    }

    private function topAgencies(Carbon $from, Carbon $to): array
    {
        $rows = CallSession::query()
            ->with('agency')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('agency_id')
            ->selectRaw('agency_id, SUM(total_coins_charged) as coins, SUM(agency_earning) as earnings')
            ->groupBy('agency_id')
            ->orderByDesc('coins')
            ->limit(8)
            ->get();

        return [
            'labels' => $rows->map(fn ($row) => $row->agency?->name ?? ('Agency #' . $row->agency_id))->all(),
            'coins' => $rows->map(fn ($row) => (int) $row->coins)->all(),
            'earnings' => $rows->map(fn ($row) => (int) $row->earnings)->all(),
        ];
    }

    private function topHosts(Carbon $from, Carbon $to): array
    {
        $rows = CallSession::query()
            ->with('host.user')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('host_id')
            ->selectRaw('host_id, SUM(total_coins_charged) as coins, COUNT(*) as calls')
            ->groupBy('host_id')
            ->orderByDesc('coins')
            ->limit(8)
            ->get();

        return [
            'labels' => $rows->map(fn ($row) => $row->host?->user?->name ?? ('Host #' . $row->host_id))->all(),
            'coins' => $rows->map(fn ($row) => (int) $row->coins)->all(),
            'calls' => $rows->map(fn ($row) => (int) $row->calls)->all(),
        ];
    }

    private function callTypeBreakdown(Carbon $from, Carbon $to): array
    {
        $audio = CallSession::query()->whereBetween('created_at', [$from, $to])->where('type', 'audio')->count();
        $video = CallSession::query()->whereBetween('created_at', [$from, $to])->where('type', 'video')->count();

        return [
            'labels' => ['Audio', 'Video'],
            'values' => [(int) $audio, (int) $video],
        ];
    }

    private function callStatusBreakdown(Carbon $from, Carbon $to): array
    {
        $statuses = ['requested', 'ringing', 'accepted', 'ended', 'rejected', 'missed', 'failed'];
        $counts = CallSession::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'labels' => collect($statuses)->map(fn ($status) => ucfirst($status))->all(),
            'values' => collect($statuses)->map(fn ($status) => (int) ($counts[$status] ?? 0))->all(),
        ];
    }

    private function liveRoomsOverTime(Carbon $from, Carbon $to): array
    {
        $rows = LiveRoom::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereHas('host', fn ($query) => $query->whereNotNull('agency_id'))
            ->selectRaw('DATE(created_at) as label, COUNT(*) as total')
            ->groupBy('label')
            ->orderBy('label')
            ->get()
            ->pluck('total', 'label');

        return $this->seriesFromDays($from, $to, fn (string $day) => (int) ($rows[$day] ?? 0));
    }

    private function liveGiftsOverTime(Carbon $from, Carbon $to): array
    {
        $rows = LiveRoomGiftEarningLedger::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('agency_id')
            ->selectRaw('DATE(created_at) as label, SUM(total_coins) as gift_coins, SUM(host_payout_coins) as host_earnings, SUM(agency_payout_coins) as agency_earnings')
            ->groupBy('label')
            ->orderBy('label')
            ->get()
            ->keyBy('label');

        $days = $this->dayLabels($from, $to);

        return [
            'labels' => $days,
            'gift_coins' => collect($days)->map(fn ($day) => (int) ($rows[$day]->gift_coins ?? 0))->values()->all(),
            'host_earnings' => collect($days)->map(fn ($day) => (int) ($rows[$day]->host_earnings ?? 0))->values()->all(),
            'agency_earnings' => collect($days)->map(fn ($day) => (int) ($rows[$day]->agency_earnings ?? 0))->values()->all(),
        ];
    }

    private function weeklyRows(Carbon $from, Carbon $to): array
    {
        $rows = Agency::query()
            ->with(['hosts.user'])
            ->withCount('hosts')
            ->get()
            ->map(function (Agency $agency) use ($from, $to) {
                $calls = CallSession::query()
                    ->where('agency_id', $agency->id)
                    ->whereBetween('created_at', [$from, $to]);
                $liveRooms = LiveRoom::query()
                    ->whereHas('host', fn ($query) => $query->where('agency_id', $agency->id))
                    ->whereBetween('created_at', [$from, $to]);
                $liveGifts = LiveRoomGiftEarningLedger::query()
                    ->where('agency_id', $agency->id)
                    ->whereBetween('created_at', [$from, $to]);
                $pkGifts = $this->pkGiftBase($from, $to, $agency->id);

                $topHostHostId = (clone $calls)
                    ->selectRaw('host_id, SUM(total_coins_charged) as coins')
                    ->whereNotNull('host_id')
                    ->groupBy('host_id')
                    ->orderByDesc('coins')
                    ->value('host_id');

                return [
                    'agency' => $agency,
                    'host_count' => (int) $agency->hosts_count,
                    'calls' => (clone $calls)->count(),
                    'minutes' => (int) (clone $calls)->sum('billable_minutes'),
                    'coins' => (int) (clone $calls)->sum('total_coins_charged'),
                    'earnings' => (int) (clone $calls)->sum('agency_earning'),
                    'live_rooms' => (int) (clone $liveRooms)->count(),
                    'live_minutes' => (int) (clone $liveRooms)->get()->sum(fn (LiveRoom $room) => (int) ($room->duration_minutes ?? 0)),
                    'live_gift_coins' => (int) (clone $liveGifts)->sum('total_coins'),
                    'live_agency_earnings' => (int) (clone $liveGifts)->sum('agency_payout_coins'),
                    'pk_gift_coins' => (int) (clone $pkGifts)->sum('live_room_gift_earning_ledgers.total_coins'),
                    'pk_agency_earnings' => (int) (clone $pkGifts)->sum('live_room_gift_earning_ledgers.agency_payout_coins'),
                    'pk_event_count' => (int) (clone $pkGifts)->count(),
                    'top_host' => $topHostHostId ? Host::query()->with('user')->find($topHostHostId)?->user?->name : null,
                ];
            })
            ->sortByDesc('coins')
            ->values();

        return $rows->all();
    }

    private function agencyWeeklyBreakdown(Agency $agency, Carbon $from, Carbon $to): array
    {
        $period = CarbonPeriod::create($from->copy()->startOfWeek(), '1 week', $to->copy()->endOfWeek());

        return collect($period)->map(function (Carbon $weekStart) use ($agency, $to) {
            $weekEnd = $weekStart->copy()->endOfWeek();
            if ($weekEnd->greaterThan($to)) {
                $weekEnd = $to->copy();
            }
            $calls = CallSession::query()
                ->where('agency_id', $agency->id)
                ->whereBetween('created_at', [$weekStart, $weekEnd]);
            $liveRooms = LiveRoom::query()
                ->whereHas('host', fn ($query) => $query->where('agency_id', $agency->id))
                ->whereBetween('created_at', [$weekStart, $weekEnd]);
            $liveGifts = LiveRoomGiftEarningLedger::query()
                ->where('agency_id', $agency->id)
                ->whereBetween('created_at', [$weekStart, $weekEnd]);
            $pkGifts = $this->pkGiftBase($weekStart, $weekEnd, $agency->id);

            return [
                'week_start' => $weekStart->format('Y-m-d'),
                'calls' => (clone $calls)->count(),
                'minutes' => (int) (clone $calls)->sum('billable_minutes'),
                'coins' => (int) (clone $calls)->sum('total_coins_charged'),
                'host_earnings' => (int) (clone $calls)->sum('host_earning'),
                'agency_earnings' => (int) (clone $calls)->sum('agency_earning'),
                'live_rooms' => (int) (clone $liveRooms)->count(),
                'live_minutes' => (int) (clone $liveRooms)->get()->sum(fn (LiveRoom $room) => (int) ($room->duration_minutes ?? 0)),
                'live_gift_coins' => (int) (clone $liveGifts)->sum('total_coins'),
                'live_agency_earnings' => (int) (clone $liveGifts)->sum('agency_payout_coins'),
                'pk_gift_coins' => (int) (clone $pkGifts)->sum('live_room_gift_earning_ledgers.total_coins'),
                'pk_agency_earnings' => (int) (clone $pkGifts)->sum('live_room_gift_earning_ledgers.agency_payout_coins'),
                'pk_event_count' => (int) (clone $pkGifts)->count(),
            ];
        })->values()->all();
    }

    private function pkGiftBase(Carbon $from, Carbon $to, ?int $agencyId = null, ?int $hostId = null)
    {
        return LiveRoomGiftEarningLedger::query()
            ->join('live_room_gifts', 'live_room_gifts.id', '=', 'live_room_gift_earning_ledgers.live_room_gift_id')
            ->join('live_room_pk_events', function ($join) {
                $join->on('live_room_pk_events.wallet_transaction_id', '=', 'live_room_gifts.transaction_id')
                    ->where('live_room_pk_events.event_type', '=', 'gift');
            })
            ->whereBetween('live_room_gift_earning_ledgers.created_at', [$from, $to])
            ->when($agencyId !== null, fn ($query) => $query->where('live_room_gift_earning_ledgers.agency_id', $agencyId))
            ->when($hostId !== null, fn ($query) => $query->where('live_room_gift_earning_ledgers.host_id', $hostId));
    }

    private function seriesFromDays(Carbon $from, Carbon $to, callable $resolver): array
    {
        $days = $this->dayLabels($from, $to);

        return [
            'labels' => $days,
            'values' => collect($days)->map(fn ($day) => $resolver($day))->values()->all(),
        ];
    }

    private function dayLabels(Carbon $from, Carbon $to): array
    {
        return collect(CarbonPeriod::create($from, '1 day', $to))
            ->map(fn (Carbon $day) => $day->format('Y-m-d'))
            ->values()
            ->all();
    }
}
