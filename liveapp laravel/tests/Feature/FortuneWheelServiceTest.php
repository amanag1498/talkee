<?php

namespace Tests\Feature;

use App\Models\EntryPack;
use App\Models\FortuneWheelSegment;
use App\Models\FortuneWheelSpin;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserEntryPack;
use App\Models\UserGameAccess;
use App\Models\UserSubscription;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\FortuneWheelService;
use App\Services\GameAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FortuneWheelServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'games.fortune_wheel.enabled' => true,
            'games.fortune_wheel.visible_in_video_room_strip' => true,
            'games.fortune_wheel.free_spins_per_day' => 1,
            'games.fortune_wheel.paid_spin_cost_coins' => 50,
            'games.fortune_wheel.paid_spins_enabled' => true,
            'games.fortune_wheel.timezone' => 'Asia/Kolkata',
            'app_features.platform.android.fortune_wheel_enabled' => true,
        ]);
    }

    public function test_api_snapshot_is_locked_until_user_has_fortune_wheel_access(): void
    {
        $user = User::factory()->create();
        $this->coinSegment('0 Coins', 0);
        Sanctum::actingAs($user);

        $this->withHeaders(['X-Client-Platform' => 'android'])
            ->getJson('/api/games/fortune-wheel')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Fortune Wheel is locked for this user.');

        UserGameAccess::query()->create([
            'user_id' => $user->id,
            'game_key' => GameAccessService::GAME_FORTUNE_WHEEL,
        ]);

        $this->withHeaders(['X-Client-Platform' => 'android'])
            ->getJson('/api/games/fortune-wheel')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.free_spins_remaining', 1);
    }

    public function test_free_spin_then_paid_spin_debits_cost_and_credits_coin_reward(): void
    {
        $user = User::factory()->create();
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 500]);
        $this->coinSegment('20 Coins', 20);

        $service = app(FortuneWheelService::class);

        $freeSpin = $service->spin($user, 'fortune-free-1');
        $this->assertSame(FortuneWheelSpin::TYPE_FREE, data_get($freeSpin, 'spin.spin_type'));
        $this->assertSame(20, data_get($freeSpin, 'spin.reward_value_coins'));
        $this->assertSame(520, data_get($freeSpin, 'wallet_balance'));
        $this->assertSame(0, data_get($freeSpin, 'free_spins_remaining'));
        $this->assertCount(1, data_get($freeSpin, 'segments'));
        $this->assertSame(
            data_get($freeSpin, 'spin.segment.id'),
            data_get($freeSpin, 'segments.0.id'),
        );

        $paidSpin = $service->spin($user, 'fortune-paid-1');
        $this->assertSame(FortuneWheelSpin::TYPE_PAID, data_get($paidSpin, 'spin.spin_type'));
        $this->assertSame(50, data_get($paidSpin, 'spin.spin_cost_coins'));
        $this->assertSame(490, data_get($paidSpin, 'wallet_balance'));

        $this->assertDatabaseHas('wallet_transactions', [
            'type' => 'debit',
            'coins' => 50,
            'category' => 'other',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'type' => 'credit',
            'coins' => 20,
            'category' => 'game_reward_credit',
            'reference_type' => 'fortune_wheel_spin',
        ]);
    }

    public function test_spin_idempotency_does_not_double_debit_or_reward(): void
    {
        $user = User::factory()->create();
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 100]);
        $this->coinSegment('0 Coins', 0);

        $service = app(FortuneWheelService::class);
        $first = $service->spin($user, 'same-spin-key');
        $second = $service->spin($user, 'same-spin-key');

        $this->assertSame(data_get($first, 'spin.id'), data_get($second, 'spin.id'));
        $this->assertSame(1, FortuneWheelSpin::query()->count());
        $this->assertSame(0, WalletTransaction::query()->count());
        $this->assertSame(100, (int) Wallet::query()->where('user_id', $user->id)->value('balance'));
    }

    public function test_zero_coin_segment_is_a_valid_reward_without_credit_transaction(): void
    {
        $user = User::factory()->create();
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 25]);
        $this->coinSegment('0 Coins', 0);

        $result = app(FortuneWheelService::class)->spin($user, 'zero-coin-spin');

        $this->assertSame(FortuneWheelSegment::REWARD_COINS, data_get($result, 'spin.reward_type'));
        $this->assertSame(0, data_get($result, 'spin.reward_value_coins'));
        $this->assertSame(25, data_get($result, 'wallet_balance'));
        $this->assertDatabaseHas('fortune_wheel_spins', [
            'user_id' => $user->id,
            'reward_type' => FortuneWheelSegment::REWARD_COINS,
            'reward_value_coins' => 0,
        ]);
        $this->assertSame(0, WalletTransaction::query()->count());
    }

    public function test_entry_pack_reward_grants_timed_user_pack(): void
    {
        $user = User::factory()->create();
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 0]);
        $pack = EntryPack::query()->create([
            'name' => 'Royal Entry',
            'price_coins' => 200,
            'svg_url' => 'https://cdn.example.com/royal.svg',
            'animation_style' => 'banner',
            'priority' => 5,
            'duration_ms' => 3000,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        FortuneWheelSegment::query()->create([
            'label' => 'Royal Entry 1 Day',
            'reward_type' => FortuneWheelSegment::REWARD_ENTRY_PACK,
            'entry_pack_id' => $pack->id,
            'reward_duration_hours' => 24,
            'weight' => 1,
            'is_active' => true,
        ]);

        $result = app(FortuneWheelService::class)->spin($user, 'entry-pack-spin');

        $this->assertSame(FortuneWheelSegment::REWARD_ENTRY_PACK, data_get($result, 'spin.reward_type'));
        $this->assertSame($pack->id, data_get($result, 'spin.entry_pack_id'));

        $userPack = UserEntryPack::query()->where('user_id', $user->id)->where('entry_pack_id', $pack->id)->first();
        $this->assertNotNull($userPack);
        $this->assertTrue($userPack->is_active);
        $this->assertSame('fortune_wheel:'.data_get($result, 'spin.id'), $userPack->purchase_key);
        $this->assertSame('fortune_wheel', $userPack->source);
        $this->assertFalse($userPack->charged);
        $this->assertSame(0, $userPack->price_coins);
        $this->assertSame('fortune_wheel_spin:'.data_get($result, 'spin.id'), $userPack->purchase_reference);
        $this->assertTrue($userPack->expires_at->between(now()->addHours(23), now()->addHours(25)));
    }

    public function test_subscription_reward_creates_and_extends_active_plan(): void
    {
        $user = User::factory()->create();
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 0]);
        $plan = SubscriptionPlan::query()->create([
            'name' => 'VIP',
            'price_coins' => 500,
            'duration_days' => 30,
            'perks' => ['badge' => true],
            'is_active' => true,
        ]);
        FortuneWheelSegment::query()->create([
            'label' => 'VIP 1 Day',
            'reward_type' => FortuneWheelSegment::REWARD_SUBSCRIPTION,
            'subscription_plan_id' => $plan->id,
            'reward_duration_hours' => 24,
            'weight' => 1,
            'is_active' => true,
        ]);

        $service = app(FortuneWheelService::class);
        $first = $service->spin($user, 'subscription-spin-1');
        config(['games.fortune_wheel.free_spins_per_day' => 2]);
        $second = $service->spin($user, 'subscription-spin-2');

        $this->assertSame(FortuneWheelSegment::REWARD_SUBSCRIPTION, data_get($first, 'spin.reward_type'));
        $this->assertSame(data_get($first, 'spin.subscription_plan_id'), data_get($second, 'spin.subscription_plan_id'));

        $subscription = UserSubscription::query()->where('user_id', $user->id)->where('subscription_plan_id', $plan->id)->first();
        $this->assertNotNull($subscription);
        $this->assertSame('active', $subscription->status);
        $this->assertSame(1, UserSubscription::query()->count());
        $this->assertTrue($subscription->ends_at->greaterThan(now()->addHours(47)));
        $this->assertSame('fortune_wheel', data_get($subscription->meta, 'source'));
        $this->assertFalse(data_get($subscription->meta, 'charged'));
    }

    public function test_paid_spin_requires_sufficient_wallet_balance(): void
    {
        config(['games.fortune_wheel.free_spins_per_day' => 0]);

        $user = User::factory()->create();
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 10]);
        $this->coinSegment('20 Coins', 20);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Insufficient wallet balance.');

        app(FortuneWheelService::class)->spin($user, 'insufficient-paid-spin');
    }

    public function test_paid_spins_disabled_blocks_after_daily_free_spin_is_used(): void
    {
        config(['games.fortune_wheel.paid_spins_enabled' => false]);

        $user = User::factory()->create();
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 500]);
        $this->coinSegment('0 Coins', 0);

        $service = app(FortuneWheelService::class);
        $service->spin($user, 'free-spin-before-disabled-paid');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Paid Fortune Wheel spins are disabled.');

        $service->spin($user, 'paid-spin-disabled');
    }

    public function test_inactive_entry_pack_and_subscription_segments_are_not_selected(): void
    {
        $user = User::factory()->create();
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 0]);
        $pack = EntryPack::query()->create([
            'name' => 'Inactive Entry',
            'price_coins' => 100,
            'svg_url' => 'https://cdn.example.com/inactive.svg',
            'animation_style' => 'banner',
            'priority' => 1,
            'duration_ms' => 2000,
            'is_active' => false,
            'sort_order' => 1,
        ]);
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Inactive VIP',
            'price_coins' => 400,
            'duration_days' => 7,
            'is_active' => false,
        ]);
        FortuneWheelSegment::query()->create([
            'label' => 'Inactive Entry Pack',
            'reward_type' => FortuneWheelSegment::REWARD_ENTRY_PACK,
            'entry_pack_id' => $pack->id,
            'reward_duration_hours' => 24,
            'weight' => 100000,
            'is_active' => true,
        ]);
        FortuneWheelSegment::query()->create([
            'label' => 'Inactive Subscription',
            'reward_type' => FortuneWheelSegment::REWARD_SUBSCRIPTION,
            'subscription_plan_id' => $plan->id,
            'reward_duration_hours' => 24,
            'weight' => 100000,
            'is_active' => true,
        ]);
        $this->coinSegment('Safe 0 Coins', 0);

        $result = app(FortuneWheelService::class)->spin($user, 'skip-inactive-rewards');

        $this->assertSame(FortuneWheelSegment::REWARD_COINS, data_get($result, 'spin.reward_type'));
        $this->assertSame('Safe 0 Coins', data_get($result, 'spin.segment.label'));
        $this->assertSame(0, UserEntryPack::query()->count());
        $this->assertSame(0, UserSubscription::query()->count());
    }

    public function test_spin_and_history_apis_are_idempotent_and_user_scoped(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $segment = $this->coinSegment('API 0 Coins', 0);
        UserGameAccess::query()->create([
            'user_id' => $user->id,
            'game_key' => GameAccessService::GAME_FORTUNE_WHEEL,
        ]);
        FortuneWheelSpin::query()->create([
            'user_id' => $otherUser->id,
            'fortune_wheel_segment_id' => $segment->id,
            'spin_type' => FortuneWheelSpin::TYPE_FREE,
            'spin_cost_coins' => 0,
            'reward_type' => FortuneWheelSegment::REWARD_COINS,
            'reward_value_coins' => 0,
            'spun_for_date' => now()->toDateString(),
        ]);

        Sanctum::actingAs($user);
        $headers = ['X-Client-Platform' => 'android'];
        $payload = ['idempotency_key' => 'api-idempotent-spin'];

        $first = $this->withHeaders($headers)
            ->postJson('/api/games/fortune-wheel/spin', $payload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json('data.spin.id');
        $second = $this->withHeaders($headers)
            ->postJson('/api/games/fortune-wheel/spin', $payload)
            ->assertOk()
            ->json('data.spin.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, FortuneWheelSpin::query()->where('user_id', $user->id)->count());
        $this->withHeaders($headers)
            ->getJson('/api/games/fortune-wheel/history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first);
    }

    public function test_disabled_game_and_empty_reward_catalog_are_rejected(): void
    {
        $user = User::factory()->create();
        UserGameAccess::query()->create([
            'user_id' => $user->id,
            'game_key' => GameAccessService::GAME_FORTUNE_WHEEL,
        ]);
        Sanctum::actingAs($user);

        config(['games.fortune_wheel.enabled' => false]);
        $this->withHeaders(['X-Client-Platform' => 'android'])
            ->getJson('/api/games/fortune-wheel')
            ->assertForbidden()
            ->assertJsonPath('message', 'Fortune Wheel is currently unavailable.');

        config(['games.fortune_wheel.enabled' => true]);
        $this->withHeaders(['X-Client-Platform' => 'android'])
            ->postJson('/api/games/fortune-wheel/spin', ['idempotency_key' => 'empty-wheel'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Fortune Wheel has no active rewards configured.');
    }

    private function coinSegment(string $label, int $coins): FortuneWheelSegment
    {
        return FortuneWheelSegment::query()->create([
            'label' => $label,
            'reward_type' => FortuneWheelSegment::REWARD_COINS,
            'reward_value_coins' => $coins,
            'weight' => 1,
            'is_active' => true,
        ]);
    }
}
