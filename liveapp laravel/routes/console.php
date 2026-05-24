<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\User;
use App\Services\BillingReconciliationService;
use App\Services\CallSessionService;
use App\Services\HostAvailabilityService;
use App\Services\LiveRoomMaintenanceService;
use App\Services\LiveRoomStateService;
use App\Services\RechargeOrderService;
use App\Services\UserLevelService;
use App\Services\AgencyBackfillService;
use App\Services\LiveRoomPkService;
use App\Services\AgencyWeeklyPayoutReportService;
use App\Services\LeaderboardService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('queue:work-safe {connection?}', function (?string $connection = null) {
    $connection ??= config('queue.default');
    $policy = (array) config('queue.worker', []);

    $this->call('queue:work', [
        'connection' => $connection,
        '--tries' => (int) ($policy['tries'] ?? 3),
        '--timeout' => (int) ($policy['timeout'] ?? 60),
        '--backoff' => (int) ($policy['backoff'] ?? 5),
        '--sleep' => (int) ($policy['sleep'] ?? 3),
        '--max-time' => (int) ($policy['max_time'] ?? 3600),
        '--max-jobs' => (int) ($policy['max_jobs'] ?? 1000),
    ]);
})->purpose('Run queue worker with explicit retry/timeout policy');

Artisan::command('calls:timeout-missed', function (CallSessionService $service) {
    $count = $service->markMissedCalls();
    $this->info("Timed out {$count} call(s).");
})->purpose('Mark ringing calls as missed after timeout');

Artisan::command('calls:cleanup-stale-availability {seconds=120}', function (HostAvailabilityService $service, int $seconds) {
    $count = $service->cleanupStaleSocketStatuses($seconds);
    $this->info("Cleaned {$count} stale availability record(s).");
})->purpose('Mark stale online host availability records as offline');

Artisan::command('calls:reconcile-billing', function (BillingReconciliationService $service) {
    $this->table(
        ['Issue', 'Count'],
        collect($service->anomalies())
            ->map(fn ($count, $issue) => [$issue, $count])
            ->values()
            ->all()
    );
})->purpose('Detect inconsistent call billing and ledger records');

Artisan::command('live-rooms:cleanup {--stale-minutes=2}', function (LiveRoomMaintenanceService $service) {
    $result = $service->cleanup((int) $this->option('stale-minutes'));

    $this->table(
        ['Room ID', 'Reason'],
        collect($result['ended'] ?? [])->map(fn ($row) => [$row['room_id'], $row['reason']])->all()
    );
    $this->info("Ended {$result['count']} live room(s).");
})->purpose('End stale live rooms or rooms with no active host participant');

Artisan::command('live-rooms:remind-hosts {--lead-minutes=2}', function (LiveRoomMaintenanceService $service) {
    $result = $service->remindScheduledHosts((int) $this->option('lead-minutes'));

    $this->table(
        ['Room ID', 'User ID'],
        collect($result['sent'] ?? [])->map(fn ($row) => [$row['room_id'], $row['user_id']])->all()
    );
    $this->info("Sent {$result['count']} scheduled host reminder(s).");
})->purpose('Send self-reminder notifications to hosts shortly before their scheduled live starts');

Artisan::command('live-rooms:sync-redis', function (LiveRoomStateService $service) {
    $count = $service->syncRedis();
    $this->info("Synced {$count} live room(s) to Redis.");
})->purpose('Rebuild Redis live room cache from the database');

Artisan::command('live-rooms:reconcile {--fix}', function (LiveRoomMaintenanceService $service) {
    $report = $service->reconcile((bool) $this->option('fix'));

    $this->table(
        ['Issue', 'Details'],
        [
            ['live_room_without_host', $report['live_room_without_host']['count']],
            ['ended_room_with_open_participants', $report['ended_room_with_open_participants']['count']],
            ['duplicate_open_participants', $report['duplicate_open_participants']['count']],
            ['redis_missing_live_rooms', count($report['redis_db_mismatch']['missing_in_redis'])],
            ['redis_extra_live_rooms', count($report['redis_db_mismatch']['extra_in_redis'])],
        ]
    );

    if ($this->option('fix')) {
        $this->line('Applied safe fixes: '.json_encode($report['fixed']));
    }
})->purpose('Detect and optionally fix inconsistent live room and participant state');

Artisan::command('pk:cleanup {--dry-run}', function (LiveRoomPkService $service) {
    $report = $service->cleanup((bool) $this->option('dry-run'));

    $this->table(
        ['Bucket', 'Count'],
        [
            ['expired_pending', count($report['expired_pending'])],
            ['completed_active', count($report['completed_active'])],
            ['failed_inconsistent', count($report['failed_inconsistent'])],
        ]
    );
})->purpose('Expire stale PK invites and complete overdue PK battles');

Artisan::command('recharge:reconcile {--sync-pending} {--limit=100}', function (RechargeOrderService $service) {
    $this->table(
        ['Issue', 'Count'],
        collect($service->anomalies())
            ->map(fn ($count, $issue) => [$issue, $count])
            ->values()
            ->all()
    );

    if ($this->option('sync-pending')) {
        $report = $service->reconcileGatewayOrders((int) $this->option('limit'));
        $this->table(
            ['Metric', 'Value'],
            [
                ['scanned', $report['scanned']],
                ['processed', $report['processed']],
                ['credited', $report['credited']],
                ['pending', $report['pending']],
                ['failed', $report['failed']],
                ['skipped', $report['skipped']],
                ['errors', count($report['errors'])],
            ]
        );
    }
})->purpose('Detect inconsistent recharge orders and wallet credits');

Artisan::command('agency:backfill', function (AgencyBackfillService $service) {
    $result = $service->run();

    $this->table(
        ['Target', 'Updated'],
        [
            ['call_sessions', $result['call_sessions_updated']],
            ['call_earning_ledgers', $result['call_earning_ledgers_updated']],
        ]
    );
})->purpose('Backfill missing agency_id values on call sessions and call earning ledgers');

Artisan::command('agency:payout-reports:generate {--start=} {--end=} {--agency_id=} {--force}', function (AgencyWeeklyPayoutReportService $service) {
    try {
        [$start, $end] = $service->resolvePeriod(
            $this->option('start'),
            $this->option('end')
        );

        $result = $service->generate(
            periodStart: $start,
            periodEnd: $end,
            agencyId: $this->option('agency_id') ? (int) $this->option('agency_id') : null,
            force: (bool) $this->option('force'),
        );
    } catch (\InvalidArgumentException $e) {
        $this->error($e->getMessage());
        return self::FAILURE;
    }

    $this->table(
        ['Agency ID', 'Agency', 'Period Start', 'Period End', 'Status', 'Final Payable'],
        collect($result['reports'])->map(function ($report) {
            return [
                $report->agency_id,
                $report->agency?->name,
                optional($report->period_start)->toDateTimeString(),
                optional($report->period_end)->toDateTimeString(),
                $report->status,
                $report->final_payable,
            ];
        })->all()
    );

    $this->info('Generated reports: ' . $result['generated_count']);
    return self::SUCCESS;
})->purpose('Generate weekly agency payout reports');

Artisan::command('levels:recalculate {--user=} {--dry-run}', function (UserLevelService $service) {
    $user = null;
    if ($this->option('user')) {
        $user = \App\Models\User::query()->findOrFail((int) $this->option('user'));
    }

    $report = $service->recalculate(
        targetUser: $user,
        dryRun: (bool) $this->option('dry-run')
    );

    $this->table(
        ['User', 'Old Spend', 'New Spend', 'Old Level', 'New Level', 'Changed'],
        collect($report['users'])
            ->map(fn ($row) => [
                $row['user_id'],
                $row['old_lifetime_spend_coins'],
                $row['new_lifetime_spend_coins'],
                $row['old_level_id'],
                $row['new_level_id'],
                $row['changed'] ? 'yes' : 'no',
            ])->all()
    );

    $this->info('Changed users: '.$report['changed_users']);
    $this->info('Dry run: '.($report['dry_run'] ? 'yes' : 'no'));
})->purpose('Recalculate lifetime spend and user levels from wallet transactions');

Artisan::command('leaderboards:backfill {--from=} {--to=}', function (LeaderboardService $service) {
    $result = $service->backfill(
        $this->option('from'),
        $this->option('to'),
    );

    $this->table(
        ['Metric', 'Value'],
        [
            ['deleted_rows', $result['deleted_rows']],
            ['upserted_rows', $result['upserted_rows']],
            ['from', $result['from'] ?? 'start'],
            ['to', $result['to'] ?? 'end'],
        ]
    );
})->purpose('Backfill leaderboard_daily_stats from gift and call earning ledgers');

Artisan::command('levels:recalculate {--user=} {--dry-run}', function (UserLevelService $service) {
    $targetUser = $this->option('user')
        ? User::query()->find((int) $this->option('user'))
        : null;
    $report = $service->recalculate($targetUser, (bool) $this->option('dry-run'));

    $this->table(
        ['User', 'Old Level', 'New Level', 'Old Spend', 'New Spend'],
        collect($report['users'] ?? [])->map(fn ($row) => [
            $row['user_id'],
            $row['old_level_id'] ?? '—',
            $row['new_level_id'] ?? '—',
            $row['old_lifetime_spend_coins'] ?? 0,
            $row['new_lifetime_spend_coins'] ?? 0,
        ])->all()
    );
})->purpose('Recalculate lifetime spend and user levels from wallet transactions');
