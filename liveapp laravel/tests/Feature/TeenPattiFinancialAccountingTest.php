<?php

namespace Tests\Feature;

use App\Models\TeenPattiFinancialAccount;
use App\Models\TeenPattiFinancialLedgerEntry;
use App\Models\TeenPattiRound;
use App\Models\User;
use App\Models\Wallet;
use App\Services\TeenPattiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeenPattiFinancialAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('games.teen_patti.enabled', true);
        config()->set('app_features.platform.android.teen_patti_enabled', true);
        config()->set('games.teen_patti.min_bet', 10);
        config()->set('games.teen_patti.max_bet', 5000);
        config()->set('games.teen_patti.payout_multiplier', 3);
        config()->set('games.teen_patti.winning_strategy_mode', 'highest_bet');
    }

    public function test_bet_allocation_and_payout_are_recorded_without_changing_existing_game_flow(): void
    {
        $user = User::factory()->create();
        Wallet::query()->where('user_id', $user->id)->update(['balance' => 1000]);
        $round = $this->openRound();

        $service = app(TeenPattiService::class);
        $service->placeBet($user, 'A', 100, 'tp-financial-test');

        $account = TeenPattiFinancialAccount::query()->where('game_key', 'teen_patti')->firstOrFail();
        $this->assertSame(95, (int) $account->treasury_balance_coins);
        $this->assertSame(5, (int) $account->company_commission_balance_coins);

        $round->forceFill([
            'locks_at' => now()->subSeconds(2),
            'ends_at' => now()->subSecond(),
        ])->save();
        $service->settleRound($round->fresh());

        $account->refresh();
        $this->assertSame(-205, (int) $account->treasury_balance_coins);
        $this->assertSame(5, (int) $account->company_commission_balance_coins);
        $this->assertSame(1200, (int) Wallet::query()->where('user_id', $user->id)->value('balance'));
        $this->assertSame(1, TeenPattiFinancialLedgerEntry::query()->where('event_type', 'bet_allocation')->count());
        $this->assertSame(1, TeenPattiFinancialLedgerEntry::query()->where('event_type', 'payout_debit')->count());
    }

    public function test_fractional_commission_rounds_up_to_a_whole_coin_and_refund_reverses_both_ledgers(): void
    {
        $user = User::factory()->create();
        Wallet::query()->where('user_id', $user->id)->update(['balance' => 1000]);
        $this->openRound();

        $service = app(TeenPattiService::class);
        $snapshot = $service->placeBet($user, 'B', 50, 'tp-financial-refund');
        $bet = collect($snapshot['round']['viewer_bets'])->firstWhere('pot', 'B');

        $account = TeenPattiFinancialAccount::query()->where('game_key', 'teen_patti')->firstOrFail();
        $this->assertSame(47, (int) $account->treasury_balance_coins);
        $this->assertSame(3, (int) $account->company_commission_balance_coins);

        $service->refundBet(\App\Models\TeenPattiBet::query()->findOrFail((int) $bet['id']), 'test reversal');

        $account->refresh();
        $this->assertSame(0, (int) $account->treasury_balance_coins);
        $this->assertSame(0, (int) $account->company_commission_balance_coins);
        $this->assertSame(1000, (int) Wallet::query()->where('user_id', $user->id)->value('balance'));
        $this->assertSame(1, TeenPattiFinancialLedgerEntry::query()->where('event_type', 'bet_refund_reversal')->count());
    }

    public function test_retrying_the_same_bet_does_not_duplicate_the_financial_allocation(): void
    {
        $user = User::factory()->create();
        Wallet::query()->where('user_id', $user->id)->update(['balance' => 1000]);
        $this->openRound();

        $service = app(TeenPattiService::class);
        $service->placeBet($user, 'C', 200, 'tp-financial-idempotent');
        $service->placeBet($user, 'C', 200, 'tp-financial-idempotent');

        $account = TeenPattiFinancialAccount::query()->where('game_key', 'teen_patti')->firstOrFail();
        $this->assertSame(190, (int) $account->treasury_balance_coins);
        $this->assertSame(10, (int) $account->company_commission_balance_coins);
        $this->assertSame(1, TeenPattiFinancialLedgerEntry::query()->where('event_type', 'bet_allocation')->count());
        $this->assertSame(800, (int) Wallet::query()->where('user_id', $user->id)->value('balance'));
    }

    public function test_treasury_affordable_strategy_excludes_pots_that_would_overdraw_treasury(): void
    {
        config()->set('games.teen_patti.winning_strategy_mode', 'treasury_affordable');

        $users = User::factory()->count(3)->create();
        foreach ($users as $user) {
            Wallet::query()->where('user_id', $user->id)->update(['balance' => 1000]);
        }

        TeenPattiFinancialAccount::query()
            ->where('game_key', 'teen_patti')
            ->update(['treasury_balance_coins' => 250]);

        $round = $this->openRound();
        $service = app(TeenPattiService::class);
        $service->placeBet($users[0], 'A', 200, 'tp-affordable-a');
        $service->placeBet($users[1], 'B', 100, 'tp-affordable-b');
        $service->placeBet($users[2], 'C', 50, 'tp-affordable-c');

        $round->forceFill([
            'locks_at' => now()->subSeconds(2),
            'ends_at' => now()->subSecond(),
        ])->save();

        $settled = $service->settleRound($round->fresh());

        $this->assertSame('settled', $settled->status);
        $this->assertContains($settled->winning_pot, ['B', 'C']);
        $this->assertSame(['B', 'C'], $settled->meta['winning_decision']['eligible_pots']);
        $this->assertSame(582, $settled->meta['winning_decision']['treasury_balance_before_settlement']);
        $this->assertSame(['A' => 600, 'B' => 300, 'C' => 150], $settled->meta['winning_decision']['pot_payouts']);
    }

    public function test_treasury_affordable_strategy_overdrafts_once_to_minimum_bet_pot_when_all_pots_are_unaffordable(): void
    {
        config()->set('games.teen_patti.winning_strategy_mode', 'treasury_affordable');

        $users = User::factory()->count(3)->create();
        foreach ($users as $user) {
            Wallet::query()->where('user_id', $user->id)->update(['balance' => 1000]);
        }

        $round = $this->openRound();
        $service = app(TeenPattiService::class);
        $service->placeBet($users[0], 'A', 100, 'tp-no-affordable-a');
        $service->placeBet($users[1], 'B', 100, 'tp-no-affordable-b');
        $service->placeBet($users[2], 'C', 100, 'tp-no-affordable-c');

        $round->forceFill([
            'locks_at' => now()->subSeconds(2),
            'ends_at' => now()->subSecond(),
        ])->save();

        $settled = $service->settleRound($round->fresh());
        $account = TeenPattiFinancialAccount::query()->where('game_key', 'teen_patti')->firstOrFail();

        $this->assertSame('settled', $settled->status);
        $this->assertSame('A', $settled->winning_pot);
        $this->assertSame('treasury_overdraft_minimum_bet', $settled->meta['winning_decision']['reason']);
        $this->assertSame(-15, (int) $account->treasury_balance_coins);
        $this->assertSame(15, (int) $account->company_commission_balance_coins);
        $this->assertSame(1, TeenPattiFinancialLedgerEntry::query()->where('event_type', 'payout_debit')->count());
        $this->assertSame(1200, (int) Wallet::query()->where('user_id', $users[0]->id)->value('balance'));
        $this->assertSame(900, (int) Wallet::query()->where('user_id', $users[1]->id)->value('balance'));
        $this->assertSame(900, (int) Wallet::query()->where('user_id', $users[2]->id)->value('balance'));
    }

    public function test_treasury_affordable_strategy_uses_minimum_bet_while_treasury_is_in_recovery(): void
    {
        config()->set('games.teen_patti.winning_strategy_mode', 'treasury_affordable');

        $users = User::factory()->count(3)->create();
        foreach ($users as $user) {
            Wallet::query()->where('user_id', $user->id)->update(['balance' => 1000]);
        }

        $round = $this->openRound();
        $service = app(TeenPattiService::class);
        $service->placeBet($users[0], 'A', 100, 'tp-recovery-a');
        $service->placeBet($users[1], 'B', 200, 'tp-recovery-b');
        $service->placeBet($users[2], 'C', 300, 'tp-recovery-c');

        TeenPattiFinancialAccount::query()
            ->where('game_key', 'teen_patti')
            ->update(['treasury_balance_coins' => -10]);

        $round->forceFill([
            'locks_at' => now()->subSeconds(2),
            'ends_at' => now()->subSecond(),
        ])->save();

        $settled = $service->settleRound($round->fresh());
        $account = TeenPattiFinancialAccount::query()->where('game_key', 'teen_patti')->firstOrFail();

        $this->assertSame('settled', $settled->status);
        $this->assertSame('A', $settled->winning_pot);
        $this->assertSame('treasury_recovery_minimum_bet', $settled->meta['winning_decision']['reason']);
        $this->assertSame(-310, (int) $account->treasury_balance_coins);
    }

    private function openRound(): TeenPattiRound
    {
        return TeenPattiRound::query()->create([
            'round_key' => 'tpr_'.str()->lower(str()->ulid()),
            'status' => 'open',
            'starts_at' => now()->subSecond(),
            'locks_at' => now()->addMinute(),
            'ends_at' => now()->addSeconds(65),
            'meta' => [],
        ]);
    }
}
