<?php

namespace Tests\Feature;

use App\Models\RechargePlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminRechargePlanCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_admin_can_create_recharge_plan(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('admin.recharge-plans.store'), [
            'title' => 'Starter Pack',
            'amount_rupees' => '99',
            'coins' => 1000,
            'bonus_coins' => 100,
            'sort_order' => 1,
            'is_active' => 1,
        ]);

        $response
            ->assertRedirect(route('admin.recharge-plans.index'))
            ->assertSessionHas('ok');

        $this->assertDatabaseHas('recharge_plans', [
            'title' => 'Starter Pack',
            'coins' => 1000,
            'bonus_coins' => 100,
            'total_coins' => 1100,
            'sort_order' => 1,
            'is_active' => 1,
        ]);
    }

    public function test_admin_can_update_and_delete_recharge_plan(): void
    {
        $admin = $this->admin();
        $plan = RechargePlan::query()->create([
            'title' => 'Starter Pack',
            'amount_rupees' => 99,
            'coins' => 1000,
            'bonus_coins' => 100,
            'total_coins' => 1100,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->put(route('admin.recharge-plans.update', $plan), [
            'title' => 'Updated Pack',
            'amount_rupees' => '149',
            'coins' => 1500,
            'bonus_coins' => 250,
            'sort_order' => 2,
            'is_active' => 0,
        ])->assertRedirect(route('admin.recharge-plans.index'))
          ->assertSessionHas('ok');

        $this->assertDatabaseHas('recharge_plans', [
            'id' => $plan->id,
            'title' => 'Updated Pack',
            'coins' => 1500,
            'bonus_coins' => 250,
            'total_coins' => 1750,
            'sort_order' => 2,
            'is_active' => 0,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.recharge-plans.destroy', $plan))
            ->assertRedirect(route('admin.recharge-plans.index'))
            ->assertSessionHas('ok');

        $this->assertDatabaseMissing('recharge_plans', [
            'id' => $plan->id,
        ]);
    }
}
