<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\WalletTransaction;
use App\Services\SubscriptionService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUserSubscriptionAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_renewal_keeps_one_entitlement_and_creates_two_auditable_sales(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $buyer = User::factory()->create();
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Gold',
            'price_coins' => 1200,
            'duration_days' => 30,
            'is_active' => true,
        ]);

        WalletService::credit($buyer, 5000, 'TEST_CREDIT');
        $service = app(SubscriptionService::class);
        $first = $service->purchase($buyer, $plan);
        $renewed = $service->purchase($buyer, $plan);

        $this->assertSame($first->id, $renewed->id);
        $this->assertSame(1, UserSubscription::query()->count());

        $sales = WalletTransaction::query()->where('category', 'subscription')->orderBy('id')->get();
        $this->assertCount(2, $sales);
        $this->assertSame(['purchase', 'renewal'], $sales->pluck('meta.purchase_kind')->all());
        $this->assertSame([$first->id, $first->id], $sales->pluck('meta.subscription_id')->all());
        $this->assertSame(2400, (int) $sales->sum('coins'));

        $this->actingAs($admin)
            ->get(route('admin.user-subscriptions.index'))
            ->assertOk()
            ->assertViewHas('salesSummary', function (array $summary): bool {
                return $summary['sold'] === 2
                    && $summary['coins'] === 2400
                    && $summary['buyers'] === 1
                    && $summary['renewals'] === 1
                    && $summary['renewal_rate'] === 50.0;
            })
            ->assertViewHas('summary', fn (array $summary): bool => $summary['active'] === 1 && $summary['complimentary_active'] === 0)
            ->assertSee('Subscription Sales Audit')
            ->assertSee('Txn #'.$sales->last()->id);
    }

    public function test_sales_audit_can_infer_and_filter_legacy_renewals(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $buyer = User::factory()->create();
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Silver',
            'price_coins' => 600,
            'duration_days' => 30,
            'is_active' => true,
        ]);

        WalletService::credit($buyer, 2000, 'TEST_CREDIT');
        app(SubscriptionService::class)->purchase($buyer, $plan);
        app(SubscriptionService::class)->purchase($buyer, $plan);

        WalletTransaction::query()->where('category', 'subscription')->get()->each(function (WalletTransaction $transaction): void {
            $meta = $transaction->meta ?? [];
            unset($meta['purchase_kind']);
            $transaction->update(['meta' => $meta]);
        });

        $this->actingAs($admin)
            ->get(route('admin.user-subscriptions.index', [
                'sale_kind' => 'renewal',
                'sale_plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertViewHas('sales', fn ($sales): bool => $sales->total() === 1)
            ->assertViewHas('salesSummary', fn (array $summary): bool => $summary['sold'] === 1 && $summary['coins'] === 600);
    }
}
