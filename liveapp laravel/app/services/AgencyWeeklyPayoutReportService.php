<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\AgencyPayoutReport;
use App\Models\AgencyPayoutReportItem;
use App\Models\CallEarningLedger;
use App\Models\Host;
use App\Models\LiveRoomGiftEarningLedger;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AgencyWeeklyPayoutReportService
{
    public function resolvePeriod(?string $start = null, ?string $end = null): array
    {
        $tz = config('app.timezone');

        if ($start || $end) {
            if (!$start || !$end) {
                throw new InvalidArgumentException('Both start and end dates are required together.');
            }

            $periodStart = Carbon::parse($start, $tz)->startOfDay();
            $periodEnd = Carbon::parse($end, $tz)->endOfDay();
        } else {
            $weekStartsAt = $this->carbonWeekDay(config('agency_payouts.week_starts_at', 'monday'));
            $periodStart = now($tz)->startOfWeek($weekStartsAt)->subWeek()->startOfDay();
            $periodEnd = $periodStart->copy()->addDays(6)->endOfDay();
        }

        if ($periodEnd->lt($periodStart)) {
            throw new InvalidArgumentException('End date must be on or after start date.');
        }

        return [$periodStart, $periodEnd];
    }

    public function generate(
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        ?int $agencyId = null,
        bool $force = false,
        ?User $actor = null,
    ): array {
        $agencies = Agency::query()
            ->when($agencyId, fn ($query) => $query->whereKey($agencyId))
            ->with(['owner', 'hosts.user'])
            ->orderBy('name')
            ->get();

        $reports = [];

        foreach ($agencies as $agency) {
            $report = $this->generateForAgency(
                agency: $agency,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
                force: $force,
                actor: $actor,
            );

            if ($report) {
                $reports[] = $report;
            }
        }

        return [
            'period_start' => $periodStart->copy(),
            'period_end' => $periodEnd->copy(),
            'agency_count' => $agencies->count(),
            'generated_count' => count($reports),
            'reports' => $reports,
        ];
    }

    public function generateForAgency(
        Agency $agency,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        bool $force = false,
        ?User $actor = null,
    ): ?AgencyPayoutReport {
        return DB::transaction(function () use ($agency, $periodStart, $periodEnd, $force, $actor) {
            $existing = AgencyPayoutReport::query()
                ->where('agency_id', $agency->id)
                ->where('period_start', $periodStart)
                ->where('period_end', $periodEnd)
                ->lockForUpdate()
                ->first();

            if ($existing && !$force) {
                return $existing->load(['agency.owner', 'items.host.user']);
            }

            if ($existing && $existing->paid_at) {
                throw new InvalidArgumentException('Paid payout reports cannot be regenerated.');
            }

            if ($existing) {
                $existing->items()->delete();
                $existing->delete();
            }

            $callRows = CallEarningLedger::query()
                ->join('call_sessions', 'call_sessions.id', '=', 'call_earning_ledgers.call_session_id')
                ->selectRaw("
                    call_earning_ledgers.host_id as host_id,
                    COUNT(*) as call_count,
                    COUNT(*) as completed_call_count,
                    SUM(call_earning_ledgers.billable_minutes) as billable_minutes,
                    SUM(call_earning_ledgers.total_coins) as call_gross,
                    SUM(CASE WHEN call_sessions.type = 'video' THEN call_earning_ledgers.billable_minutes ELSE 0 END) as video_call_minutes,
                    SUM(CASE WHEN call_sessions.type = 'video' THEN call_earning_ledgers.total_coins ELSE 0 END) as video_call_gross,
                    SUM(CASE WHEN call_sessions.type = 'audio' THEN call_earning_ledgers.billable_minutes ELSE 0 END) as audio_call_minutes,
                    SUM(CASE WHEN call_sessions.type = 'audio' THEN call_earning_ledgers.total_coins ELSE 0 END) as audio_call_gross,
                    SUM(call_earning_ledgers.host_earning) as ledger_host_share,
                    SUM(call_earning_ledgers.agency_earning) as ledger_agency_share,
                    SUM(call_earning_ledgers.platform_earning) as platform_share
                ")
                ->where(function ($query) use ($agency) {
                    $query->where('call_earning_ledgers.agency_id', $agency->id)
                        ->orWhere(function ($fallback) use ($agency) {
                            $fallback->whereNull('call_earning_ledgers.agency_id')
                                ->where('call_sessions.agency_id', $agency->id);
                        });
                })
                ->where('call_sessions.status', 'ended')
                ->where('call_earning_ledgers.total_coins', '>', 0)
                ->whereBetween('call_earning_ledgers.created_at', [$periodStart, $periodEnd])
                ->groupBy('call_earning_ledgers.host_id')
                ->get()
                ->keyBy('host_id');

            $giftRows = LiveRoomGiftEarningLedger::query()
                ->join('hosts', 'hosts.id', '=', 'live_room_gift_earning_ledgers.host_id')
                ->join('live_room_gifts', 'live_room_gifts.id', '=', 'live_room_gift_earning_ledgers.live_room_gift_id')
                ->join('live_rooms', 'live_rooms.id', '=', 'live_room_gift_earning_ledgers.live_room_id')
                ->leftJoin('live_room_pk_events', function ($join) {
                    $join->on('live_room_pk_events.wallet_transaction_id', '=', 'live_room_gifts.transaction_id')
                        ->where('live_room_pk_events.event_type', '=', 'gift');
                })
                ->selectRaw("
                    live_room_gift_earning_ledgers.host_id as host_id,
                    SUM(CASE WHEN live_room_pk_events.id IS NULL THEN 1 ELSE 0 END) as gift_events,
                    COUNT(DISTINCT CASE WHEN live_room_pk_events.id IS NULL THEN live_room_gift_earning_ledgers.live_room_id END) as live_room_count,
                    COUNT(DISTINCT CASE WHEN live_room_pk_events.id IS NULL THEN live_room_gift_earning_ledgers.sender_user_id END) as unique_gifters,
                    SUM(CASE WHEN live_room_pk_events.id IS NULL THEN COALESCE(live_room_gifts.quantity, 0) ELSE 0 END) as gift_quantity,
                    SUM(CASE WHEN live_room_pk_events.id IS NULL THEN live_room_gift_earning_ledgers.total_coins ELSE 0 END) as gift_gross,
                    SUM(CASE WHEN live_room_pk_events.id IS NULL AND live_rooms.room_type = 'video' THEN live_room_gift_earning_ledgers.total_coins ELSE 0 END) as video_gift_gross,
                    SUM(CASE WHEN live_room_pk_events.id IS NULL AND live_rooms.room_type = 'audio' THEN live_room_gift_earning_ledgers.total_coins ELSE 0 END) as audio_gift_gross,
                    SUM(CASE WHEN live_room_pk_events.id IS NULL THEN live_room_gift_earning_ledgers.host_payout_coins ELSE 0 END) as ledger_host_share,
                    SUM(CASE WHEN live_room_pk_events.id IS NULL THEN live_room_gift_earning_ledgers.agency_payout_coins ELSE 0 END) as ledger_agency_share,
                    SUM(CASE WHEN live_room_pk_events.id IS NULL THEN live_room_gift_earning_ledgers.platform_revenue_coins ELSE 0 END) as platform_share
                ")
                ->where(function ($query) use ($agency) {
                    $query->where('live_room_gift_earning_ledgers.agency_id', $agency->id)
                        ->orWhere(function ($fallback) use ($agency) {
                            $fallback->whereNull('live_room_gift_earning_ledgers.agency_id')
                                ->where('hosts.agency_id', $agency->id);
                        });
                })
                ->whereBetween('live_room_gift_earning_ledgers.created_at', [$periodStart, $periodEnd])
                ->groupBy('live_room_gift_earning_ledgers.host_id')
                ->get()
                ->keyBy('host_id');

            $pkRows = LiveRoomGiftEarningLedger::query()
                ->join('hosts', 'hosts.id', '=', 'live_room_gift_earning_ledgers.host_id')
                ->join('live_room_gifts', 'live_room_gifts.id', '=', 'live_room_gift_earning_ledgers.live_room_gift_id')
                ->join('live_room_pk_events', function ($join) {
                    $join->on('live_room_pk_events.wallet_transaction_id', '=', 'live_room_gifts.transaction_id')
                        ->where('live_room_pk_events.event_type', '=', 'gift');
                })
                ->selectRaw('live_room_gift_earning_ledgers.host_id as host_id, COUNT(live_room_pk_events.id) as pk_event_count, SUM(live_room_gift_earning_ledgers.total_coins) as pk_gross')
                ->where(function ($query) use ($agency) {
                    $query->where('live_room_gift_earning_ledgers.agency_id', $agency->id)
                        ->orWhere(function ($fallback) use ($agency) {
                            $fallback->whereNull('live_room_gift_earning_ledgers.agency_id')
                                ->where('hosts.agency_id', $agency->id);
                        });
                })
                ->whereBetween('live_room_gift_earning_ledgers.created_at', [$periodStart, $periodEnd])
                ->groupBy('live_room_gift_earning_ledgers.host_id')
                ->get()
                ->keyBy('host_id');

            $historicalHostIds = collect()
                ->merge(Host::query()->where('agency_id', $agency->id)->pluck('id'))
                ->merge($callRows->keys())
                ->merge($giftRows->keys())
                ->merge($pkRows->keys())
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            $hosts = Host::query()
                ->with('user')
                ->whereIn('id', $historicalHostIds)
                ->orderBy('id')
                ->get();

            $roomRows = DB::table('live_rooms')
                ->selectRaw("
                    host_id,
                    COUNT(*) as live_room_count,
                    SUM(CASE WHEN room_type = 'audio' THEN 1 ELSE 0 END) as audio_room_count,
                    SUM(CASE WHEN room_type = 'video' THEN 1 ELSE 0 END) as video_room_count,
                    SUM(CASE
                        WHEN room_type = 'audio' THEN GREATEST(
                            TIMESTAMPDIFF(
                                MINUTE,
                                GREATEST(started_at, ?),
                                LEAST(COALESCE(ended_at, last_activity_at, started_at), ?)
                            ),
                            0
                        )
                        ELSE 0
                    END) as audio_room_minutes,
                    SUM(CASE
                        WHEN room_type = 'video' THEN GREATEST(
                            TIMESTAMPDIFF(
                                MINUTE,
                                GREATEST(started_at, ?),
                                LEAST(COALESCE(ended_at, last_activity_at, started_at), ?)
                            ),
                            0
                        )
                        ELSE 0
                    END) as video_room_minutes
                ", [
                    $periodStart->toDateTimeString(),
                    $periodEnd->toDateTimeString(),
                    $periodStart->toDateTimeString(),
                    $periodEnd->toDateTimeString(),
                ])
                ->whereIn('host_id', $hosts->pluck('id'))
                ->whereNotNull('started_at')
                ->where('started_at', '<=', $periodEnd)
                ->whereRaw('COALESCE(ended_at, last_activity_at, started_at) >= ?', [$periodStart->toDateTimeString()])
                ->groupBy('host_id')
                ->get()
                ->keyBy('host_id');

            $generateZeroReports = (bool) config('agency_payouts.generate_zero_reports', true);

            $totals = [
                'gross_earnings' => 0,
                'platform_commission' => 0,
                'agency_commission' => 0,
                'host_share' => 0,
                'call_count' => 0,
                'billable_minutes' => 0,
                'gift_events' => 0,
                'gift_quantity' => 0,
                'live_room_count' => 0,
                'audio_room_count' => 0,
                'video_room_count' => 0,
                'audio_room_minutes' => 0,
                'video_room_minutes' => 0,
                'audio_gift_gross' => 0,
                'video_gift_gross' => 0,
                'audio_call_minutes' => 0,
                'video_call_minutes' => 0,
                'audio_call_gross' => 0,
                'video_call_gross' => 0,
                'pk_event_count' => 0,
                'total_payout' => 0,
                'video_gift_coins' => 0,
                'audio_gift_coins' => 0,
                'pk_gift_coins' => 0,
                'video_call_coins' => 0,
                'audio_call_coins' => 0,
                'bonus_coins' => 0,
                'total_coins' => 0,
                'agency_commission_coins' => 0,
                'total_coins_to_be_paid' => 0,
                'host_payout_inr' => 0.0,
                'agency_commission_inr' => 0.0,
                'total_inr' => 0.0,
            ];

            $items = [];

            foreach ($hosts as $host) {
                $call = $callRows->get($host->id);
                $gift = $giftRows->get($host->id);
                $pk = $pkRows->get($host->id);
                $rooms = $roomRows->get($host->id);

                $callGross = (int) ($call->call_gross ?? 0);
                $giftGross = (int) ($gift->gift_gross ?? 0);
                $pkGross = (int) ($pk->pk_gross ?? 0);
                $callCount = (int) ($call->call_count ?? 0);
                $completedCallCount = (int) ($call->completed_call_count ?? 0);
                $billableMinutes = (int) ($call->billable_minutes ?? 0);
                $videoCallMinutes = (int) ($call->video_call_minutes ?? 0);
                $videoCallGross = (int) ($call->video_call_gross ?? 0);
                $audioCallMinutes = (int) ($call->audio_call_minutes ?? 0);
                $audioCallGross = (int) ($call->audio_call_gross ?? 0);
                $giftEvents = (int) ($gift->gift_events ?? 0);
                $giftQuantity = (int) ($gift->gift_quantity ?? 0);
                $uniqueGifters = (int) ($gift->unique_gifters ?? 0);
                $roomCount = (int) ($rooms->live_room_count ?? $gift->live_room_count ?? 0);
                $audioRoomCount = (int) ($rooms->audio_room_count ?? 0);
                $videoRoomCount = (int) ($rooms->video_room_count ?? 0);
                $audioRoomMinutes = (int) ($rooms->audio_room_minutes ?? 0);
                $videoRoomMinutes = (int) ($rooms->video_room_minutes ?? 0);
                $videoGiftGross = (int) ($gift->video_gift_gross ?? 0);
                $audioGiftGross = (int) ($gift->audio_gift_gross ?? 0);
                $pkEventCount = (int) ($pk->pk_event_count ?? 0);
                $videoCallCoins = $videoCallGross;
                $audioCallCoins = $audioCallGross;
                $pkGiftCoins = $pkGross;
                $bonusCoins = 0;
                $agencyCommissionCoins = 0;
                $totalCoins = $this->calculateTotalCoins([
                    'video_gift_coins' => $videoGiftGross,
                    'audio_gift_coins' => $audioGiftGross,
                    'pk_gift_coins' => $pkGiftCoins,
                    'video_call_coins' => $videoCallCoins,
                    'audio_call_coins' => $audioCallCoins,
                    'bonus_coins' => $bonusCoins,
                ]);
                $totalCoinsToBePaid = $this->calculateTotalCoinsToBePaid($totalCoins, $agencyCommissionCoins);

                $items[] = [
                    'host_id' => $host->id,
                    'call_earnings' => $callGross,
                    'gift_earnings' => $giftGross,
                    'live_room_earnings' => $giftGross,
                    'pk_earnings' => $pkGiftCoins,
                    'gross_earnings' => $totalCoins,
                    'agency_commission' => $agencyCommissionCoins,
                    'host_share' => $totalCoins,
                    'final_payable' => $totalCoinsToBePaid,
                    'meta' => [
                        'call_count' => $callCount,
                        'completed_call_count' => $completedCallCount,
                        'billable_minutes' => $billableMinutes,
                        'gift_events' => $giftEvents,
                        'gift_quantity' => $giftQuantity,
                        'unique_gifters' => $uniqueGifters,
                        'live_room_count' => $roomCount,
                        'audio_room_count' => $audioRoomCount,
                        'video_room_count' => $videoRoomCount,
                        'video_room_minutes' => $videoRoomMinutes,
                        'audio_room_minutes' => $audioRoomMinutes,
                        'video_gift_coins' => $videoGiftGross,
                        'video_gift_gross' => $videoGiftGross,
                        'audio_gift_coins' => $audioGiftGross,
                        'audio_gift_gross' => $audioGiftGross,
                        'pk_gift_coins' => $pkGiftCoins,
                        'video_call_coins' => $videoCallCoins,
                        'video_call_gross' => $videoCallCoins,
                        'video_call_minutes' => $videoCallMinutes,
                        'audio_call_coins' => $audioCallCoins,
                        'audio_call_gross' => $audioCallCoins,
                        'audio_call_minutes' => $audioCallMinutes,
                        'bonus_coins' => $bonusCoins,
                        'total_coins' => $totalCoins,
                        'agency_commission_coins' => $agencyCommissionCoins,
                        'total_coins_to_be_paid' => $totalCoinsToBePaid,
                        'host_payout_inr' => 0,
                        'agency_commission_inr' => 0,
                        'total_inr' => 0,
                        'admin_note' => '',
                        'pk_event_count' => $pkEventCount,
                        'total_payout' => $totalCoinsToBePaid,
                    ],
                ];

                $totals['gross_earnings'] += $totalCoins;
                $totals['agency_commission'] += $agencyCommissionCoins;
                $totals['host_share'] += $totalCoins;
                $totals['call_count'] += $callCount;
                $totals['billable_minutes'] += $billableMinutes;
                $totals['gift_events'] += $giftEvents;
                $totals['gift_quantity'] += $giftQuantity;
                $totals['live_room_count'] += $roomCount;
                $totals['audio_room_count'] += $audioRoomCount;
                $totals['video_room_count'] += $videoRoomCount;
                $totals['audio_room_minutes'] += $audioRoomMinutes;
                $totals['video_room_minutes'] += $videoRoomMinutes;
                $totals['audio_gift_gross'] += $audioGiftGross;
                $totals['video_gift_gross'] += $videoGiftGross;
                $totals['audio_call_minutes'] += $audioCallMinutes;
                $totals['video_call_minutes'] += $videoCallMinutes;
                $totals['audio_call_gross'] += $audioCallGross;
                $totals['video_call_gross'] += $videoCallGross;
                $totals['pk_event_count'] += $pkEventCount;
                $totals['total_payout'] += $totalCoinsToBePaid;
                $totals['video_gift_coins'] += $videoGiftGross;
                $totals['audio_gift_coins'] += $audioGiftGross;
                $totals['pk_gift_coins'] += $pkGiftCoins;
                $totals['video_call_coins'] += $videoCallCoins;
                $totals['audio_call_coins'] += $audioCallCoins;
                $totals['total_coins'] += $totalCoins;
                $totals['agency_commission_coins'] += $agencyCommissionCoins;
                $totals['total_coins_to_be_paid'] += $totalCoinsToBePaid;
            }

            if (!$generateZeroReports && $totals['gross_earnings'] === 0 && $hosts->isEmpty()) {
                return null;
            }

            $report = AgencyPayoutReport::query()->create([
                'agency_id' => $agency->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'gross_earnings' => $totals['total_coins'],
                'platform_commission' => 0,
                'agency_commission' => $totals['agency_commission_coins'],
                'host_share' => $totals['total_coins'],
                'deductions' => 0,
                'final_payable' => $totals['total_coins_to_be_paid'],
                'status' => 'generated',
                'generated_at' => now(config('app.timezone')),
                'admin_remarks' => $force ? 'Regenerated from console/admin flow.' : null,
                'meta' => [
                    'timezone' => config('app.timezone'),
                    'generated_via' => $actor ? 'admin' : 'system',
                    'totals' => [
                        'call_count' => $totals['call_count'],
                        'billable_minutes' => $totals['billable_minutes'],
                        'gift_events' => $totals['gift_events'],
                        'gift_quantity' => $totals['gift_quantity'],
                        'live_room_count' => $totals['live_room_count'],
                        'audio_room_count' => $totals['audio_room_count'],
                        'video_room_count' => $totals['video_room_count'],
                        'audio_room_minutes' => $totals['audio_room_minutes'],
                        'video_room_minutes' => $totals['video_room_minutes'],
                        'audio_gift_coins' => $totals['audio_gift_coins'],
                        'video_gift_coins' => $totals['video_gift_coins'],
                        'audio_gift_gross' => $totals['audio_gift_gross'],
                        'video_gift_gross' => $totals['video_gift_gross'],
                        'audio_call_minutes' => $totals['audio_call_minutes'],
                        'video_call_minutes' => $totals['video_call_minutes'],
                        'audio_call_coins' => $totals['audio_call_coins'],
                        'video_call_coins' => $totals['video_call_coins'],
                        'audio_call_gross' => $totals['audio_call_gross'],
                        'video_call_gross' => $totals['video_call_gross'],
                        'pk_gift_coins' => $totals['pk_gift_coins'],
                        'pk_event_count' => $totals['pk_event_count'],
                        'bonus_coins' => $totals['bonus_coins'],
                        'total_coins' => $totals['total_coins'],
                        'agency_commission_coins' => $totals['agency_commission_coins'],
                        'total_coins_to_be_paid' => $totals['total_coins_to_be_paid'],
                        'host_payout_inr' => $totals['host_payout_inr'],
                        'agency_commission_inr' => $totals['agency_commission_inr'],
                        'total_inr' => $totals['total_inr'],
                        'total_payout' => $totals['total_coins_to_be_paid'],
                    ],
                ],
            ]);

            foreach ($items as $item) {
                $report->items()->create($item);
            }

            if ($actor) {
                app(AdminAuditService::class)->log(
                    area: 'agency_payout_reports',
                    action: $force ? 'regenerate' : 'generate',
                    admin: $actor,
                    entity: $report,
                    after: [
                        'agency_id' => $agency->id,
                        'period_start' => $periodStart->toIso8601String(),
                        'period_end' => $periodEnd->toIso8601String(),
                        'final_payable' => $report->final_payable,
                    ],
                    meta: ['force' => $force]
                );
            }

            return $report->load(['agency.owner', 'items.host.user']);
        });
    }

    public function markPendingReview(AgencyPayoutReport $report, int $deductions = 0, ?string $remarks = null, ?User $actor = null): AgencyPayoutReport
    {
        $this->assertTransitionAllowed($report, ['generated', 'pending_review'], 'Only generated or pending review reports can be edited before approval.');

        return $this->transitionWithRecalculatedTotals($report, [
            'status' => 'pending_review',
            'deductions' => max(0, $deductions),
            'admin_remarks' => $remarks,
        ], 'review', $actor);
    }

    public function approve(AgencyPayoutReport $report, int $deductions = 0, ?string $remarks = null, ?User $actor = null): AgencyPayoutReport
    {
        $this->assertTransitionAllowed($report, ['generated', 'pending_review'], 'Only generated or pending review reports can be approved.');

        return $this->transitionWithRecalculatedTotals($report, [
            'status' => 'approved',
            'deductions' => max(0, $deductions),
            'approved_at' => now(config('app.timezone')),
            'admin_remarks' => $remarks,
        ], 'approve', $actor);
    }

    public function reject(AgencyPayoutReport $report, ?string $remarks = null, ?User $actor = null): AgencyPayoutReport
    {
        $this->assertTransitionAllowed($report, ['generated', 'pending_review'], 'Only generated or pending review reports can be rejected.');

        return $this->transition($report, [
            'status' => 'rejected',
            'admin_remarks' => $remarks,
        ], 'reject', $actor);
    }

    public function updateItem(
        AgencyPayoutReport $report,
        AgencyPayoutReportItem $item,
        array $payload,
        ?User $actor = null,
    ): AgencyPayoutReport {
        return DB::transaction(function () use ($report, $item, $payload, $actor) {
            $locked = AgencyPayoutReport::query()
                ->with(['agency.owner', 'items.host.user'])
                ->lockForUpdate()
                ->findOrFail($report->id);

            if ($locked->paid_at || $locked->status === 'paid') {
                throw new InvalidArgumentException('Paid payout reports are locked.');
            }

            if ($locked->published_at) {
                throw new InvalidArgumentException('Published payout reports cannot be edited.');
            }

            $allowedStatuses = ['generated', 'pending_review', 'approved'];
            if (!in_array($locked->status, $allowedStatuses, true)) {
                throw new InvalidArgumentException('Only draft or approved reports can be edited.');
            }

            $lockedItem = AgencyPayoutReportItem::query()
                ->whereKey($item->id)
                ->where('agency_payout_report_id', $locked->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = $lockedItem->toArray();
            $normalized = $this->normalizeSettlementItemPayload($payload, $lockedItem->meta ?? []);

            $lockedItem->forceFill([
                'call_earnings' => $normalized['call_earnings'],
                'gift_earnings' => $normalized['gift_earnings'],
                'live_room_earnings' => $normalized['gift_earnings'],
                'pk_earnings' => $normalized['pk_earnings'],
                'gross_earnings' => $normalized['gross_earnings'],
                'agency_commission' => $normalized['agency_commission'],
                'host_share' => $normalized['host_share'],
                'final_payable' => $normalized['final_payable'],
                'meta' => $normalized['meta'],
            ])->save();

            $reportStatus = $locked->status === 'approved'
                ? ['status' => 'pending_review', 'approved_at' => null]
                : [];

            $this->syncReportTotals($locked, $reportStatus);

            if ($actor) {
                app(AdminAuditService::class)->log(
                    area: 'agency_payout_reports',
                    action: 'update_item',
                    admin: $actor,
                    targetUser: $locked->agency?->owner,
                    entity: $locked,
                    before: $before,
                    after: $lockedItem->fresh()->toArray(),
                    meta: [
                        'report_id' => $locked->id,
                        'agency_payout_report_item_id' => $lockedItem->id,
                        'host_id' => $lockedItem->host_id,
                    ],
                    reason: (string) ($payload['admin_note'] ?? '')
                );
            }

            return $locked->fresh(['agency.owner', 'items.host.user', 'publishedByAdmin']);
        });
    }

    public function publish(AgencyPayoutReport $report, ?string $remarks = null, ?User $actor = null): AgencyPayoutReport
    {
        return DB::transaction(function () use ($report, $remarks, $actor) {
            $locked = AgencyPayoutReport::query()
                ->with('agency.owner')
                ->lockForUpdate()
                ->findOrFail($report->id);

            if ($locked->paid_at || $locked->status === 'paid') {
                throw new InvalidArgumentException('Paid payout reports are locked.');
            }

            if ($locked->published_at) {
                throw new InvalidArgumentException('This payout report is already published.');
            }

            if ($locked->status !== 'approved') {
                throw new InvalidArgumentException('Only approved payout reports can be published to agencies.');
            }

            $before = $locked->toArray();
            $locked->forceFill([
                'published_at' => now(config('app.timezone')),
                'published_by_admin_user_id' => $actor?->id,
                'admin_remarks' => $remarks ?: $locked->admin_remarks,
            ])->save();

            if ($actor) {
                app(AdminAuditService::class)->log(
                    area: 'agency_payout_reports',
                    action: 'publish',
                    admin: $actor,
                    targetUser: $locked->agency?->owner,
                    entity: $locked,
                    before: $before,
                    after: $locked->fresh()->toArray(),
                    reason: $remarks
                );
            }

            return $locked->fresh(['agency.owner', 'items.host.user', 'publishedByAdmin']);
        });
    }

    public function markPaid(AgencyPayoutReport $report, ?string $remarks = null, ?User $actor = null): AgencyPayoutReport
    {
        return DB::transaction(function () use ($report, $remarks, $actor) {
            $report = AgencyPayoutReport::query()
                ->with(['agency.owner'])
                ->lockForUpdate()
                ->findOrFail($report->id);

            if ($report->paid_at || $report->status === 'paid') {
                throw new InvalidArgumentException('This payout report is already marked as paid.');
            }

            if ($report->status !== 'approved') {
                throw new InvalidArgumentException('Only approved payout reports can be marked as paid.');
            }

            if (!$report->published_at) {
                throw new InvalidArgumentException('Publish the payout report to the agency before marking it paid.');
            }

            $report->forceFill([
                'status' => 'paid',
                'paid_at' => now(config('app.timezone')),
                'admin_remarks' => $remarks ?: $report->admin_remarks,
            ])->save();

            $owner = $report->agency?->owner;

            if ($actor) {
                app(AdminAuditService::class)->log(
                    area: 'agency_payout_reports',
                    action: 'paid',
                    admin: $actor,
                    targetUser: $owner,
                    entity: $report,
                    after: $report->fresh()->toArray(),
                    reason: $remarks
                );
            }

            return $report->fresh(['agency.owner', 'items.host.user', 'publishedByAdmin']);
        });
    }

    public function deleteReport(AgencyPayoutReport $report, ?string $remarks = null, ?User $actor = null): void
    {
        DB::transaction(function () use ($report, $remarks, $actor) {
            $locked = AgencyPayoutReport::query()
                ->with(['agency.owner', 'items.host.user', 'publishedByAdmin'])
                ->lockForUpdate()
                ->findOrFail($report->id);

            $before = $locked->toArray();
            $owner = $locked->agency?->owner;
            $locked->delete();

            if ($actor) {
                app(AdminAuditService::class)->log(
                    area: 'agency_payout_reports',
                    action: 'delete',
                    admin: $actor,
                    targetUser: $owner,
                    entity: $report,
                    before: $before,
                    after: null,
                    reason: $remarks,
                    meta: [
                        'deleted_report_id' => $report->id,
                    ]
                );
            }
        });
    }

    public function exportRows(AgencyPayoutReport $report): array
    {
        $report->loadMissing(['agency.owner', 'items.host.user']);

        return $report->items->map(function (AgencyPayoutReportItem $item) use ($report) {
            return [
                'report_id' => $report->id,
                'agency' => $report->agency?->name,
                'period_start' => optional($report->period_start)->toDateTimeString(),
                'period_end' => optional($report->period_end)->toDateTimeString(),
                'host_id' => $item->host_id,
                'host_name' => $item->host?->user?->name ?? $item->host?->stage_name,
                'video_room_minutes' => $item->video_room_minutes,
                'audio_room_minutes' => $item->audio_room_minutes,
                'video_gift_coins' => $item->video_gift_coins,
                'audio_gift_coins' => $item->audio_gift_coins,
                'pk_gift_coins' => $item->pk_gift_coins,
                'video_call_coins' => $item->video_call_coins,
                'video_call_minutes' => $item->video_call_minutes,
                'audio_call_coins' => $item->audio_call_coins,
                'audio_call_minutes' => $item->audio_call_minutes,
                'bonus_coins' => $item->bonus_coins,
                'total_coins' => $item->total_coins,
                'agency_commission_coins' => $item->agency_commission_coins,
                'total_coins_to_be_paid' => $item->total_coins_to_be_paid,
                'host_payout_inr' => $item->host_payout_inr,
                'agency_commission_inr' => $item->agency_commission_inr,
                'total_inr' => $item->total_inr,
                'admin_note' => $item->admin_note,
                'report_status' => $report->status,
                'published_at' => optional($report->published_at)->toDateTimeString(),
            ];
        })->all();
    }

    private function transitionWithRecalculatedTotals(AgencyPayoutReport $report, array $changes, string $action, ?User $actor = null): AgencyPayoutReport
    {
        return DB::transaction(function () use ($report, $changes, $action, $actor) {
            $locked = AgencyPayoutReport::query()
                ->with(['agency.owner', 'items'])
                ->lockForUpdate()
                ->findOrFail($report->id);

            if ($locked->paid_at || $locked->status === 'paid') {
                throw new InvalidArgumentException('Paid payout reports are locked.');
            }

            if ($locked->published_at && in_array($action, ['review', 'approve'], true)) {
                throw new InvalidArgumentException('Published payout reports are locked.');
            }

            $before = $locked->toArray();
            $this->syncReportTotals($locked, $changes);

            if ($actor) {
                app(AdminAuditService::class)->log(
                    area: 'agency_payout_reports',
                    action: $action,
                    admin: $actor,
                    targetUser: $locked->agency?->owner,
                    entity: $locked,
                    before: $before,
                    after: $locked->fresh()->toArray(),
                    reason: $changes['admin_remarks'] ?? null
                );
            }

            return $locked->fresh(['agency.owner', 'items.host.user', 'publishedByAdmin']);
        });
    }

    private function transition(AgencyPayoutReport $report, array $changes, string $action, ?User $actor = null): AgencyPayoutReport
    {
        return DB::transaction(function () use ($report, $changes, $action, $actor) {
            $locked = AgencyPayoutReport::query()
                ->with('agency.owner')
                ->lockForUpdate()
                ->findOrFail($report->id);

            if ($locked->paid_at || $locked->status === 'paid') {
                throw new InvalidArgumentException('Paid payout reports are locked.');
            }

            $before = $locked->toArray();
            $locked->forceFill($changes)->save();

            if ($actor) {
                app(AdminAuditService::class)->log(
                    area: 'agency_payout_reports',
                    action: $action,
                    admin: $actor,
                    targetUser: $locked->agency?->owner,
                    entity: $locked,
                    before: $before,
                    after: $locked->fresh()->toArray(),
                    reason: $changes['admin_remarks'] ?? null
                );
            }

            return $locked->fresh(['agency.owner', 'items.host.user']);
        });
    }

    private function syncReportTotals(AgencyPayoutReport $report, array $changes = []): void
    {
        $report->unsetRelation('items');
        $report->load('items');

        $deductions = array_key_exists('deductions', $changes)
            ? max(0, (int) $changes['deductions'])
            : max(0, (int) $report->deductions);
        $meta = $report->meta ?? [];
        $meta['totals']['call_count'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => (int) data_get($item->meta, 'call_count', 0));
        $meta['totals']['billable_minutes'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => (int) data_get($item->meta, 'billable_minutes', 0));
        $meta['totals']['gift_events'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => (int) data_get($item->meta, 'gift_events', 0));
        $meta['totals']['gift_quantity'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => (int) data_get($item->meta, 'gift_quantity', 0));
        $meta['totals']['live_room_count'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => (int) data_get($item->meta, 'live_room_count', 0));
        $meta['totals']['audio_room_count'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => (int) data_get($item->meta, 'audio_room_count', 0));
        $meta['totals']['video_room_count'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => (int) data_get($item->meta, 'video_room_count', 0));
        $meta['totals']['audio_room_minutes'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => (int) data_get($item->meta, 'audio_room_minutes', 0));
        $meta['totals']['video_room_minutes'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => (int) data_get($item->meta, 'video_room_minutes', 0));
        $meta['totals']['audio_gift_gross'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => $item->audio_gift_coins);
        $meta['totals']['video_gift_gross'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => $item->video_gift_coins);
        $meta['totals']['audio_gift_coins'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => $item->audio_gift_coins);
        $meta['totals']['video_gift_coins'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => $item->video_gift_coins);
        $meta['totals']['audio_call_minutes'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => (int) data_get($item->meta, 'audio_call_minutes', 0));
        $meta['totals']['video_call_minutes'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => (int) data_get($item->meta, 'video_call_minutes', 0));
        $meta['totals']['audio_call_gross'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => $item->audio_call_coins);
        $meta['totals']['video_call_gross'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => $item->video_call_coins);
        $meta['totals']['audio_call_coins'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => $item->audio_call_coins);
        $meta['totals']['video_call_coins'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => $item->video_call_coins);
        $meta['totals']['pk_event_count'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => (int) data_get($item->meta, 'pk_event_count', 0));
        $meta['totals']['pk_gift_coins'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => $item->pk_gift_coins);
        $meta['totals']['bonus_coins'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => $item->bonus_coins);
        $meta['totals']['total_coins'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => $item->total_coins);
        $meta['totals']['agency_commission_coins'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => $item->agency_commission_coins);
        $meta['totals']['total_coins_to_be_paid'] = (int) $report->items->sum(fn (AgencyPayoutReportItem $item) => $item->total_coins_to_be_paid);
        $meta['totals']['host_payout_inr'] = round((float) $report->items->sum(fn (AgencyPayoutReportItem $item) => $item->host_payout_inr), 2);
        $meta['totals']['agency_commission_inr'] = round((float) $report->items->sum(fn (AgencyPayoutReportItem $item) => $item->agency_commission_inr), 2);
        $meta['totals']['total_inr'] = round((float) ($meta['totals']['host_payout_inr'] + $meta['totals']['agency_commission_inr']), 2);
        $meta['totals']['total_payout'] = (int) $meta['totals']['total_coins_to_be_paid'];

        $payload = array_merge($changes, [
            'gross_earnings' => (int) $meta['totals']['total_coins'],
            'platform_commission' => 0,
            'agency_commission' => (int) $meta['totals']['agency_commission_coins'],
            'host_share' => (int) $meta['totals']['total_coins'],
            'deductions' => $deductions,
            'final_payable' => max(0, (int) $meta['totals']['total_coins_to_be_paid'] - $deductions),
            'meta' => $meta,
        ]);

        $report->forceFill($payload)->save();
    }

    private function normalizeSettlementItemPayload(array $payload, array $existingMeta = []): array
    {
        $videoRoomMinutes = max(0, (int) ($payload['video_room_minutes'] ?? data_get($existingMeta, 'video_room_minutes', 0)));
        $audioRoomMinutes = max(0, (int) ($payload['audio_room_minutes'] ?? data_get($existingMeta, 'audio_room_minutes', 0)));
        $videoGiftCoins = max(0, (int) ($payload['video_gift_coins'] ?? data_get($existingMeta, 'video_gift_coins', data_get($existingMeta, 'video_gift_gross', 0))));
        $audioGiftCoins = max(0, (int) ($payload['audio_gift_coins'] ?? data_get($existingMeta, 'audio_gift_coins', data_get($existingMeta, 'audio_gift_gross', 0))));
        $pkGiftCoins = max(0, (int) ($payload['pk_gift_coins'] ?? data_get($existingMeta, 'pk_gift_coins', 0)));
        $videoCallCoins = max(0, (int) ($payload['video_call_coins'] ?? data_get($existingMeta, 'video_call_coins', data_get($existingMeta, 'video_call_gross', 0))));
        $videoCallMinutes = max(0, (int) ($payload['video_call_minutes'] ?? data_get($existingMeta, 'video_call_minutes', 0)));
        $audioCallCoins = max(0, (int) ($payload['audio_call_coins'] ?? data_get($existingMeta, 'audio_call_coins', data_get($existingMeta, 'audio_call_gross', 0))));
        $audioCallMinutes = max(0, (int) ($payload['audio_call_minutes'] ?? data_get($existingMeta, 'audio_call_minutes', 0)));
        $bonusCoins = max(0, (int) ($payload['bonus_coins'] ?? data_get($existingMeta, 'bonus_coins', 0)));
        $agencyCommissionCoins = max(0, (int) ($payload['agency_commission_coins'] ?? data_get($existingMeta, 'agency_commission_coins', 0)));
        $hostPayoutInr = round(max(0, (float) ($payload['host_payout_inr'] ?? data_get($existingMeta, 'host_payout_inr', 0))), 2);
        $agencyCommissionInr = round(max(0, (float) ($payload['agency_commission_inr'] ?? data_get($existingMeta, 'agency_commission_inr', 0))), 2);
        $totalCoins = $this->calculateTotalCoins([
            'video_gift_coins' => $videoGiftCoins,
            'audio_gift_coins' => $audioGiftCoins,
            'pk_gift_coins' => $pkGiftCoins,
            'video_call_coins' => $videoCallCoins,
            'audio_call_coins' => $audioCallCoins,
            'bonus_coins' => $bonusCoins,
        ]);
        $totalCoinsToBePaid = $this->calculateTotalCoinsToBePaid($totalCoins, $agencyCommissionCoins);
        $totalInr = round($hostPayoutInr + $agencyCommissionInr, 2);

        return [
            'call_earnings' => $videoCallCoins + $audioCallCoins,
            'gift_earnings' => $videoGiftCoins + $audioGiftCoins,
            'pk_earnings' => $pkGiftCoins,
            'gross_earnings' => $totalCoins,
            'agency_commission' => $agencyCommissionCoins,
            'host_share' => $totalCoins,
            'final_payable' => $totalCoinsToBePaid,
            'meta' => array_merge($existingMeta, [
                'video_room_minutes' => $videoRoomMinutes,
                'audio_room_minutes' => $audioRoomMinutes,
                'video_gift_coins' => $videoGiftCoins,
                'video_gift_gross' => $videoGiftCoins,
                'audio_gift_coins' => $audioGiftCoins,
                'audio_gift_gross' => $audioGiftCoins,
                'pk_gift_coins' => $pkGiftCoins,
                'video_call_coins' => $videoCallCoins,
                'video_call_gross' => $videoCallCoins,
                'video_call_minutes' => $videoCallMinutes,
                'audio_call_coins' => $audioCallCoins,
                'audio_call_gross' => $audioCallCoins,
                'audio_call_minutes' => $audioCallMinutes,
                'bonus_coins' => $bonusCoins,
                'total_coins' => $totalCoins,
                'agency_commission_coins' => $agencyCommissionCoins,
                'total_coins_to_be_paid' => $totalCoinsToBePaid,
                'host_payout_inr' => $hostPayoutInr,
                'agency_commission_inr' => $agencyCommissionInr,
                'total_inr' => $totalInr,
                'admin_note' => trim((string) ($payload['admin_note'] ?? data_get($existingMeta, 'admin_note', ''))),
            ]),
        ];
    }

    private function calculateTotalCoins(array $values): int
    {
        return max(
            0,
            (int) ($values['video_gift_coins'] ?? 0)
            + (int) ($values['audio_gift_coins'] ?? 0)
            + (int) ($values['pk_gift_coins'] ?? 0)
            + (int) ($values['video_call_coins'] ?? 0)
            + (int) ($values['audio_call_coins'] ?? 0)
            + (int) ($values['bonus_coins'] ?? 0)
        );
    }

    private function calculateTotalCoinsToBePaid(int $totalCoins, int $agencyCommissionCoins): int
    {
        return max(0, $totalCoins + $agencyCommissionCoins);
    }

    private function carbonWeekDay(string $name): int
    {
        return match (strtolower($name)) {
            'sunday' => Carbon::SUNDAY,
            'monday' => Carbon::MONDAY,
            'tuesday' => Carbon::TUESDAY,
            'wednesday' => Carbon::WEDNESDAY,
            'thursday' => Carbon::THURSDAY,
            'friday' => Carbon::FRIDAY,
            'saturday' => Carbon::SATURDAY,
            default => Carbon::MONDAY,
        };
    }

    private function assertTransitionAllowed(AgencyPayoutReport $report, array $allowedStatuses, string $message): void
    {
        $status = (string) AgencyPayoutReport::query()
            ->whereKey($report->id)
            ->value('status');

        if (!in_array($status, $allowedStatuses, true)) {
            throw new InvalidArgumentException($message);
        }
    }
}
