<?php

namespace Tests\Feature;

use App\Models\CallSession;
use App\Models\Host;
use App\Models\RechargePlan;
use App\Models\User;
use App\Models\UserLevel;
use App\Models\UserNotification;
use App\Models\Wallet;
use App\Services\CallBillingService;
use App\Services\UserLevelService;
use App\Services\WalletService;
use Database\Seeders\RechargePlanSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserLevelSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->seed([
            UserLevelSeeder::class,
            RechargePlanSeeder::class,
        ]);
    }

    public function test_recharge_does_not_increase_level_or_lifetime_spend(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $plan = RechargePlan::query()->firstOrFail();
        $orderId = $this->postJson('/api/recharge/orders', ['plan_id' => $plan->id])->json('data.order_id');

        $this->postJson("/api/recharge/orders/{$orderId}/verify", ['result' => 'success'])
            ->assertOk();

        $user->refresh();

        $this->assertSame(0, (int) $user->lifetime_spend_coins);
        $this->assertNotNull($user->level_id);
        $this->assertSame(1, (int) $user->level?->level);
        $this->assertDatabaseCount('level_spend_events', 0);
    }

    public function test_gift_spend_increases_level_progress_and_level_at_threshold(): void
    {
        $user = $this->makeUserWithBalance(1200);
        $levelOne = UserLevel::query()->where('level', 1)->firstOrFail();
        $levelTwo = UserLevel::query()->where('level', 2)->firstOrFail();

        $transaction = WalletService::spend($user, 1200, 'gift', null, 'gift:test');

        $user->refresh();

        $this->assertSame('gift', $transaction->category);
        $this->assertSame(1200, (int) $user->lifetime_spend_coins);
        $this->assertSame(2, (int) $user->level?->level);
        $this->assertDatabaseHas('level_spend_events', [
            'user_id' => $user->id,
            'wallet_transaction_id' => $transaction->id,
            'spend_coins' => 1200,
        ]);
        $this->assertDatabaseHas('user_level_histories', [
            'user_id' => $user->id,
            'old_level_id' => $levelOne->id,
            'new_level_id' => $levelTwo->id,
            'triggered_by_transaction_id' => $transaction->id,
        ]);
    }

    public function test_call_charge_increases_level_progress(): void
    {
        $caller = $this->makeUserWithBalance(2500);
        $receiver = User::factory()->create();
        $receiver->assignRole('host');
        $host = Host::query()->create([
            'user_id' => $receiver->id,
            'stage_name' => 'Level Host',
        ]);

        $call = CallSession::query()->create([
            'caller_id' => $caller->id,
            'receiver_id' => $receiver->id,
            'host_id' => $host->id,
            'type' => 'audio',
            'status' => 'ended',
            'accepted_at' => now()->subMinutes(10),
            'started_at' => now()->subMinutes(10),
            'ended_at' => now(),
            'coin_rate_per_minute' => 100,
        ]);

        app(CallBillingService::class)->processEndedCall($call);

        $caller->refresh();

        $this->assertSame(1000, (int) $caller->lifetime_spend_coins);
        $this->assertSame(2, (int) $caller->level?->level);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $caller->wallet->id,
            'category' => 'audio_call',
            'type' => 'debit',
            'coins' => 1000,
        ]);
        $this->assertDatabaseHas('level_spend_events', [
            'user_id' => $caller->id,
            'spend_coins' => 1000,
        ]);
    }

    public function test_failed_spend_and_earnings_do_not_count_toward_levels(): void
    {
        $caller = $this->makeUserWithBalance(100);
        $receiver = User::factory()->create();
        $receiver->assignRole('host');
        $host = Host::query()->create([
            'user_id' => $receiver->id,
            'stage_name' => 'Blocked Host',
        ]);

        $failedCall = CallSession::query()->create([
            'caller_id' => $caller->id,
            'receiver_id' => $receiver->id,
            'host_id' => $host->id,
            'type' => 'video',
            'status' => 'ended',
            'accepted_at' => now()->subMinutes(10),
            'started_at' => now()->subMinutes(10),
            'ended_at' => now(),
            'coin_rate_per_minute' => 50,
        ]);

        app(CallBillingService::class)->processEndedCall($failedCall);
        WalletService::earn($caller, 250, 'gift_earning', $receiver, 'earning:test');

        $caller->wallet->transactions()->create([
            'type' => 'credit',
            'coins' => 75,
            'category' => 'refund',
            'reference' => 'refund:test',
            'balance_before' => $caller->wallet->balance,
            'balance_after' => $caller->wallet->balance,
        ]);

        $caller->refresh();

        $this->assertSame(0, (int) $caller->lifetime_spend_coins);
        $this->assertSame(1, (int) $caller->level?->level);
        $this->assertDatabaseCount('level_spend_events', 0);
        $this->assertDatabaseMissing('wallet_transactions', [
            'wallet_id' => $caller->wallet->id,
            'category' => 'video_call',
            'type' => 'debit',
        ]);
    }

    public function test_game_bets_do_not_increase_level_progress(): void
    {
        $user = $this->makeUserWithBalance(2000);

        $transaction = WalletService::spend(
            $user,
            1200,
            'game_bet_debit',
            null,
            'teen_patti_bet:test',
            ['game' => 'teen_patti'],
        );

        $user->refresh();

        $this->assertSame(800, (int) $user->wallet->fresh()->balance);
        $this->assertSame(0, (int) $user->lifetime_spend_coins);
        $this->assertSame(1, (int) $user->level?->level);
        $this->assertDatabaseMissing('level_spend_events', [
            'wallet_transaction_id' => $transaction->id,
        ]);
    }

    public function test_duplicate_processing_does_not_count_same_wallet_transaction_twice(): void
    {
        $user = $this->makeUserWithBalance(400);
        $transaction = WalletService::spend($user, 300, 'gift', null, 'dup:test');

        app(UserLevelService::class)->processWalletTransaction($transaction->id);
        $user->refresh();

        $this->assertSame(300, (int) $user->lifetime_spend_coins);
        $this->assertDatabaseCount('level_spend_events', 1);
    }

    public function test_level_changes_exactly_at_threshold(): void
    {
        $user = $this->makeUserWithBalance(1000);

        WalletService::spend($user, 400, 'other', null, 'threshold:1');
        WalletService::spend($user, 600, 'gift', null, 'threshold:2');

        $user->refresh();

        $this->assertSame(1000, (int) $user->lifetime_spend_coins);
        $this->assertSame(2, (int) $user->level?->level);
        $this->assertDatabaseCount('user_level_histories', 1);
    }

    public function test_recalculate_command_backfills_old_spend_and_supports_dry_run(): void
    {
        $user = $this->makeUserWithBalance(0);
        $wallet = $user->wallet()->firstOrFail();

        DB::table('wallet_transactions')->insert([
            [
                'wallet_id' => $wallet->id,
                'type' => 'debit',
                'coins' => 1200,
                'category' => 'gift',
                'reference' => 'legacy:gift',
                'balance_before' => 1200,
                'balance_after' => 0,
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour(),
            ],
            [
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'coins' => 500,
                'category' => 'recharge',
                'reference' => 'legacy:recharge',
                'balance_before' => 0,
                'balance_after' => 500,
                'created_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(30),
            ],
        ]);

        $user->forceFill([
            'lifetime_spend_coins' => 0,
            'level_id' => null,
        ])->save();

        $report = app(UserLevelService::class)->recalculate($user, true);
        $this->assertTrue((bool) ($report['dry_run'] ?? false));

        $user->refresh();
        $this->assertSame(0, (int) $user->lifetime_spend_coins);
        $this->assertNull($user->level_id);

        $this->artisan('levels:recalculate', [
            '--user' => $user->id,
        ])->assertExitCode(0);

        $user->refresh();

        $this->assertSame(1200, (int) $user->lifetime_spend_coins);
        $this->assertSame(2, (int) $user->level?->level);
        $this->assertDatabaseHas('level_spend_events', [
            'user_id' => $user->id,
            'wallet_transaction_id' => DB::table('wallet_transactions')->where('reference', 'legacy:gift')->value('id'),
            'spend_coins' => 1200,
        ]);
    }

    public function test_recalculate_removes_historical_game_bets_from_level_progress(): void
    {
        $user = $this->makeUserWithBalance(0);
        $wallet = $user->wallet()->firstOrFail();
        $levelTwo = UserLevel::query()->where('level', 2)->firstOrFail();

        $transactionId = DB::table('wallet_transactions')->insertGetId([
            'wallet_id' => $wallet->id,
            'type' => 'debit',
            'coins' => 1200,
            'category' => 'game_bet_debit',
            'reference' => 'greedy_bet:legacy',
            'balance_before' => 1200,
            'balance_after' => 0,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        DB::table('level_spend_events')->insert([
            'user_id' => $user->id,
            'wallet_transaction_id' => $transactionId,
            'spend_coins' => 1200,
            'created_at' => now()->subHour(),
        ]);
        $user->forceFill([
            'lifetime_spend_coins' => 1200,
            'level_id' => $levelTwo->id,
        ])->save();

        app(UserLevelService::class)->recalculate($user);
        $user->refresh();

        $this->assertSame(0, (int) $user->lifetime_spend_coins);
        $this->assertSame(1, (int) $user->level?->level);
        $this->assertDatabaseMissing('level_spend_events', [
            'wallet_transaction_id' => $transactionId,
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $user->id,
            'type' => 'level_up',
        ]);
    }

    public function test_recalculate_preserves_legacy_spend_and_adds_only_eligible_new_spend(): void
    {
        $user = $this->makeUserWithBalance(2500);
        $user->forceFill([
            'legacy_lifetime_spend_coins' => 5000,
            'lifetime_spend_coins' => 5000,
        ])->save();

        WalletService::spend($user, 1200, 'gift', null, 'gift:new');
        WalletService::spend($user, 1200, 'game_bet_debit', null, 'teen_patti_bet:new');

        app(UserLevelService::class)->recalculate($user->fresh());
        $user->refresh();

        $this->assertSame(5000, (int) $user->legacy_lifetime_spend_coins);
        $this->assertSame(6200, (int) $user->lifetime_spend_coins);
        $this->assertSame(3, (int) $user->level?->level);
    }

    public function test_profile_endpoint_includes_level_progress_fields(): void
    {
        $user = $this->makeUserWithBalance(6200);
        WalletService::spend($user, 5200, 'gift', null, 'profile:test');
        $user = $user->fresh();

        Sanctum::actingAs($user);

        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.level', 3)
            ->assertJsonPath('data.level_title', 'Popular')
            ->assertJsonPath('data.lifetime_spend_coins', 5200)
            ->assertJsonPath('data.next_level', 4)
            ->assertJsonPath('data.next_level_title', 'Super Fan')
            ->assertJsonPath('data.next_level_required_spend', 10000)
            ->assertJsonPath('data.remaining_spend_to_next_level', 4800);
    }

    public function test_levels_endpoint_returns_seeded_thresholds(): void
    {
        $this->getJson('/api/levels')
            ->assertOk()
            ->assertJsonPath('data.0.level', 1)
            ->assertJsonPath('data.0.title', 'Newbie')
            ->assertJsonPath('data.1.level', 2)
            ->assertJsonPath('data.1.min_spend_coins', 1000)
            ->assertJsonPath('data.9.level', 10)
            ->assertJsonPath('data.9.min_spend_coins', 1000000)
            ->assertJsonPath('data.99.level', 100);
    }

    public function test_level_reward_frame_unlocks_when_entering_new_10_level_band(): void
    {
        $user = $this->makeUserWithBalance(1500000);

        WalletService::spend($user, 1500000, 'gift', null, 'level-frame:test');

        $user->refresh();

        $this->assertSame(11, (int) $user->level?->level);
        $this->assertDatabaseHas('user_profile_frames', [
            'user_id' => $user->id,
            'source' => 'level_reward',
            'is_equipped' => true,
        ]);

        $notification = UserNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'profile_frame_unlocked')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('sapphire-crown-laurel', data_get($notification->meta, 'profile_frame.slug'));
    }

    private function makeUserWithBalance(int $balance): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['balance' => $balance]
        );

        return $user->fresh(['wallet', 'level']);
    }
}
