<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\AgencyPayoutReport;
use App\Models\CallSession;
use App\Models\Host;
use App\Models\HostAvailability;
use App\Models\LiveRoom;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class AgencyDashboardService
{
    private const AGENCY_VISIBLE_PAYOUT_STATUSES = ['approved', 'paid'];

    public function __construct(
        private readonly AgencyWeeklyPayoutReportService $settlementService,
    ) {
    }

    public function build(Agency $agency, int $perPage = 20, array $filters = []): array
    {
        [$from, $to, $period] = $this->resolveDateRange($filters);

        $hostIds = $agency->hosts()->pluck('id');
        $hostUserIds = Host::query()->whereIn('id', $hostIds)->pluck('user_id');
        $callsBase = CallSession::query()->where('agency_id', $agency->id);
        $this->applyDateWindow($callsBase, 'created_at', $from, $to);

        $liveRoomsBase = LiveRoom::query()
            ->whereHas('host', fn ($query) => $query->where('agency_id', $agency->id));
        $this->applyLiveRoomWindow($liveRoomsBase, $from, $to);

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

        $settlement = $this->settlementService->aggregateAgencySettlementMetrics($agency, $from, $to);
        $hostMetrics = $settlement['metrics'];
        $settlementTotals = $settlement['totals'];

        $hosts = Host::query()
            ->with(['user', 'user.hostAvailability'])
            ->where('agency_id', $agency->id)
            ->latest()
            ->paginate($perPage);

        $hosts->setCollection(
            $hosts->getCollection()->map(function (Host $host) use ($hostMetrics) {
                $metric = $hostMetrics->get($host->id, []);

                $host->setAttribute('dashboard_call_count', (int) ($metric['call_count'] ?? 0));
                $host->setAttribute('dashboard_call_minutes', (int) ($metric['billable_minutes'] ?? 0));
                $host->setAttribute('dashboard_call_gross', (int) ($metric['call_gross'] ?? 0));
                $host->setAttribute('dashboard_live_gift_gross', (int) ($metric['gift_gross'] ?? 0));
                $host->setAttribute('dashboard_video_call_minutes', (int) ($metric['video_call_minutes'] ?? 0));
                $host->setAttribute('dashboard_video_call_gross', (int) ($metric['video_call_gross'] ?? 0));
                $host->setAttribute('dashboard_audio_call_minutes', (int) ($metric['audio_call_minutes'] ?? 0));
                $host->setAttribute('dashboard_audio_call_gross', (int) ($metric['audio_call_gross'] ?? 0));
                $host->setAttribute('dashboard_video_room_minutes', (int) ($metric['video_room_minutes'] ?? 0));
                $host->setAttribute('dashboard_audio_room_minutes', (int) ($metric['audio_room_minutes'] ?? 0));
                $host->setAttribute('dashboard_video_gift_gross', (int) ($metric['video_gift_gross'] ?? 0));
                $host->setAttribute('dashboard_audio_gift_gross', (int) ($metric['audio_gift_gross'] ?? 0));
                $host->setAttribute('dashboard_pk_gross', (int) ($metric['pk_gross'] ?? 0));
                $host->setAttribute('dashboard_pk_event_count', (int) ($metric['pk_event_count'] ?? 0));
                $host->setAttribute('dashboard_total_gross', (int) ($metric['total_coins'] ?? 0));
                $host->setAttribute('dashboard_host_payout_percentage', 0);
                $host->setAttribute('dashboard_agency_payout_percentage', 0);
                $host->setAttribute('dashboard_host_payout', (int) ($metric['total_coins'] ?? 0));
                $host->setAttribute('dashboard_agency_payout', (int) ($metric['agency_commission_coins'] ?? 0));
                $host->setAttribute('dashboard_total_payout', (int) ($metric['total_coins_to_be_paid'] ?? 0));
                $host->setAttribute('dashboard_agency_earnings', (int) ($metric['agency_commission_coins'] ?? 0));
                $host->setAttribute('dashboard_live_agency_earnings', (int) ($metric['agency_commission_coins'] ?? 0));

                return $host;
            })
        );

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
                'live_gift_gross' => (int) ($settlementTotals['video_gift_gross'] + $settlementTotals['audio_gift_gross']),
                'video_room_minutes' => (int) ($settlementTotals['video_room_minutes'] ?? 0),
                'audio_room_minutes' => (int) ($settlementTotals['audio_room_minutes'] ?? 0),
                'video_gift_gross' => (int) ($settlementTotals['video_gift_gross'] ?? 0),
                'audio_gift_gross' => (int) ($settlementTotals['audio_gift_gross'] ?? 0),
                'pk_event_count' => (int) ($settlementTotals['pk_event_count'] ?? 0),
                'pk_gross' => (int) ($settlementTotals['pk_gross'] ?? 0),
                'pk_host_earnings' => 0,
                'pk_agency_earnings' => 0,
                'video_call_minutes' => (int) ($settlementTotals['video_call_minutes'] ?? 0),
                'audio_call_minutes' => (int) ($settlementTotals['audio_call_minutes'] ?? 0),
                'video_call_gross' => (int) ($settlementTotals['video_call_gross'] ?? 0),
                'audio_call_gross' => (int) ($settlementTotals['audio_call_gross'] ?? 0),
                'gross_total' => (int) ($settlementTotals['total_coins'] ?? 0),
                'host_payout_percentage' => (float) $agency->hosts()->avg('payout_percentage'),
                'agency_payout_percentage' => (float) $agency->payout_percentage,
                'host_payout_total' => (int) ($settlementTotals['total_coins'] ?? 0),
                'agency_payout_total' => (int) ($settlementTotals['agency_commission_coins'] ?? 0),
                'combined_payout_total' => (int) ($settlementTotals['total_coins_to_be_paid'] ?? 0),
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
                ->map(function (Host $host) use ($hostMetrics) {
                    $metric = $hostMetrics->get($host->id, []);

                    return [
                        'host' => $host,
                        'gross' => (int) ($metric['total_coins'] ?? 0),
                        'agency_earnings' => (int) ($metric['agency_commission_coins'] ?? 0),
                        'call_count' => (int) ($metric['call_count'] ?? 0),
                        'pk_gross' => (int) ($metric['pk_gross'] ?? 0),
                        'pk_event_count' => (int) ($metric['pk_event_count'] ?? 0),
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
        $anchor = $this->parseDate($filters['from'] ?? null)
            ?? $this->parseDate($filters['to'] ?? null)
            ?? $today->copy();

        if ($period === 'daily') {
            $from = $anchor->copy()->startOfDay();
            $to = $anchor->copy()->endOfDay();
        } elseif ($period === 'weekly') {
            $from = $anchor->copy()->startOfWeek(Carbon::MONDAY);
            $to = $anchor->copy()->endOfWeek(Carbon::SUNDAY);
        } else {
            $period = 'custom';
            $from = $this->parseDate($filters['from'] ?? null)?->startOfDay()
                ?? $this->parseDate($filters['to'] ?? null)?->startOfDay()
                ?? $today->copy()->startOfWeek(Carbon::MONDAY);
            $to = $this->parseDate($filters['to'] ?? null)?->endOfDay()
                ?? $this->parseDate($filters['from'] ?? null)?->endOfDay()
                ?? $today->copy()->endOfDay();
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

        $liveRoomsBase = LiveRoom::query()->where('host_id', $host->id);
        $this->applyLiveRoomWindow($liveRoomsBase, $from, $to);

        $settlement = $this->settlementService->aggregateAgencySettlementMetrics($agency, $from, $to, $host->id);
        $metric = $settlement['metrics']->get($host->id, []);

        $followerCount = method_exists($host, 'followers')
            ? $host->followers()->count()
            : 0;

        return [
            'summary' => [
                'call_count' => (int) ($metric['call_count'] ?? 0),
                'completed_calls' => (int) ($metric['completed_call_count'] ?? 0),
                'active_calls' => (int) (clone $callsBase)->where('status', 'active')->count(),
                'total_minutes' => (int) ($metric['billable_minutes'] ?? 0),
                'call_gross' => (int) ($metric['call_gross'] ?? 0),
                'video_call_minutes' => (int) ($metric['video_call_minutes'] ?? 0),
                'video_call_gross' => (int) ($metric['video_call_gross'] ?? 0),
                'audio_call_minutes' => (int) ($metric['audio_call_minutes'] ?? 0),
                'audio_call_gross' => (int) ($metric['audio_call_gross'] ?? 0),
                'live_rooms' => (int) ($metric['live_room_count'] ?? 0),
                'live_rooms_active' => (int) (clone $liveRoomsBase)->where('status', 'live')->count(),
                'video_room_minutes' => (int) ($metric['video_room_minutes'] ?? 0),
                'audio_room_minutes' => (int) ($metric['audio_room_minutes'] ?? 0),
                'live_gift_gross' => (int) ($metric['gift_gross'] ?? 0),
                'video_gift_gross' => (int) ($metric['video_gift_gross'] ?? 0),
                'audio_gift_gross' => (int) ($metric['audio_gift_gross'] ?? 0),
                'pk_event_count' => (int) ($metric['pk_event_count'] ?? 0),
                'pk_gross' => (int) ($metric['pk_gross'] ?? 0),
                'pk_host_earnings' => 0,
                'pk_agency_earnings' => 0,
                'gross_total' => (int) ($metric['total_coins'] ?? 0),
                'host_payout_percentage' => 0,
                'agency_payout_percentage' => 0,
                'host_payout' => (int) ($metric['total_coins'] ?? 0),
                'agency_payout' => (int) ($metric['agency_commission_coins'] ?? 0),
                'total_payout' => (int) ($metric['total_coins_to_be_paid'] ?? 0),
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
