<?php

namespace Tests\Feature;

use Database\Seeders\RechargePlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RechargePlanApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_recharge_plans_api_returns_active_sorted_plans(): void
    {
        $this->seed(RechargePlanSeeder::class);

        $response = $this->getJson('/api/recharge/plans')
            ->assertOk()
            ->assertJsonPath('data.0.amount_rupees', 49)
            ->assertJsonPath('data.0.total_coins', 500)
            ->assertJsonPath('data.4.amount_rupees', 999)
            ->assertJsonPath('data.4.total_coins', 13000);

        $this->assertCount(5, $response->json('data'));
    }

    public function test_recharge_plan_seeder_is_idempotent(): void
    {
        $this->seed(RechargePlanSeeder::class);
        $this->seed(RechargePlanSeeder::class);

        $this->assertDatabaseCount('recharge_plans', 5);
    }
}
