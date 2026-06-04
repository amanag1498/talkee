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

            $hosts = Host::query()
                ->with('user')
                ->where('agency_id', $agency->id)
                ->orderBy('id')
                ->get();

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
                ->where('call_earning_ledgers.agency_id', $agency->id)
                ->whereBetween('call_earning_ledgers.created_at', [$periodStart, $periodEnd])
                ->groupBy('call_earning_ledgers.host_id')
                ->get()
                ->keyBy('host_id');

            $giftRows = LiveRoomGiftEarningLedger::query()
                ->join('live_room_gifts', 'live_room_gifts.id', '=', 'live_room_gift_earning_ledgers.live_room_gift_id')
                ->join('live_rooms', 'live_rooms.id', '=', 'live_room_gift_earning_ledgers.live_room_id')
                ->selectRaw("
                    live_room_gift_earning_ledgers.host_id as host_id,
                    COUNT(live_room_gift_earning_ledgers.id) as gift_events,
                    COUNT(DISTINCT live_room_gift_earning_ledgers.live_room_id) as live_room_count,
                    COUNT(DISTINCT live_room_gift_earning_ledgers.sender_user_id) as unique_gifters,
                    SUM(COALESCE(live_room_gifts.quantity, 0)) as gift_quantity,
                    SUM(live_room_gift_earning_ledgers.total_coins) as gift_gross,
                    SUM(CASE WHEN live_rooms.room_type = 'video' THEN live_room_gift_earning_ledgers.total_coins ELSE 0 END) as video_gift_gross,
                    SUM(CASE WHEN live_rooms.room_type = 'audio' THEN live_room_gift_earning_ledgers.total_coins ELSE 0 END) as audio_gift_gross,
                    SUM(live_room_gift_earning_ledgers.host_payout_coins) as ledger_host_share,
                    SUM(live_room_gift_earning_ledgers.agency_payout_coins) as ledger_agency_share,
                    SUM(live_room_gift_earning_ledgers.platform_revenue_coins) as platform_share
                ")
                ->where('live_room_gift_earning_ledgers.agency_id', $agency->id)
                ->whereBetween('live_room_gift_earning_ledgers.created_at', [$periodStart, $periodEnd])
                ->groupBy('live_room_gift_earning_ledgers.host_id')
                ->get()
                ->keyBy('host_id');

            $pkRows = LiveRoomGiftEarningLedger::query()
                ->join('live_room_gifts', 'live_room_gifts.id', '=', 'live_room_gift_earning_ledgers.live_room_gift_id')
                ->join('live_room_pk_events', function ($join) {
                    $join->on('live_room_pk_events.wallet_transaction_id', '=', 'live_room_gifts.transaction_id')
                        ->where('live_room_pk_events.event_type', '=', 'gift');
                })
                ->selectRaw('live_room_gift_earning_ledgers.host_id as host_id, COUNT(live_room_pk_events.id) as pk_event_count, SUM(live_room_gift_earning_ledgers.total_coins) as pk_gross')
                ->where('live_room_gift_earning_ledgers.agency_id', $agency->id)
                ->whereBetween('live_room_gift_earning_ledgers.created_at', [$periodStart, $periodEnd])
                ->groupBy('live_room_gift_earning_ledgers.host_id')
                ->get()
                ->keyBy('host_id');

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
                $hostPct = (float) ($host->payout_percentage ?? 0);
                $agencyPct = (float) ($agency->payout_percentage ?? 0);
                $platformShare = (int) ($call->platform_share ?? 0) + (int) ($gift->platform_share ?? 0);
                $gross = $callGross + $giftGross;
                $hostShare = (int) floor(($gross * $hostPct) / 100);
                $agencyShare = (int) floor(($gross * $agencyPct) / 100);
                $totalPayout = $hostShare + $agencyShare;

                $items[] = [
                    'host_id' => $host->id,
                    'call_earnings' => $callGross,
                    'gift_earnings' => $giftGross,
                    'live_room_earnings' => $giftGross,
                    'pk_earnings' => $pkGross,
                    'gross_earnings' => $gross,
                    'agency_commission' => $agencyShare,
                    'host_share' => $hostShare,
                    'final_payable' => $agencyShare,
                    'meta' => [
                        'call_count' => $callCount,
                        'completed_call_count' => $completedCallCount,
                        'billable_minutes' => $billableMinutes,
                        'video_call_minutes' => $videoCallMinutes,
                        'video_call_gross' => $videoCallGross,
                        'audio_call_minutes' => $audioCallMinutes,
                        'audio_call_gross' => $audioCallGross,
                        'gift_events' => $giftEvents,
                        'gift_quantity' => $giftQuantity,
                        'unique_gifters' => $uniqueGifters,
                        'live_room_count' => $roomCount,
                        'audio_room_count' => $audioRoomCount,
                        'video_room_count' => $videoRoomCount,
                        'audio_room_minutes' => $audioRoomMinutes,
                        'video_room_minutes' => $videoRoomMinutes,
                        'video_gift_gross' => $videoGiftGross,
                        'audio_gift_gross' => $audioGiftGross,
                        'pk_event_count' => $pkEventCount,
                        'host_payout_percentage' => $hostPct,
                        'agency_payout_percentage' => $agencyPct,
                        'host_payout' => $hostShare,
                        'agency_payout' => $agencyShare,
                        'total_payout' => $totalPayout,
                        'effective_agency_rate' => $agencyPct,
                        'effective_host_rate' => $hostPct,
                    ],
                ];

                $totals['gross_earnings'] += $gross;
                $totals['platform_commission'] += $platformShare;
                $totals['agency_commission'] += $agencyShare;
                $totals['host_share'] += $hostShare;
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
                $totals['total_payout'] += $totalPayout;
            }

            if (!$generateZeroReports && $totals['gross_earnings'] === 0 && $hosts->isEmpty()) {
                return null;
            }

            $report = AgencyPayoutReport::query()->create([
                'agency_id' => $agency->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'gross_earnings' => $totals['gross_earnings'],
                'platform_commission' => $totals['platform_commission'],
                'agency_commission' => $totals['agency_commission'],
                'host_share' => $totals['host_share'],
                'deductions' => 0,
                'final_payable' => $totals['agency_commission'],
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
                        'audio_gift_gross' => $totals['audio_gift_gross'],
                        'video_gift_gross' => $totals['video_gift_gross'],
                        'audio_call_minutes' => $totals['audio_call_minutes'],
                        'video_call_minutes' => $totals['video_call_minutes'],
                        'audio_call_gross' => $totals['audio_call_gross'],
                        'video_call_gross' => $totals['video_call_gross'],
                        'pk_event_count' => $totals['pk_event_count'],
                        'total_payout' => $totals['total_payout'],
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
        int $agencyCommission,
        int $hostShare,
        int $finalPayable,
        ?string $adminNote = null,
        ?User $actor = null,
    ): AgencyPayoutReport {
        return DB::transaction(function () use ($report, $item, $agencyCommission, $hostShare, $finalPayable, $adminNote, $actor) {
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
            $meta = $lockedItem->meta ?? [];
            $meta['agency_payout'] = max(0, $agencyCommission);
            $meta['host_payout'] = max(0, $hostShare);
            $meta['total_payout'] = max(0, $agencyCommission) + max(0, $hostShare);
            $meta['agency_payout_percentage'] = $this->percentOfGross((int) $lockedItem->gross_earnings, max(0, $agencyCommission));
            $meta['host_payout_percentage'] = $this->percentOfGross((int) $lockedItem->gross_earnings, max(0, $hostShare));
            if ($adminNote !== null) {
                $meta['admin_note'] = $adminNote;
            }

            $lockedItem->forceFill([
                'agency_commission' => max(0, $agencyCommission),
                'host_share' => max(0, $hostShare),
                'final_payable' => max(0, $finalPayable),
                'meta' => $meta,
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
                    reason: $adminNote
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
                'call_earnings' => $item->call_earnings,
                'call_count' => (int) data_get($item->meta, 'call_count', 0),
                'completed_call_count' => (int) data_get($item->meta, 'completed_call_count', 0),
                'billable_minutes' => (int) data_get($item->meta, 'billable_minutes', 0),
                'video_call_minutes' => (int) data_get($item->meta, 'video_call_minutes', 0),
                'video_call_gross' => (int) data_get($item->meta, 'video_call_gross', 0),
                'audio_call_minutes' => (int) data_get($item->meta, 'audio_call_minutes', 0),
                'audio_call_gross' => (int) data_get($item->meta, 'audio_call_gross', 0),
                'gift_earnings' => $item->gift_earnings,
                'gift_events' => (int) data_get($item->meta, 'gift_events', 0),
                'gift_quantity' => (int) data_get($item->meta, 'gift_quantity', 0),
                'unique_gifters' => (int) data_get($item->meta, 'unique_gifters', 0),
                'live_room_count' => (int) data_get($item->meta, 'live_room_count', 0),
                'audio_room_count' => (int) data_get($item->meta, 'audio_room_count', 0),
                'video_room_count' => (int) data_get($item->meta, 'video_room_count', 0),
                'audio_room_minutes' => (int) data_get($item->meta, 'audio_room_minutes', 0),
                'video_room_minutes' => (int) data_get($item->meta, 'video_room_minutes', 0),
                'video_gift_gross' => (int) data_get($item->meta, 'video_gift_gross', 0),
                'audio_gift_gross' => (int) data_get($item->meta, 'audio_gift_gross', 0),
                'pk_earnings' => $item->pk_earnings,
                'pk_event_count' => (int) data_get($item->meta, 'pk_event_count', 0),
                'gross_earnings' => $item->gross_earnings,
                'agency_commission' => $item->agency_commission,
                'agency_payout_percentage' => (float) data_get($item->meta, 'agency_payout_percentage', $item->effective_agency_rate),
                'agency_payout' => (int) data_get($item->meta, 'agency_payout', $item->agency_commission),
                'host_share' => $item->host_share,
                'host_payout_percentage' => (float) data_get($item->meta, 'host_payout_percentage', $item->effective_host_rate),
                'host_payout' => (int) data_get($item->meta, 'host_payout', $item->host_share),
                'total_payout' => (int) data_get($item->meta, 'total_payout', ((int) $item->agency_commission + (int) $item->host_share)),
                'final_payable' => $item->final_payable,
                'admin_note' => (string) data_get($item->meta, 'admin_note', ''),
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

        $agencyCommission = (int) $report->items->sum('agency_commission');
        $hostShare = (int) $report->items->sum('host_share');
        $itemFinalPayable = (int) $report->items->sum('final_payable');
        $deductions = array_key_exists('deductions', $changes)
            ? max(0, (int) $changes['deductions'])
            : max(0, (int) $report->deductions);
        $meta = $report->meta ?? [];
        $meta['totals']['total_payout'] = (int) $report->items->sum(function (AgencyPayoutReportItem $item) {
            return (int) data_get($item->meta, 'total_payout', ((int) $item->agency_commission + (int) $item->host_share));
        });

        $payload = array_merge($changes, [
            'agency_commission' => $agencyCommission,
            'host_share' => $hostShare,
            'deductions' => $deductions,
            'final_payable' => max(0, $itemFinalPayable - $deductions),
            'meta' => $meta,
        ]);

        $report->forceFill($payload)->save();
    }

    private function percentOfGross(int $gross, int $value): float
    {
        if ($gross <= 0) {
            return 0.0;
        }

        return round(($value / $gross) * 100, 2);
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
