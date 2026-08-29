<?php

namespace Tests\Feature;

use App\Models\EntryPack;
use App\Models\FortuneWheelSegment;
use App\Models\FortuneWheelSpin;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserEntryPack;
use App\Models\UserSubscription;
use App\Services\FortuneWheelService;
use Carbon\CarbonImmutable;
use Database\Seeders\EntryPackSeeder;
use Database\Seeders\FortuneWheelSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FortuneWheelAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        config([
            'games.fortune_wheel.enabled' => true,
            'games.fortune_wheel.paid_spins_enabled' => true,
            'games.fortune_wheel.paid_spin_cost_coins' => 50,
        ]);
    }

    public function test_dashboard_shows_runtime_odds_and_seeded_catalog_rewards(): void
    {
        $this->seed([
            EntryPackSeeder::class,
            SubscriptionPlanSeeder::class,
            FortuneWheelSeeder::class,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.games.fortune-wheel.dashboard'));

        $response->assertOk()
            ->assertSee('Fortune Wheel Control Room')
            ->assertSee('Reward Mix')
            ->assertSee('Catalog Readiness')
            ->assertSee('Royal Entry 1 Day')
            ->assertSee('Bronze 1 Day')
            ->assertSee('% chance');
    }

    public function test_segment_validation_rejects_duplicates_and_malformed_visual_fields(): void
    {
        $this->coinSegment('Existing Reward', 10);

        $response = $this->actingAs($this->admin)
            ->from(route('admin.games.fortune-wheel.dashboard'))
            ->post(route('admin.games.fortune-wheel.segments.store'), [
                '_segment_context' => 'new',
                'label' => 'Existing Reward',
                'reward_type' => FortuneWheelSegment::REWARD_COINS,
                'reward_value_coins' => 20,
                'weight' => 1,
                'sort_order' => 2,
                'color' => 'purple',
                'icon_url' => 'javascript:alert(1)',
                'is_active' => 1,
            ]);

        $response->assertRedirect(route('admin.games.fortune-wheel.dashboard'))
            ->assertSessionHasErrors(['label', 'color', 'icon_url']);
    }

    public function test_active_timed_reward_requires_an_active_catalog_item(): void
    {
        $pack = $this->entryPack('Inactive Entry', false);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.games.fortune-wheel.segments.store'), [
                '_segment_context' => 'new',
                'label' => 'Unavailable Entry Reward',
                'reward_type' => FortuneWheelSegment::REWARD_ENTRY_PACK,
                'entry_pack_id' => $pack->id,
                'reward_duration_hours' => 24,
                'weight' => 1,
                'sort_order' => 1,
                'color' => '#7C3AED',
                'is_active' => 1,
            ]);

        $response->assertSessionHasErrors(['entry_pack_id']);
        $this->assertDatabaseMissing('fortune_wheel_segments', [
            'label' => 'Unavailable Entry Reward',
        ]);
    }

    public function test_enabled_wheel_cannot_delete_its_final_selectable_segment(): void
    {
        $segment = $this->coinSegment('Only Reward', 0);

        $response = $this->actingAs($this->admin)
            ->delete(route('admin.games.fortune-wheel.segments.destroy', $segment));

        $response->assertSessionHasErrors(['segment']);
        $this->assertDatabaseHas('fortune_wheel_segments', ['id' => $segment->id]);
    }

    public function test_admin_metrics_exclude_active_segments_with_inactive_catalog_rewards(): void
    {
        $this->coinSegment('Safe Reward', 10, 1);
        $pack = $this->entryPack('Inactive Entry', false);
        FortuneWheelSegment::query()->create([
            'label' => 'Excluded Reward',
            'reward_type' => FortuneWheelSegment::REWARD_ENTRY_PACK,
            'entry_pack_id' => $pack->id,
            'reward_duration_hours' => 24,
            'weight' => 99,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $payload = app(FortuneWheelService::class)->adminDashboardPayload();

        $this->assertSame(2, $payload['summary']['active_segments']);
        $this->assertSame(1, $payload['summary']['eligible_segments']);
        $this->assertSame(1, $payload['expected_value']['total_weight']);
        $this->assertSame(10.0, $payload['expected_value']['average_coin_reward']);
        $this->assertNotEmpty($payload['health_warnings']);
    }

    public function test_dashboard_exposes_daily_weekly_entitlements_and_filtered_spin_audit(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 12:00:00', 'Asia/Kolkata'));
        config(['games.fortune_wheel.timezone' => 'Asia/Kolkata']);

        $todayPlayer = User::factory()->create(['name' => 'Today Player']);
        $weeklyPlayer = User::factory()->create(['name' => 'Weekly Player']);
        $olderPlayer = User::factory()->create(['name' => 'Older Player']);

        $this->spin($todayPlayer, [
            'spin_type' => FortuneWheelSpin::TYPE_FREE,
            'reward_type' => FortuneWheelSegment::REWARD_ENTRY_PACK,
            'reward_duration_hours' => 24,
            'spun_for_date' => '2026-08-26',
        ]);
        $this->spin($weeklyPlayer, [
            'spin_type' => FortuneWheelSpin::TYPE_PAID,
            'spin_cost_coins' => 50,
            'reward_type' => FortuneWheelSegment::REWARD_SUBSCRIPTION,
            'reward_duration_hours' => 168,
            'spun_for_date' => '2026-08-25',
        ]);
        $this->spin($olderPlayer, [
            'spin_type' => FortuneWheelSpin::TYPE_FREE,
            'reward_type' => FortuneWheelSegment::REWARD_ENTRY_PACK,
            'reward_duration_hours' => 24,
            'spun_for_date' => '2026-08-20',
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.games.fortune-wheel.dashboard', [
            'period' => 'week',
            'q' => 'Weekly Player',
            'reward_type' => FortuneWheelSegment::REWARD_SUBSCRIPTION,
        ]));

        $response->assertOk()
            ->assertSee('Entitlements Today')
            ->assertSee('Entitlements This Week')
            ->assertSee('Spin &amp; Entitlement Audit', false)
            ->assertSee('Weekly Player')
            ->assertDontSee('Older Player')
            ->assertViewHas('payload', function (array $payload): bool {
                return data_get($payload, 'entitlement_summary.today.total_entitlements') === 1
                    && data_get($payload, 'entitlement_summary.week.total_entitlements') === 2
                    && data_get($payload, 'entitlement_summary.week.entitlement_hours') === 192
                    && data_get($payload, 'spin_audit.summary.total_spins') === 1
                    && data_get($payload, 'spin_audit.summary.subscription_entitlements') === 1
                    && data_get($payload, 'spin_audit.spins')->total() === 1
                    && data_get($payload, 'spin_audit.daily')->count() === 1;
            });
    }

    public function test_dashboard_rejects_invalid_audit_date_range(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('admin.games.fortune-wheel.dashboard'))
            ->get(route('admin.games.fortune-wheel.dashboard', [
                'period' => 'custom',
                'date_from' => '2026-08-20',
                'date_to' => '2026-08-19',
            ]));

        $response->assertRedirect(route('admin.games.fortune-wheel.dashboard'))
            ->assertSessionHasErrors('date_to');
    }

    private function coinSegment(string $label, int $coins, int $weight = 1): FortuneWheelSegment
    {
        return FortuneWheelSegment::query()->create([
            'label' => $label,
            'reward_type' => FortuneWheelSegment::REWARD_COINS,
            'reward_value_coins' => $coins,
            'weight' => $weight,
            'color' => '#7C3AED',
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function entryPack(string $name, bool $active): EntryPack
    {
        return EntryPack::query()->create([
            'name' => $name,
            'price_coins' => 100,
            'svg_url' => 'https://cdn.example.com/entry.svg',
            'animation_style' => 'banner',
            'priority' => 1,
            'duration_ms' => 2000,
            'is_active' => $active,
            'sort_order' => 1,
        ]);
    }

    private function spin(User $user, array $attributes): FortuneWheelSpin
    {
        $attributes = array_merge([
            'user_id' => $user->id,
            'spin_type' => FortuneWheelSpin::TYPE_FREE,
            'spin_cost_coins' => 0,
            'reward_type' => FortuneWheelSegment::REWARD_COINS,
            'reward_value_coins' => 0,
            'idempotency_key' => 'admin-audit-'.$user->id.'-'.($attributes['spun_for_date'] ?? 'today'),
            'spun_for_date' => '2026-08-26',
        ], $attributes);

        if ($attributes['reward_type'] === FortuneWheelSegment::REWARD_ENTRY_PACK) {
            $pack = $this->entryPack('Audit Entry '.$user->id, true);
            $grant = UserEntryPack::query()->create([
                'user_id' => $user->id,
                'entry_pack_id' => $pack->id,
                'is_active' => true,
                'purchased_at' => now(),
                'expires_at' => now()->addHours((int) $attributes['reward_duration_hours']),
                'purchase_key' => 'admin-audit-'.$user->id,
                'source' => 'fortune_wheel',
                'charged' => false,
                'price_coins' => 0,
            ]);
            $attributes['entry_pack_id'] = $pack->id;
            $attributes['user_entry_pack_id'] = $grant->id;
        }

        if ($attributes['reward_type'] === FortuneWheelSegment::REWARD_SUBSCRIPTION) {
            $plan = SubscriptionPlan::query()->create([
                'name' => 'Audit Subscription '.$user->id,
                'price_coins' => 100,
                'duration_days' => 7,
                'is_active' => true,
            ]);
            $grant = UserSubscription::query()->create([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => now()->addHours((int) $attributes['reward_duration_hours']),
                'last_purchased_at' => now(),
                'meta' => ['source' => 'fortune_wheel'],
            ]);
            $attributes['subscription_plan_id'] = $plan->id;
            $attributes['user_subscription_id'] = $grant->id;
        }

        return FortuneWheelSpin::query()->create($attributes);
    }
}
