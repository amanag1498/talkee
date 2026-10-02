<?php

namespace Tests\Feature;

use App\Models\CallEarningLedger;
use App\Models\CallSession;
use App\Models\Host;
use App\Models\HostAvailability;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\BillingReconciliationService;
use App\Services\CallBillingService;
use App\Services\CallReportService;
use App\Services\CallSessionService;
use App\Services\HostAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CallServerParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_per_minute_billing_references_are_reported_even_when_total_matches(): void
    {
        $caller = User::factory()->create();
        $receiver = User::factory()->create();
        $host = Host::query()->create([
            'user_id' => $receiver->id,
            'stage_name' => 'Duplicate Test Host',
        ]);
        $wallet = Wallet::query()->updateOrCreate(['user_id' => $caller->id], ['balance' => 3000]);
        $call = CallSession::query()->create([
            'caller_id' => $caller->id,
            'receiver_id' => $receiver->id,
            'host_id' => $host->id,
            'type' => 'video',
            'status' => 'ended',
            'accepted_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
            'ended_at' => now(),
            'coin_rate_per_minute' => 1000,
            'billable_minutes' => 2,
            'total_coins_charged' => 2000,
            'billing_processed_at' => now(),
        ]);

        foreach ([[5000, 4000], [4000, 3000]] as [$before, $after]) {
            WalletTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'type' => 'debit',
                'coins' => 1000,
                'category' => 'video_call',
                'reference' => "call_billing:{$call->id}:1",
                'counterparty_user_id' => $receiver->id,
                'balance_before' => $before,
                'balance_after' => $after,
            ]);
        }

        CallEarningLedger::query()->create([
            'call_session_id' => $call->id,
            'caller_id' => $caller->id,
            'host_id' => $host->id,
            'agency_id' => null,
            'total_coins' => 2000,
            'host_earning' => 1200,
            'agency_earning' => 0,
            'platform_earning' => 800,
            'duration_seconds' => 60,
            'billable_minutes' => 2,
        ]);

        $anomalies = app(BillingReconciliationService::class)->anomalies();

        $this->assertSame(2000, (int) WalletTransaction::query()
            ->where('type', 'debit')
            ->where('reference', "call_billing:{$call->id}:1")
            ->sum('coins'));
        $this->assertSame(2000, (int) WalletTransaction::query()
            ->where('type', 'debit')
            ->where(function ($query) use ($call) {
                $query->where('reference', "call_billing:{$call->id}")
                    ->orWhere('reference', 'like', "call_billing:{$call->id}:%");
            })
            ->sum('coins'));
        $this->assertSame(2000, (int) $call->fresh()->total_coins_charged);
        $this->assertSame(1, $anomalies['duplicate_billing_references']);
        $this->assertSame(0, $anomalies['call_wallet_debit_total_mismatch']);
    }

    public function test_reconciliation_does_not_issue_a_query_for_every_billed_call(): void
    {
        $caller = User::factory()->create();
        $receiver = User::factory()->create();
        for ($minute = 0; $minute < 25; $minute++) {
            CallSession::query()->create([
                'caller_id' => $caller->id,
                'receiver_id' => $receiver->id,
                'type' => 'video',
                'status' => 'ended',
                'coin_rate_per_minute' => 1000,
                'total_coins_charged' => 1000,
                'billing_processed_at' => now(),
            ]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $anomalies = app(BillingReconciliationService::class)->anomalies();
            $queryCount = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame(25, $anomalies['calls_missing_wallet_transaction']);
        $this->assertSame(25, $anomalies['calls_missing_earning_ledger']);
        $this->assertSame(25, $anomalies['call_wallet_debit_total_mismatch']);
        $this->assertLessThanOrEqual(20, $queryCount);
    }

    public function test_temporary_billing_failure_does_not_end_an_accepted_call(): void
    {
        $caller = User::factory()->create();
        $receiver = User::factory()->create();
        $call = CallSession::query()->create([
            'caller_id' => $caller->id,
            'receiver_id' => $receiver->id,
            'type' => 'video',
            'status' => 'accepted',
            'coin_rate_per_minute' => 1000,
            'accepted_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);

        $this->mock(CallBillingService::class)
            ->shouldReceive('syncAcceptedCallBilling')
            ->once()
            ->andThrow(new \RuntimeException('Lock wait timeout'));

        $this->assertSame(0, app(CallSessionService::class)->enforceAcceptedCallBilling());
        $this->assertDatabaseHas('call_sessions', [
            'id' => $call->id,
            'status' => 'accepted',
            'end_reason' => null,
        ]);
    }

    public function test_call_financial_summary_uses_earning_ledger_and_is_user_scoped(): void
    {
        $caller = User::factory()->create();
        $receiver = User::factory()->create();
        $host = Host::query()->create([
            'user_id' => $receiver->id,
            'stage_name' => 'Ledger Host',
        ]);
        $call = CallSession::query()->create([
            'caller_id' => $caller->id,
            'receiver_id' => $receiver->id,
            'host_id' => $host->id,
            'type' => 'video',
            'status' => 'ended',
            'accepted_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
            'ended_at' => now(),
            'coin_rate_per_minute' => 100,
            'billable_minutes' => 99,
            'total_coins_charged' => 9999,
            'host_earning' => 9999,
            'agency_earning' => 9999,
            'platform_earning' => 9999,
            'billing_processed_at' => now(),
        ]);
        CallEarningLedger::query()->create([
            'call_session_id' => $call->id,
            'caller_id' => $caller->id,
            'host_id' => $host->id,
            'agency_id' => null,
            'total_coins' => 100,
            'host_earning' => 60,
            'agency_earning' => 0,
            'platform_earning' => 40,
            'duration_seconds' => 59,
            'billable_minutes' => 1,
        ]);

        $report = app(CallReportService::class)->forUserHistory(Request::create('/calls/history'), $caller);

        $this->assertSame(1, $report['summary']['total_minutes']);
        $this->assertSame(100, $report['summary']['total_coins_charged']);
        $this->assertSame(60, $report['summary']['total_host_earnings']);
        $this->assertSame(0, $report['summary']['total_agency_earnings']);
        $this->assertSame(40, $report['summary']['total_platform_earnings']);
    }

    public function test_online_heartbeat_prevents_an_active_call_from_being_cleaned_up_as_stale(): void
    {
        $caller = User::factory()->create();
        $receiver = User::factory()->create();
        $call = CallSession::query()->create([
            'caller_id' => $caller->id,
            'receiver_id' => $receiver->id,
            'type' => 'video',
            'status' => 'accepted',
            'livekit_room_name' => 'call_presence_test',
            'started_at' => now()->subMinute(),
            'accepted_at' => now()->subMinute(),
            'coin_rate_per_minute' => 20,
        ]);
        HostAvailability::query()->create([
            'user_id' => $receiver->id,
            'manual_status' => 'online',
            'socket_status' => 'online',
            'call_status' => 'busy',
            'current_call_session_id' => $call->id,
            'last_seen_at' => now()->subMinutes(5),
        ]);

        $service = app(HostAvailabilityService::class);
        $service->updateSocketStatus($receiver->id, 'online');

        $this->assertSame(0, $service->cleanupStaleSocketStatuses(120));
        $this->assertDatabaseHas('call_sessions', [
            'id' => $call->id,
            'status' => 'accepted',
            'end_reason' => null,
        ]);
    }
}
