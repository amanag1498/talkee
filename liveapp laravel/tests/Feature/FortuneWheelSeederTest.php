<?php

namespace Tests\Feature;

use App\Models\EntryPack;
use App\Models\FortuneWheelSegment;
use App\Models\SubscriptionPlan;
use Database\Seeders\CommonSeeder;
use Database\Seeders\FortuneWheelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FortuneWheelSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_common_seeder_creates_idempotent_fortune_wheel_segments(): void
    {
        $this->seed(CommonSeeder::class);
        $firstCount = FortuneWheelSegment::query()->count();

        $this->seed(CommonSeeder::class);

        $this->assertSame($firstCount, FortuneWheelSegment::query()->count());
        $this->assertDatabaseHas('fortune_wheel_segments', [
            'label' => '0 Coins',
            'reward_type' => FortuneWheelSegment::REWARD_COINS,
            'reward_value_coins' => 0,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('fortune_wheel_segments', [
            'label' => '10 Coins',
            'reward_type' => FortuneWheelSegment::REWARD_COINS,
            'reward_value_coins' => 10,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('fortune_wheel_segments', [
            'label' => '5 Coins',
            'reward_type' => FortuneWheelSegment::REWARD_COINS,
            'reward_value_coins' => 5,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('fortune_wheel_segments', [
            'label' => '75 Coins',
            'reward_type' => FortuneWheelSegment::REWARD_COINS,
            'reward_value_coins' => 75,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('fortune_wheel_segments', [
            'label' => '200 Coins',
            'reward_type' => FortuneWheelSegment::REWARD_COINS,
            'reward_value_coins' => 200,
            'is_active' => true,
        ]);
        $this->assertTrue(
            FortuneWheelSegment::query()
                ->whereIn('reward_type', [
                    FortuneWheelSegment::REWARD_ENTRY_PACK,
                    FortuneWheelSegment::REWARD_SUBSCRIPTION,
                ])
                ->exists(),
        );
        $this->assertDatabaseHas('entry_packs', [
            'name' => 'Royal Entry',
            'price_coins' => 500,
            'duration_days' => 30,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('subscription_plans', [
            'name' => 'Bronze',
            'price_coins' => 500,
            'duration_days' => 30,
            'is_active' => true,
        ]);
    }

    public function test_fortune_wheel_seeder_directly_creates_catalog_backed_rewards(): void
    {
        $this->seedProductionCatalogFixtures();
        $this->seed(FortuneWheelSeeder::class);

        $this->assertDatabaseHas('fortune_wheel_segments', [
            'label' => 'CAR 1 Day',
            'reward_type' => FortuneWheelSegment::REWARD_ENTRY_PACK,
            'reward_duration_hours' => 24,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('fortune_wheel_segments', [
            'label' => 'Leopard 1 Day',
            'reward_type' => FortuneWheelSegment::REWARD_ENTRY_PACK,
            'reward_duration_hours' => 24,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('fortune_wheel_segments', [
            'label' => 'Bronze 1 Day',
            'reward_type' => FortuneWheelSegment::REWARD_SUBSCRIPTION,
            'reward_duration_hours' => 24,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('fortune_wheel_segments', [
            'label' => 'Platinum 1 Day',
            'reward_type' => FortuneWheelSegment::REWARD_SUBSCRIPTION,
            'reward_duration_hours' => 24,
            'is_active' => true,
        ]);
        $this->assertSame(7, FortuneWheelSegment::query()->where('reward_type', FortuneWheelSegment::REWARD_ENTRY_PACK)->count());
        $this->assertSame(5, FortuneWheelSegment::query()->where('reward_type', FortuneWheelSegment::REWARD_SUBSCRIPTION)->count());
    }

    public function test_fortune_wheel_seeder_does_not_overwrite_production_catalog_values(): void
    {
        $this->seedProductionCatalogFixtures();

        $this->seed(FortuneWheelSeeder::class);

        $this->assertDatabaseHas('entry_packs', [
            'name' => 'Leopard',
            'price_coins' => 7000,
            'duration_days' => 7,
        ]);
        $this->assertDatabaseHas('subscription_plans', [
            'name' => 'Bronze',
            'price_coins' => 750,
            'duration_days' => 3,
        ]);
        $this->assertDatabaseHas('subscription_plans', [
            'name' => 'Gold',
            'price_coins' => 2250,
            'duration_days' => 15,
        ]);
    }

    private function seedProductionCatalogFixtures(): void
    {
        $entryPacks = [
            ['name' => 'CAR', 'price_coins' => 3000, 'sort_order' => 1],
            ['name' => 'CAR 2', 'price_coins' => 3000, 'sort_order' => 2],
            ['name' => 'CAR 3', 'price_coins' => 4000, 'sort_order' => 3],
            ['name' => 'Royal Entry', 'price_coins' => 500, 'sort_order' => 3],
            ['name' => 'SPACESHIP', 'price_coins' => 3000, 'sort_order' => 4],
            ['name' => 'DRAGON', 'price_coins' => 6000, 'sort_order' => 5],
            ['name' => 'Leopard', 'price_coins' => 7000, 'sort_order' => 6],
        ];

        foreach ($entryPacks as $pack) {
            EntryPack::query()->create(array_merge($pack, [
                'svg_url' => 'https://cdn.example.com/entry-pack/test.svga',
                'animation_style' => 'fullscreen',
                'priority' => $pack['sort_order'],
                'duration_ms' => 6000,
                'duration_days' => 7,
                'is_active' => true,
            ]));
        }

        foreach ([
            ['name' => 'Base', 'price_coins' => 300, 'duration_days' => 1],
            ['name' => 'Bronze', 'price_coins' => 750, 'duration_days' => 3],
            ['name' => 'Silver', 'price_coins' => 1400, 'duration_days' => 7],
            ['name' => 'Gold', 'price_coins' => 2250, 'duration_days' => 15],
            ['name' => 'Platinum', 'price_coins' => 3000, 'duration_days' => 30],
        ] as $plan) {
            SubscriptionPlan::query()->create(array_merge($plan, ['is_active' => true]));
        }
    }
}
