<?php

namespace Tests\Feature;

use App\Models\RechargePlan;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RechargePlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RechargeOrderApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->seed(RechargePlanSeeder::class);
    }

    public function test_recharge_order_can_be_created(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $plan = RechargePlan::query()->firstOrFail();

        $this->postJson('/api/recharge/orders', ['plan_id' => $plan->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.recharge_plan_id', $plan->id);

        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $user->id,
            'recharge_plan_id' => $plan->id,
            'status' => 'pending',
        ]);
    }

    public function test_verify_success_credits_wallet_once(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 100]);
        Sanctum::actingAs($user);

        $plan = RechargePlan::query()->firstOrFail();
        $orderId = $this->postJson('/api/recharge/orders', ['plan_id' => $plan->id])->json('data.order_id');

        $response = $this->postJson("/api/recharge/orders/{$orderId}/verify", [
            'result' => 'success',
            'gateway_payment_id' => 'mock_txn_1',
            'gateway_response' => ['approved' => true],
        ])->assertOk();

        $this->assertSame(600, $response->json('data.wallet_balance'));
        $this->assertDatabaseHas('wallet_transactions', [
            'category' => 'recharge',
            'reference_type' => 'payment_order',
            'transaction_id' => 'mock_txn_1',
            'coins' => 500,
            'balance_before' => 100,
            'balance_after' => 600,
        ]);
    }

    public function test_verify_is_idempotent_and_does_not_double_credit(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 0]);
        Sanctum::actingAs($user);

        $plan = RechargePlan::query()->firstOrFail();
        $orderId = $this->postJson('/api/recharge/orders', ['plan_id' => $plan->id])->json('data.order_id');

        $this->postJson("/api/recharge/orders/{$orderId}/verify", ['result' => 'success'])->assertOk();
        $this->postJson("/api/recharge/orders/{$orderId}/verify", ['result' => 'success'])->assertOk();

        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertSame(500, Wallet::query()->where('user_id', $user->id)->value('balance'));
    }

    public function test_failed_verify_does_not_credit_wallet(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 50]);
        Sanctum::actingAs($user);

        $plan = RechargePlan::query()->firstOrFail();
        $orderId = $this->postJson('/api/recharge/orders', ['plan_id' => $plan->id])->json('data.order_id');

        $this->postJson("/api/recharge/orders/{$orderId}/verify", ['result' => 'failed'])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('data.order.status', 'failed');

        $this->assertDatabaseMissing('wallet_transactions', [
            'category' => 'recharge',
        ]);
        $this->assertSame(50, Wallet::query()->where('user_id', $user->id)->value('balance'));
    }

    public function test_wallet_transactions_endpoint_includes_recharge_entries_and_filters(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $plan = RechargePlan::query()->firstOrFail();
        $orderId = $this->postJson('/api/recharge/orders', ['plan_id' => $plan->id])->json('data.order_id');
        $this->postJson("/api/recharge/orders/{$orderId}/verify", ['result' => 'success'])->assertOk();

        $this->getJson('/api/wallet/transactions?filter=recharge')
            ->assertOk()
            ->assertJsonPath('data.transactions.0.category', 'recharge');
    }
}
