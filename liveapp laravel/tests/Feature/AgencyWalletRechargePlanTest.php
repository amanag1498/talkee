<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyCoinTransfer;
use App\Models\AgencyWallet;
use App\Models\RechargePlan;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AgencyWalletRechargePlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_agency_can_only_choose_an_active_recharge_plan(): void
    {
        [$owner, $agency] = $this->agencyWithBalance(1_000);
        $activePlan = $this->plan('Agency Starter', 500, 100, true);
        $inactivePlan = $this->plan('Retired Pack', 250, 50, false);

        $response = $this->actingAs($owner)->get(route('agency.wallet.show'));

        $response->assertOk()
            ->assertSee('name="recharge_plan_id"', false)
            ->assertDontSee('name="coins"', false)
            ->assertSee($activePlan->title)
            ->assertDontSee($inactivePlan->title)
            ->assertDontSee('Bonus Coins')
            ->assertDontSee('User Received');
    }

    public function test_agency_recharge_deducts_base_coins_and_credits_the_user_total(): void
    {
        [$owner, $agency] = $this->agencyWithBalance(500);
        $target = User::factory()->create();
        $plan = $this->plan('Agency Boost', 500, 100);

        $this->actingAs($owner)
            ->post(route('agency.wallet.credit-user'), [
                'target_user_id' => $target->id,
                'recharge_plan_id' => $plan->id,
                'reference' => 'agency-recharge-001',
                'note' => 'Requested by customer',
            ])
            ->assertRedirect(route('agency.wallet.show'))
            ->assertSessionHas('ok');

        $this->assertSame(0, (int) AgencyWallet::query()->where('agency_id', $agency->id)->value('balance'));
        $this->assertSame(600, (int) Wallet::query()->where('user_id', $target->id)->value('balance'));

        $transfer = AgencyCoinTransfer::query()->firstOrFail();
        $this->assertSame($plan->id, $transfer->recharge_plan_id);
        $this->assertSame(500, $transfer->coins);
        $this->assertSame(100, $transfer->bonus_coins);
        $this->assertSame(600, $transfer->total_coins);

        $agencyTransaction = $transfer->agencyWalletTransaction()->firstOrFail();
        $this->assertSame(500, (int) $agencyTransaction->coins);
        $this->assertSame(500, (int) $agencyTransaction->balance_before);
        $this->assertSame(0, (int) $agencyTransaction->balance_after);

        $userTransaction = WalletTransaction::query()->findOrFail($transfer->user_wallet_transaction_id);
        $this->assertSame(600, (int) $userTransaction->coins);
        $this->assertSame('agency_credit', $userTransaction->category);
        $this->assertSame(500, (int) $userTransaction->meta['base_coins']);
        $this->assertSame(100, (int) $userTransaction->meta['bonus_coins']);
        $this->assertSame(600, (int) $userTransaction->meta['total_coins']);
    }

    public function test_arbitrary_coin_amounts_and_inactive_plans_are_rejected(): void
    {
        [$owner, $agency] = $this->agencyWithBalance(1_000);
        $target = User::factory()->create();
        $inactivePlan = $this->plan('Inactive Agency Pack', 400, 80, false);

        $this->actingAs($owner)
            ->post(route('agency.wallet.credit-user'), [
                'target_user_id' => $target->id,
                'coins' => 1,
            ])
            ->assertSessionHasErrors('recharge_plan_id');

        $this->actingAs($owner)
            ->post(route('agency.wallet.credit-user'), [
                'target_user_id' => $target->id,
                'recharge_plan_id' => $inactivePlan->id,
            ])
            ->assertSessionHas('err', 'Recharge plan is unavailable.');

        $this->assertSame(1_000, (int) AgencyWallet::query()->where('agency_id', $agency->id)->value('balance'));
        $this->assertSame(0, (int) Wallet::query()->where('user_id', $target->id)->value('balance'));
        $this->assertDatabaseCount('agency_coin_transfers', 0);
    }

    public function test_bonus_total_is_aggregated_on_the_admin_dashboard_only(): void
    {
        [$owner, $agency] = $this->agencyWithBalance(1_000);
        $target = User::factory()->create();
        $plan = $this->plan('Dashboard Pack', 400, 75);

        $this->actingAs($owner)->post(route('agency.wallet.credit-user'), [
            'target_user_id' => $target->id,
            'recharge_plan_id' => $plan->id,
        ])->assertSessionHas('ok');

        $this->actingAs($owner)
            ->get(route('agency.wallet.show'))
            ->assertOk()
            ->assertDontSee('Agency Recharge Bonuses')
            ->assertDontSee('Bonus Coins');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.agencies.wallet.show', $agency))
            ->assertOk()
            ->assertSee('Bonus Coins')
            ->assertSee('User Received');

        $report = $this->actingAs($admin)->get(route('admin.reports.agency-wallets.index'));
        $report->assertOk()
            ->assertSee('Bonus Coins Credited');
        $this->assertSame(75, (int) $report->viewData('summary')['total_bonus_credited']);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk()
            ->assertSee('Agency Recharge Bonuses');
        $this->assertSame(75, (int) $response->viewData('stats')['agencyBonusCoinsIssued']);
    }

    private function agencyWithBalance(int $balance): array
    {
        $owner = User::factory()->create();
        $owner->assignRole('agency');
        $agency = Agency::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Orbit Agency',
        ]);
        AgencyWallet::query()->create([
            'agency_id' => $agency->id,
            'balance' => $balance,
        ]);

        return [$owner, $agency];
    }

    private function plan(
        string $title,
        int $baseCoins,
        int $bonusCoins,
        bool $active = true,
    ): RechargePlan {
        return RechargePlan::query()->create([
            'title' => $title,
            'amount_rupees' => $baseCoins / 10,
            'coins' => $baseCoins,
            'bonus_coins' => $bonusCoins,
            'total_coins' => $baseCoins + $bonusCoins,
            'is_active' => $active,
            'sort_order' => 1,
        ]);
    }
}
