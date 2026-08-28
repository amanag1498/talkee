<?php

namespace Tests\Feature;

use App\Models\EntryPack;
use App\Models\FortuneWheelSegment;
use App\Models\User;
use App\Services\FortuneWheelService;
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
}
