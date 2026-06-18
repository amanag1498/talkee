<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\AgencyPayoutReport;
use App\Models\CallSession;
use App\Models\Host;
use App\Models\HostAvailability;
use App\Models\LiveRoom;
use App\Models\LiveRoomGiftEarningLedger;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AgencyDashboardService
{
    private const AGENCY_VISIBLE_PAYOUT_STATUSES = ['approved', 'paid'];

    public function build(Agency $agency, int $perPage = 20, array $filters = []): array
    {
        [$from, $to, $period] = $this->resolveDateRange($filters);

        $hostIds = $agency->hosts()->pluck('id');
        $hostUserIds = Host::query()->whereIn('id', $hostIds)->pluck('user_id');
        $allHosts = Host::query()->where('agency_id', $agency->id)->get(['id', 'agency_id', 'payout_percentage']);

        $callsBase = CallSession::query()->where('agency_id', $agency->id);
        $this->applyDateWindow($callsBase, 'created_at', $from, $to);

        $liveRoomsBase = LiveRoom::query()
            ->whereHas('host', fn ($query) => $query->where('agency_id', $agency->id));
        $this->applyLiveRoomWindow($liveRoomsBase, $from, $to);

        $giftBase = LiveRoomGiftEarningLedger::query()->where('agency_id', $agency->id);
        $this->applyDateWindow($giftBase, 'created_at', $from, $to);

        $payoutBase = AgencyPayoutReport::query()
            ->where('agency_id', $agency->id)
            ->whereIn('status', self::AGENCY_VISIBLE_PAYOUT_STATUSES);
        $this->applyPayoutWindow($payoutBase, $from, $to);

        $activeHostCount = HostAvailability::query()
            ->whereIn('user_id', $hostUserIds)
            ->where(function ($query) {
                $query->where('socket_status', 'online')
                    ->orWhere('manual_status', 'online');
            })->count();

        $hostCallAgg = CallSession::query()
            ->where('agency_id', $agency->id)
            ->tap(fn (Builder $query) => $this->applyDateWindow($query, 'created_at', $from, $to))
            ->selectRaw("
                host_id,
                COUNT(*) as call_count,
                SUM(billable_minutes) as call_minutes,
                SUM(total_coins_charged) as call_gross,
                SUM(CASE WHEN type = 'video' THEN billable_minutes ELSE 0 END) as video_call_minutes,
                SUM(CASE WHEN type = 'video' THEN total_coins_charged ELSE 0 END) as video_call_gross,
                SUM(CASE WHEN type = 'audio' THEN billable_minutes ELSE 0 END) as audio_call_minutes,
                SUM(CASE WHEN type = 'audio' THEN total_coins_charged ELSE 0 END) as audio_call_gross
            ")
            ->groupBy('host_id')
            ->get()
            ->keyBy('host_id');

        $hostGiftAgg = LiveRoomGiftEarningLedger::query()
            ->join('live_rooms', 'live_rooms.id', '=', 'live_room_gift_earning_ledgers.live_room_id')
            ->where('live_room_gift_earning_ledgers.agency_id', $agency->id)
            ->tap(fn ($query) => $this->applyDateWindow($query, 'live_room_gift_earning_ledgers.created_at', $from, $to))
            ->selectRaw("
                live_room_gift_earning_ledgers.host_id as host_id,
                SUM(live_room_gift_earning_ledgers.total_coins) as live_gift_gross,
                SUM(CASE WHEN live_rooms.room_type = 'video' THEN live_room_gift_earning_ledgers.total_coins ELSE 0 END) as video_gift_gross,
                SUM(CASE WHEN live_rooms.room_type = 'audio' THEN live_room_gift_earning_ledgers.total_coins ELSE 0 END) as audio_gift_gross
            ")
            ->groupBy('live_room_gift_earning_ledgers.host_id')
            ->get()
            ->keyBy('host_id');

        $hostPkAgg = LiveRoomGiftEarningLedger::query()
            ->join('live_room_gifts', 'live_room_gifts.id', '=', 'live_room_gift_earning_ledgers.live_room_gift_id')
            ->join('live_room_pk_events', function ($join) {
                $join->on('live_room_pk_events.wallet_transaction_id', '=', 'live_room_gifts.transaction_id')
                    ->where('live_room_pk_events.event_type', '=', 'gift');
            })
            ->where('live_room_gift_earning_ledgers.agency_id', $agency->id)
            ->tap(fn ($query) => $this->applyDateWindow($query, 'live_room_gift_earning_ledgers.created_at', $from, $to))
            ->selectRaw("
                live_room_gift_earning_ledgers.host_id as host_id,
                COUNT(live_room_pk_events.id) as pk_event_count,
                SUM(live_room_gift_earning_ledgers.total_coins) as pk_gross,
                SUM(live_room_gift_earning_ledgers.host_payout_coins) as pk_host_earnings,
                SUM(live_room_gift_earning_ledgers.agency_payout_coins) as pk_agency_earnings
            ")
            ->groupBy('live_room_gift_earning_ledgers.host_id')
            ->get()
            ->keyBy('host_id');

        $hostRoomAgg = LiveRoom::query()
            ->whereHas('host', fn ($query) => $query->where('agency_id', $agency->id))
            ->tap(fn (Builder $query) => $this->applyLiveRoomWindow($query, $from, $to))
            ->selectRaw("
                host_id,
                SUM(CASE WHEN room_type = 'video' THEN GREATEST(TIMESTAMPDIFF(MINUTE, GREATEST(started_at, ?), LEAST(COALESCE(ended_at, last_activity_at, started_at), ?)), 0) ELSE 0 END) as video_room_minutes,
                SUM(CASE WHEN room_type = 'audio' THEN GREATEST(TIMESTAMPDIFF(MINUTE, GREATEST(started_at, ?), LEAST(COALESCE(ended_at, last_activity_at, started_at), ?)), 0) ELSE 0 END) as audio_room_minutes
            ")
            ->addBinding([$from->toDateTimeString(), $to->toDateTimeString(), $from->toDateTimeString(), $to->toDateTimeString()], 'select')
            ->groupBy('host_id')
            ->get()
            ->keyBy('host_id');

        $hosts = Host::query()
            ->with(['user', 'user.hostAvailability'])
            ->where('agency_id', $agency->id)
            ->latest()
            ->paginate($perPage);

        $hosts->setCollection(
            $hosts->getCollection()->map(function (Host $host) use ($hostCallAgg, $hostGiftAgg, $hostPkAgg, $hostRoomAgg) {
                $callAgg = $hostCallAgg->get($host->id);
                $giftAgg = $hostGiftAgg->get($host->id);
                $pkAgg = $hostPkAgg->get($host->id);
                $roomAgg = $hostRoomAgg->get($host->id);
                $hostPct = (float) ($host->payout_percentage ?? 0);
                $agencyPct = (float) ($host->agency?->payout_percentage ?? 0);
                $totalGross = (int) ($callAgg->call_gross ?? 0) + (int) ($giftAgg->live_gift_gross ?? 0);
                $hostPayout = (int) floor(($totalGross * $hostPct) / 100);
                $agencyPayout = (int) floor(($totalGross * $agencyPct) / 100);

                $host->setAttribute('dashboard_call_count', (int) ($callAgg->call_count ?? 0));
                $host->setAttribute('dashboard_call_minutes', (int) ($callAgg->call_minutes ?? 0));
                $host->setAttribute('dashboard_call_gross', (int) ($callAgg->call_gross ?? 0));
                $host->setAttribute('dashboard_live_gift_gross', (int) ($giftAgg->live_gift_gross ?? 0));
                $host->setAttribute('dashboard_video_call_minutes', (int) ($callAgg->video_call_minutes ?? 0));
                $host->setAttribute('dashboard_video_call_gross', (int) ($callAgg->video_call_gross ?? 0));
                $host->setAttribute('dashboard_audio_call_minutes', (int) ($callAgg->audio_call_minutes ?? 0));
                $host->setAttribute('dashboard_audio_call_gross', (int) ($callAgg->audio_call_gross ?? 0));
                $host->setAttribute('dashboard_video_room_minutes', (int) ($roomAgg->video_room_minutes ?? 0));
                $host->setAttribute('dashboard_audio_room_minutes', (int) ($roomAgg->audio_room_minutes ?? 0));
                $host->setAttribute('dashboard_video_gift_gross', (int) ($giftAgg->video_gift_gross ?? 0));
                $host->setAttribute('dashboard_audio_gift_gross', (int) ($giftAgg->audio_gift_gross ?? 0));
                $host->setAttribute('dashboard_pk_gross', (int) ($pkAgg->pk_gross ?? 0));
                $host->setAttribute('dashboard_pk_event_count', (int) ($pkAgg->pk_event_count ?? 0));
                $host->setAttribute('dashboard_pk_host_earnings', (int) ($pkAgg->pk_host_earnings ?? 0));
                $host->setAttribute('dashboard_pk_agency_earnings', (int) ($pkAgg->pk_agency_earnings ?? 0));
                $host->setAttribute('dashboard_total_gross', $totalGross);
                $host->setAttribute('dashboard_host_payout_percentage', $hostPct);
                $host->setAttribute('dashboard_agency_payout_percentage', $agencyPct);
                $host->setAttribute('dashboard_host_payout', $hostPayout);
                $host->setAttribute('dashboard_agency_payout', $agencyPayout);
                $host->setAttribute('dashboard_total_payout', $hostPayout + $agencyPayout);
                $host->setAttribute('dashboard_agency_earnings', $agencyPayout);
                $host->setAttribute('dashboard_live_agency_earnings', $agencyPayout);

                return $host;
            })
        );

        $summaryHostPayout = 0;
        $summaryAgencyPayout = 0;
        foreach ($allHosts as $host) {
            $callAgg = $hostCallAgg->get($host->id);
            $giftAgg = $hostGiftAgg->get($host->id);
            $gross = (int) ($callAgg->call_gross ?? 0) + (int) ($giftAgg->live_gift_gross ?? 0);
            $summaryHostPayout += (int) floor(($gross * (float) ($host->payout_percentage ?? 0)) / 100);
            $summaryAgencyPayout += (int) floor(($gross * (float) ($agency->payout_percentage ?? 0)) / 100);
        }

        $summaryCalls = CallSession::query()
            ->where('agency_id', $agency->id)
            ->tap(fn (Builder $query) => $this->applyDateWindow($query, 'created_at', $from, $to))
            ->selectRaw("
                SUM(CASE WHEN type = 'video' THEN billable_minutes ELSE 0 END) as video_call_minutes,
                SUM(CASE WHEN type = 'video' THEN total_coins_charged ELSE 0 END) as video_call_gross,
                SUM(CASE WHEN type = 'audio' THEN billable_minutes ELSE 0 END) as audio_call_minutes,
                SUM(CASE WHEN type = 'audio' THEN total_coins_charged ELSE 0 END) as audio_call_gross
            ")
            ->first();

        $summaryGifts = LiveRoomGiftEarningLedger::query()
            ->join('live_rooms', 'live_rooms.id', '=', 'live_room_gift_earning_ledgers.live_room_id')
            ->where('live_room_gift_earning_ledgers.agency_id', $agency->id)
            ->tap(fn ($query) => $this->applyDateWindow($query, 'live_room_gift_earning_ledgers.created_at', $from, $to))
            ->selectRaw("
                SUM(CASE WHEN live_rooms.room_type = 'video' THEN live_room_gift_earning_ledgers.total_coins ELSE 0 END) as video_gift_gross,
                SUM(CASE WHEN live_rooms.room_type = 'audio' THEN live_room_gift_earning_ledgers.total_coins ELSE 0 END) as audio_gift_gross
            ")
            ->first();

        $summaryPk = LiveRoomGiftEarningLedger::query()
            ->join('live_room_gifts', 'live_room_gifts.id', '=', 'live_room_gift_earning_ledgers.live_room_gift_id')
            ->join('live_room_pk_events', function ($join) {
                $join->on('live_room_pk_events.wallet_transaction_id', '=', 'live_room_gifts.transaction_id')
                    ->where('live_room_pk_events.event_type', '=', 'gift');
            })
            ->where('live_room_gift_earning_ledgers.agency_id', $agency->id)
            ->tap(fn ($query) => $this->applyDateWindow($query, 'live_room_gift_earning_ledgers.created_at', $from, $to))
            ->selectRaw("
                COUNT(live_room_pk_events.id) as pk_event_count,
                SUM(live_room_gift_earning_ledgers.total_coins) as pk_gross,
                SUM(live_room_gift_earning_ledgers.host_payout_coins) as pk_host_earnings,
                SUM(live_room_gift_earning_ledgers.agency_payout_coins) as pk_agency_earnings
            ")
            ->first();

        $summaryRooms = LiveRoom::query()
            ->whereHas('host', fn ($query) => $query->where('agency_id', $agency->id))
            ->tap(fn (Builder $query) => $this->applyLiveRoomWindow($query, $from, $to))
            ->selectRaw("
                SUM(CASE WHEN room_type = 'video' THEN GREATEST(TIMESTAMPDIFF(MINUTE, GREATEST(started_at, ?), LEAST(COALESCE(ended_at, last_activity_at, started_at), ?)), 0) ELSE 0 END) as video_room_minutes,
                SUM(CASE WHEN room_type = 'audio' THEN GREATEST(TIMESTAMPDIFF(MINUTE, GREATEST(started_at, ?), LEAST(COALESCE(ended_at, last_activity_at, started_at), ?)), 0) ELSE 0 END) as audio_room_minutes
            ")
            ->addBinding([$from->toDateTimeString(), $to->toDateTimeString(), $from->toDateTimeString(), $to->toDateTimeString()], 'select')
            ->first();

        return [
            'summary' => [
                'host_count' => $agency->hosts()->count(),
                'active_host_count' => $activeHostCount,
                'blocked_host_count' => $agency->hosts()->where('is_blocked', true)->count(),
                'live_host_count' => (clone $liveRoomsBase)->where('status', 'live')->distinct('host_id')->count('host_id'),
                'total_calls' => (clone $callsBase)->count(),
                'completed_calls' => (clone $callsBase)->where('status', 'ended')->count(),
                'total_minutes' => (int) (clone $callsBase)->sum('billable_minutes'),
                'call_agency_earnings' => (int) (clone $callsBase)->sum('agency_earning'),
                'live_rooms' => (int) (clone $liveRoomsBase)->count(),
                'live_gift_gross' => (int) (clone $giftBase)->sum('total_coins'),
                'video_room_minutes' => (int) ($summaryRooms->video_room_minutes ?? 0),
                'audio_room_minutes' => (int) ($summaryRooms->audio_room_minutes ?? 0),
                'video_gift_gross' => (int) ($summaryGifts->video_gift_gross ?? 0),
                'audio_gift_gross' => (int) ($summaryGifts->audio_gift_gross ?? 0),
                'pk_event_count' => (int) ($summaryPk->pk_event_count ?? 0),
                'pk_gross' => (int) ($summaryPk->pk_gross ?? 0),
                'pk_host_earnings' => (int) ($summaryPk->pk_host_earnings ?? 0),
                'pk_agency_earnings' => (int) ($summaryPk->pk_agency_earnings ?? 0),
                'video_call_minutes' => (int) ($summaryCalls->video_call_minutes ?? 0),
                'audio_call_minutes' => (int) ($summaryCalls->audio_call_minutes ?? 0),
                'video_call_gross' => (int) ($summaryCalls->video_call_gross ?? 0),
                'audio_call_gross' => (int) ($summaryCalls->audio_call_gross ?? 0),
                'gross_total' => (int) ((clone $callsBase)->sum('total_coins_charged') + (clone $giftBase)->sum('total_coins')),
                'host_payout_percentage' => (float) $agency->hosts()->avg('payout_percentage'),
                'agency_payout_percentage' => (float) $agency->payout_percentage,
                'host_payout_total' => $summaryHostPayout,
                'agency_payout_total' => $summaryAgencyPayout,
                'combined_payout_total' => $summaryHostPayout + $summaryAgencyPayout,
                'payout_reports' => (int) (clone $payoutBase)->count(),
                'approved_unpaid_reports' => (int) (clone $payoutBase)->where('status', 'approved')->count(),
                'approved_unpaid_amount' => (int) (clone $payoutBase)->where('status', 'approved')->sum('final_payable'),
            ],
            'filters' => [
                'period' => $period,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $this->dateRangeLabel($period, $from, $to),
            ],
            'hosts' => $hosts,
            'recentPayoutReports' => AgencyPayoutReport::query()
                ->where('agency_id', $agency->id)
                ->whereIn('status', self::AGENCY_VISIBLE_PAYOUT_STATUSES)
                ->tap(fn (Builder $query) => $this->applyPayoutWindow($query, $from, $to))
                ->latest('period_start')
                ->limit(5)
                ->get(),
            'recentLiveRooms' => LiveRoom::query()
                ->with('host.user')
                ->whereHas('host', fn ($query) => $query->where('agency_id', $agency->id))
                ->tap(fn (Builder $query) => $this->applyLiveRoomWindow($query, $from, $to))
                ->latest('started_at')
                ->limit(5)
                ->get(),
            'topHosts' => Host::query()
                ->with('user')
                ->where('agency_id', $agency->id)
                ->get()
                ->map(function (Host $host) use ($hostCallAgg, $hostGiftAgg, $hostPkAgg) {
                    $callAgg = $hostCallAgg->get($host->id);
                    $giftAgg = $hostGiftAgg->get($host->id);
                    $pkAgg = $hostPkAgg->get($host->id);

                    return [
                        'host' => $host,
                        'gross' => (int) ($callAgg->call_gross ?? 0) + (int) ($giftAgg->live_gift_gross ?? 0),
                        'agency_earnings' => (int) floor((((int) ($callAgg->call_gross ?? 0) + (int) ($giftAgg->live_gift_gross ?? 0)) * (float) ($host->agency?->payout_percentage ?? 0)) / 100),
                        'call_count' => (int) ($callAgg->call_count ?? 0),
                        'pk_gross' => (int) ($pkAgg->pk_gross ?? 0),
                        'pk_event_count' => (int) ($pkAgg->pk_event_count ?? 0),
                    ];
                })
                ->sortByDesc('gross')
                ->take(5)
                ->values(),
        ];
    }

    private function resolveDateRange(array $filters): array
    {
        $period = strtolower(trim((string) ($filters['period'] ?? 'weekly')));
        $today = now()->startOfDay();

        if ($period === 'daily') {
            $from = $today->copy();
            $to = $today->copy()->endOfDay();
        } elseif ($period === 'weekly') {
            $from = $today->copy()->startOfWeek(Carbon::MONDAY);
            $to = $today->copy()->endOfWeek(Carbon::SUNDAY);
        } else {
            $period = 'custom';
            $from = $this->parseDate($filters['from'] ?? null)?->startOfDay() ?? $today->copy()->startOfWeek(Carbon::MONDAY);
            $to = $this->parseDate($filters['to'] ?? null)?->endOfDay() ?? $today->copy()->endOfDay();
        }

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            $period = 'custom';
        }

        return [$from, $to, $period];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function applyDateWindow(Builder $query, string $column, Carbon $from, Carbon $to): void
    {
        $query->whereBetween($column, [$from->toDateTimeString(), $to->toDateTimeString()]);
    }

    private function applyLiveRoomWindow(Builder $query, Carbon $from, Carbon $to): void
    {
        $query
            ->whereNotNull('started_at')
            ->where('started_at', '<=', $to->toDateTimeString())
            ->whereRaw('COALESCE(ended_at, last_activity_at, started_at) >= ?', [$from->toDateTimeString()]);
    }

    private function applyPayoutWindow(Builder $query, Carbon $from, Carbon $to): void
    {
        $query
            ->where('period_start', '<=', $to->toDateTimeString())
            ->where('period_end', '>=', $from->toDateTimeString());
    }

    private function dateRangeLabel(string $period, Carbon $from, Carbon $to): string
    {
        return match ($period) {
            'daily' => 'Daily · '.$from->format('d M Y'),
            'weekly' => 'Weekly · '.$from->format('d M').' - '.$to->format('d M Y'),
            default => 'Custom · '.$from->format('d M Y').' - '.$to->format('d M Y'),
        };
    }

    public function hostDetail(Agency $agency, Host $host, array $filters = []): array
    {
        abort_unless((int) $host->agency_id === (int) $agency->id, 404);
        [$from, $to, $period] = $this->resolveDateRange($filters);

        $callsBase = CallSession::query()
            ->where('agency_id', $agency->id)
            ->where('host_id', $host->id);
        $this->applyDateWindow($callsBase, 'created_at', $from, $to);

        $giftBase = LiveRoomGiftEarningLedger::query()
            ->join('live_rooms', 'live_rooms.id', '=', 'live_room_gift_earning_ledgers.live_room_id')
            ->where('live_room_gift_earning_ledgers.agency_id', $agency->id)
            ->where('live_room_gift_earning_ledgers.host_id', $host->id);
        $this->applyDateWindow($giftBase, 'live_room_gift_earning_ledgers.created_at', $from, $to);

        $liveRoomsBase = LiveRoom::query()->where('host_id', $host->id);
        $this->applyLiveRoomWindow($liveRoomsBase, $from, $to);

        $followerCount = method_exists($host, 'followers')
            ? $host->followers()->count()
            : 0;

        $summaryCalls = (clone $callsBase)
            ->selectRaw("
                SUM(CASE WHEN type = 'video' THEN billable_minutes ELSE 0 END) as video_call_minutes,
                SUM(CASE WHEN type = 'video' THEN total_coins_charged ELSE 0 END) as video_call_gross,
                SUM(CASE WHEN type = 'audio' THEN billable_minutes ELSE 0 END) as audio_call_minutes,
                SUM(CASE WHEN type = 'audio' THEN total_coins_charged ELSE 0 END) as audio_call_gross
            ")
            ->first();

        $summaryGifts = (clone $giftBase)
            ->selectRaw("
                SUM(CASE WHEN live_rooms.room_type = 'video' THEN live_room_gift_earning_ledgers.total_coins ELSE 0 END) as video_gift_gross,
                SUM(CASE WHEN live_rooms.room_type = 'audio' THEN live_room_gift_earning_ledgers.total_coins ELSE 0 END) as audio_gift_gross
            ")
            ->first();

        $summaryPk = LiveRoomGiftEarningLedger::query()
            ->join('live_room_gifts', 'live_room_gifts.id', '=', 'live_room_gift_earning_ledgers.live_room_gift_id')
            ->join('live_room_pk_events', function ($join) {
                $join->on('live_room_pk_events.wallet_transaction_id', '=', 'live_room_gifts.transaction_id')
                    ->where('live_room_pk_events.event_type', '=', 'gift');
            })
            ->where('live_room_gift_earning_ledgers.agency_id', $agency->id)
            ->where('live_room_gift_earning_ledgers.host_id', $host->id)
            ->tap(fn ($query) => $this->applyDateWindow($query, 'live_room_gift_earning_ledgers.created_at', $from, $to))
            ->selectRaw("
                COUNT(live_room_pk_events.id) as pk_event_count,
                SUM(live_room_gift_earning_ledgers.total_coins) as pk_gross,
                SUM(live_room_gift_earning_ledgers.host_payout_coins) as pk_host_earnings,
                SUM(live_room_gift_earning_ledgers.agency_payout_coins) as pk_agency_earnings
            ")
            ->first();

        $summaryRooms = (clone $liveRoomsBase)
            ->selectRaw("
                SUM(CASE WHEN room_type = 'video' THEN GREATEST(TIMESTAMPDIFF(MINUTE, GREATEST(started_at, ?), LEAST(COALESCE(ended_at, last_activity_at, started_at), ?)), 0) ELSE 0 END) as video_room_minutes,
                SUM(CASE WHEN room_type = 'audio' THEN GREATEST(TIMESTAMPDIFF(MINUTE, GREATEST(started_at, ?), LEAST(COALESCE(ended_at, last_activity_at, started_at), ?)), 0) ELSE 0 END) as audio_room_minutes
            ")
            ->addBinding([$from->toDateTimeString(), $to->toDateTimeString(), $from->toDateTimeString(), $to->toDateTimeString()], 'select')
            ->first();

        $totalGross = (int) ((clone $callsBase)->sum('total_coins_charged') + (clone $giftBase)->sum('live_room_gift_earning_ledgers.total_coins'));
        $hostPct = (float) ($host->payout_percentage ?? 0);
        $agencyPct = (float) ($host->agency?->payout_percentage ?? 0);

        return [
            'summary' => [
                'call_count' => (int) (clone $callsBase)->count(),
                'completed_calls' => (int) (clone $callsBase)->where('status', 'ended')->count(),
                'active_calls' => (int) (clone $callsBase)->where('status', 'active')->count(),
                'total_minutes' => (int) (clone $callsBase)->sum('billable_minutes'),
                'call_gross' => (int) (clone $callsBase)->sum('total_coins_charged'),
                'video_call_minutes' => (int) ($summaryCalls->video_call_minutes ?? 0),
                'video_call_gross' => (int) ($summaryCalls->video_call_gross ?? 0),
                'audio_call_minutes' => (int) ($summaryCalls->audio_call_minutes ?? 0),
                'audio_call_gross' => (int) ($summaryCalls->audio_call_gross ?? 0),
                'live_rooms' => (int) (clone $liveRoomsBase)->count(),
                'live_rooms_active' => (int) (clone $liveRoomsBase)->where('status', 'live')->count(),
                'video_room_minutes' => (int) ($summaryRooms->video_room_minutes ?? 0),
                'audio_room_minutes' => (int) ($summaryRooms->audio_room_minutes ?? 0),
                'live_gift_gross' => (int) (clone $giftBase)->sum('live_room_gift_earning_ledgers.total_coins'),
                'video_gift_gross' => (int) ($summaryGifts->video_gift_gross ?? 0),
                'audio_gift_gross' => (int) ($summaryGifts->audio_gift_gross ?? 0),
                'pk_event_count' => (int) ($summaryPk->pk_event_count ?? 0),
                'pk_gross' => (int) ($summaryPk->pk_gross ?? 0),
                'pk_host_earnings' => (int) ($summaryPk->pk_host_earnings ?? 0),
                'pk_agency_earnings' => (int) ($summaryPk->pk_agency_earnings ?? 0),
                'gross_total' => $totalGross,
                'host_payout_percentage' => $hostPct,
                'agency_payout_percentage' => $agencyPct,
                'host_payout' => (int) floor(($totalGross * $hostPct) / 100),
                'agency_payout' => (int) floor(($totalGross * $agencyPct) / 100),
                'total_payout' => (int) floor(($totalGross * $hostPct) / 100) + (int) floor(($totalGross * $agencyPct) / 100),
                'followers' => $followerCount,
            ],
            'filters' => [
                'period' => $period,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $this->dateRangeLabel($period, $from, $to),
            ],
            'recentCalls' => (clone $callsBase)
                ->with(['caller', 'receiver'])
                ->latest('created_at')
                ->limit(10)
                ->get(),
            'recentLiveRooms' => (clone $liveRoomsBase)
                ->latest('started_at')
                ->limit(10)
                ->get(),
            'recentPayoutItems' => $host->payoutReportItems()
                ->with('report')
                ->whereHas('report', fn (Builder $query) => $this->applyPayoutWindow($query, $from, $to))
                ->latest('created_at')
                ->limit(10)
                ->get(),
        ];
    }

    public function agencyProfile(Agency $agency): array
    {
        $owner = $agency->owner;
        $activeHosts = HostAvailability::query()
            ->whereIn('user_id', $agency->hosts()->pluck('user_id'))
            ->where(function ($query) {
                $query->where('socket_status', 'online')
                    ->orWhere('manual_status', 'online');
            })
            ->count();

        return [
            'owner' => $owner,
            'summary' => [
                'host_count' => $agency->hosts()->count(),
                'active_hosts' => $activeHosts,
                'blocked' => (bool) $agency->is_blocked,
                'payout_percentage' => (float) $agency->payout_percentage,
                'weekly_bonus' => (int) $agency->weekly_bonus,
                'payout_reports' => $agency->payoutReports()->count(),
            ],
        ];
    }
}
