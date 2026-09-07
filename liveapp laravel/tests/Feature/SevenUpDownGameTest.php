<?php

namespace Tests\Feature;

use App\Models\SevenUpDownBet;
use App\Models\SevenUpDownFinancialAccount;
use App\Models\SevenUpDownFinancialLedgerEntry;
use App\Models\SevenUpDownPayout;
use App\Models\SevenUpDownRound;
use App\Models\User;
use App\Models\UserGameAccess;
use App\Models\Wallet;
use App\Services\SevenUpDownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SevenUpDownGameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('games.seven_up_down.enabled', true);
        config()->set('app_features.platform.android.seven_up_down_enabled', true);
        config()->set('games.seven_up_down.min_bet', 10);
        config()->set('games.seven_up_down.max_bet', 5000);
        config()->set('games.seven_up_down.multiplier_down', 3);
        config()->set('games.seven_up_down.multiplier_seven', 4);
        config()->set('games.seven_up_down.multiplier_up', 3);
        config()->set('games.seven_up_down.winning_strategy_mode', 'highest_bet');
        Role::findOrCreate('admin', 'web');
    }

    public function test_retried_bet_is_debited_and_allocated_only_once(): void
    {
        $user = $this->fundedUser();
        $this->openRound();
        $service = app(SevenUpDownService::class);

        $first = $service->placeBet($user, 'DOWN', 100, 'sud-idempotent');
        $second = $service->placeBet($user, 'DOWN', 100, 'sud-idempotent');

        $this->assertFalse($first['already_processed']);
        $this->assertTrue($second['already_processed']);
        $this->assertSame(900, (int) Wallet::query()->where('user_id', $user->id)->value('balance'));
        $this->assertSame(1, SevenUpDownBet::query()->count());
        $this->assertSame(1, SevenUpDownFinancialLedgerEntry::query()->where('event_type', 'bet_allocation')->count());
    }

    public function test_settlement_persists_matching_dice_and_credits_snapshotted_multiplier_once(): void
    {
        $user = $this->fundedUser();
        $round = $this->openRound();
        $service = app(SevenUpDownService::class);
        $service->placeBet($user, 'SEVEN', 100, 'sud-seven');

        config()->set('games.seven_up_down.multiplier_seven', 9);
        $round->forceFill(['locks_at' => now()->subSeconds(2), 'ends_at' => now()->subSecond()])->save();
        $settled = $service->settleRound($round->fresh());
        $service->settleRound($settled->fresh());

        $this->assertSame('SEVEN', $settled->winning_pot);
        $this->assertSame(7, (int) $settled->dice_total);
        $this->assertSame((int) $settled->dice_total, (int) $settled->dice_one + (int) $settled->dice_two);
        $this->assertSame(4, (int) $settled->winning_multiplier);
        $this->assertSame(1300, (int) Wallet::query()->where('user_id', $user->id)->value('balance'));
        $this->assertSame(1, SevenUpDownPayout::query()->count());
        $this->assertSame(1, SevenUpDownFinancialLedgerEntry::query()->where('event_type', 'payout_debit')->count());
    }

    public function test_every_settled_dice_result_matches_its_winning_pot(): void
    {
        foreach (['DOWN', 'SEVEN', 'UP'] as $pot) {
            $user = $this->fundedUser();
            $round = $this->openRound();
            $service = app(SevenUpDownService::class);
            $service->placeBet($user, $pot, 100, 'sud-'.$pot);
            $round->forceFill(['locks_at' => now()->subSeconds(2), 'ends_at' => now()->subSecond()])->save();
            $settled = $service->settleRound($round->fresh());

            $this->assertSame($pot, $settled->winning_pot);
            $this->assertContains((int) $settled->dice_one, range(1, 6));
            $this->assertContains((int) $settled->dice_two, range(1, 6));
            $this->assertTrue(match ($pot) {
                'DOWN' => (int) $settled->dice_total < 7,
                'SEVEN' => (int) $settled->dice_total === 7,
                'UP' => (int) $settled->dice_total > 7,
            });
        }
    }

    public function test_refund_restores_wallet_and_reverses_financial_allocation(): void
    {
        $user = $this->fundedUser();
        $this->openRound();
        $service = app(SevenUpDownService::class);
        $result = $service->placeBet($user, 'UP', 50, 'sud-refund');

        $service->refundBet(SevenUpDownBet::query()->findOrFail($result['bet']['id']), 'test');

        $account = SevenUpDownFinancialAccount::query()->where('game_key', 'seven_up_down')->firstOrFail();
        $this->assertSame(1000, (int) Wallet::query()->where('user_id', $user->id)->value('balance'));
        $this->assertSame(0, (int) $account->treasury_balance_coins);
        $this->assertSame(0, (int) $account->company_commission_balance_coins);
        $this->assertSame(1, SevenUpDownFinancialLedgerEntry::query()->where('event_type', 'bet_refund_reversal')->count());
    }

    public function test_treasury_affordable_single_occupied_pot_uses_teen_patti_seventy_five_percent_flow(): void
    {
        config()->set('games.seven_up_down.winning_strategy_mode', 'treasury_affordable');
        SevenUpDownFinancialAccount::query()
            ->where('game_key', 'seven_up_down')
            ->update(['treasury_balance_coins' => 1000]);

        $user = $this->fundedUser();
        $round = $this->openRound();
        $service = app(SevenUpDownService::class);
        $service->placeBet($user, 'DOWN', 100, 'sud-single-affordable');

        $round->forceFill(['locks_at' => now()->subSeconds(2), 'ends_at' => now()->subSecond()])->save();
        $settled = $service->settleRound($round->fresh());
        $decision = $settled->meta['winning_decision'];

        $this->assertSame(['DOWN'], $decision['eligible_pots']);
        $this->assertSame(75, $decision['single_pot_win_probability_percent']);
        $this->assertGreaterThanOrEqual(1, (int) $decision['single_pot_roll']);
        $this->assertLessThanOrEqual(100, (int) $decision['single_pot_roll']);

        if ((int) $decision['single_pot_roll'] <= 75) {
            $this->assertSame('DOWN', $settled->winning_pot);
            $this->assertArrayNotHasKey('reason', $decision);
        } else {
            $this->assertContains($settled->winning_pot, ['SEVEN', 'UP']);
            $this->assertSame('single_pot_probability_miss', $decision['reason']);
        }
    }

    public function test_treasury_affordable_randomly_selects_only_between_affordable_occupied_pots(): void
    {
        config()->set('games.seven_up_down.winning_strategy_mode', 'treasury_affordable');
        SevenUpDownFinancialAccount::query()
            ->where('game_key', 'seven_up_down')
            ->update(['treasury_balance_coins' => 1000]);

        $downUser = $this->fundedUser();
        $sevenUser = $this->fundedUser();
        $round = $this->openRound();
        $service = app(SevenUpDownService::class);
        $service->placeBet($downUser, 'DOWN', 100, 'sud-two-affordable-down');
        $service->placeBet($sevenUser, 'SEVEN', 100, 'sud-two-affordable-seven');

        $round->forceFill(['locks_at' => now()->subSeconds(2), 'ends_at' => now()->subSecond()])->save();
        $settled = $service->settleRound($round->fresh());
        $decision = $settled->meta['winning_decision'];

        $this->assertSame(['DOWN', 'SEVEN'], $decision['eligible_pots']);
        $this->assertContains($settled->winning_pot, ['DOWN', 'SEVEN']);
        $this->assertNotSame('UP', $settled->winning_pot);
        $this->assertArrayNotHasKey('single_pot_win_probability_percent', $decision);
    }

    public function test_treasury_affordable_uses_empty_pot_when_no_occupied_pot_is_affordable(): void
    {
        config()->set('games.seven_up_down.winning_strategy_mode', 'treasury_affordable');

        $downUser = $this->fundedUser();
        $sevenUser = $this->fundedUser();
        $round = $this->openRound();
        $service = app(SevenUpDownService::class);
        $service->placeBet($downUser, 'DOWN', 100, 'sud-none-affordable-down');
        $service->placeBet($sevenUser, 'SEVEN', 100, 'sud-none-affordable-seven');
        SevenUpDownFinancialAccount::query()
            ->where('game_key', 'seven_up_down')
            ->update(['treasury_balance_coins' => 1]);

        $round->forceFill(['locks_at' => now()->subSeconds(2), 'ends_at' => now()->subSecond()])->save();
        $settled = $service->settleRound($round->fresh());

        $this->assertSame('UP', $settled->winning_pot);
        $this->assertSame([], $settled->meta['winning_decision']['eligible_pots']);
        $this->assertSame('no_eligible_pot', $settled->meta['winning_decision']['reason']);
    }

    public function test_treasury_affordable_uses_minimum_real_bet_when_all_pots_are_unaffordable(): void
    {
        config()->set('games.seven_up_down.winning_strategy_mode', 'treasury_affordable');

        $downUser = $this->fundedUser();
        $sevenUser = $this->fundedUser();
        $upUser = $this->fundedUser();
        $round = $this->openRound();
        $service = app(SevenUpDownService::class);
        $service->placeBet($downUser, 'DOWN', 50, 'sud-overdraft-down');
        $service->placeBet($sevenUser, 'SEVEN', 100, 'sud-overdraft-seven');
        $service->placeBet($upUser, 'UP', 150, 'sud-overdraft-up');
        SevenUpDownFinancialAccount::query()
            ->where('game_key', 'seven_up_down')
            ->update(['treasury_balance_coins' => 1]);

        $round->forceFill(['locks_at' => now()->subSeconds(2), 'ends_at' => now()->subSecond()])->save();
        $settled = $service->settleRound($round->fresh());

        $this->assertSame('DOWN', $settled->winning_pot);
        $this->assertSame('treasury_overdraft_minimum_bet', $settled->meta['winning_decision']['reason']);
    }

    public function test_treasury_affordable_uses_minimum_real_bet_while_treasury_is_in_recovery(): void
    {
        config()->set('games.seven_up_down.winning_strategy_mode', 'treasury_affordable');

        $downUser = $this->fundedUser();
        $sevenUser = $this->fundedUser();
        $round = $this->openRound();
        $service = app(SevenUpDownService::class);
        $service->placeBet($downUser, 'DOWN', 50, 'sud-recovery-down');
        $service->placeBet($sevenUser, 'SEVEN', 100, 'sud-recovery-seven');
        SevenUpDownFinancialAccount::query()
            ->where('game_key', 'seven_up_down')
            ->update(['treasury_balance_coins' => -1]);

        $round->forceFill(['locks_at' => now()->subSeconds(2), 'ends_at' => now()->subSecond()])->save();
        $settled = $service->settleRound($round->fresh());

        $this->assertSame('DOWN', $settled->winning_pot);
        $this->assertSame('treasury_recovery_minimum_bet', $settled->meta['winning_decision']['reason']);
    }

    public function test_admin_can_open_game_dashboard_and_settings_surface(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.games.seven-up-down.dashboard'))
            ->assertOk()
            ->assertSee('Lucky 7')
            ->assertSee('Financial Audit Ledger');

        $this->actingAs($admin)
            ->get(route('admin.settings.games.edit', ['game' => 'seven_up_down']))
            ->assertOk()
            ->assertSee('Lucky 7')
            ->assertSee('Exact 7 Multiplier');
    }

    public function test_api_requires_explicit_game_access(): void
    {
        $user = $this->fundedUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/games/seven-up-down')
            ->assertForbidden()
            ->assertJsonPath('message', 'Lucky 7 is locked for this user.');

        UserGameAccess::query()->create([
            'user_id' => $user->id,
            'game_key' => 'seven_up_down',
        ]);

        $this->getJson('/api/games/seven-up-down')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.settings.display_name', 'Lucky 7')
            ->assertJsonPath('data.settings.rules.SEVEN.dice_combinations', 6)
            ->assertJsonPath('data.settings.rules.payout_type', 'total_return_including_stake')
            ->assertJsonPath('data.settings.pot_multipliers.SEVEN', 4);
    }

    public function test_websocket_config_exposes_global_engine_flags_without_bypassing_user_access(): void
    {
        config()->set('app_features.platform.android.video_room_games_enabled', true);
        config()->set('services.websocket.internal_key', 'test-ws-key');

        $this->getJson('/api/app-config')
            ->assertOk()
            ->assertJsonPath('data.features.seven_up_down_enabled', false);

        $this->getJson('/api/ws/app-config')->assertForbidden();
        $this->getJson('/api/ws/games/seven-up-down/snapshot')->assertForbidden();

        $this->withHeader('X-WS-Internal-Key', 'test-ws-key')
            ->getJson('/api/ws/app-config')
            ->assertOk()
            ->assertJsonPath('data.features.seven_up_down_enabled', true)
            ->assertJsonPath('data.features.video_room_games_enabled', true);

        $this->withHeader('X-WS-Internal-Key', 'test-ws-key')
            ->getJson('/api/ws/games/seven-up-down/snapshot')
            ->assertOk()
            ->assertJsonPath('enabled', true);
    }

    public function test_trusted_websocket_config_remains_available_during_maintenance_and_force_upgrade(): void
    {
        config()->set('services.websocket.internal_key', 'test-ws-key');
        config()->set('app_features.maintenance_mode_enabled', true);
        config()->set('app_features.force_app_upgrade_enabled', true);
        config()->set('app_features.android_min_version_code', 999);

        $this->withHeader('X-WS-Internal-Key', 'test-ws-key')
            ->getJson('/api/ws/app-config')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_fake_bet_targets_stay_stable_when_round_locks(): void
    {
        config()->set('games.seven_up_down.fake_bets_enabled', true);
        config()->set('games.seven_up_down.min_bet', 50);
        $round = $this->openRound();
        $service = app(SevenUpDownService::class);

        $bettingSnapshot = $service->publicRoundSnapshot();
        $bettingFakeTotals = data_get($bettingSnapshot, 'round.fake_totals');

        $round->forceFill([
            'locks_at' => now()->subSecond(),
            'ends_at' => now()->addMinute(),
        ])->save();
        $lockedSnapshot = $service->publicRoundSnapshot();

        $this->assertSame('locked', data_get($lockedSnapshot, 'round.phase'));
        $this->assertSame($bettingFakeTotals, data_get($lockedSnapshot, 'round.fake_totals'));
        $this->assertGreaterThan(0, array_sum($bettingFakeTotals));
        foreach ($bettingFakeTotals as $total) {
            $this->assertSame(0, $total % 50);
        }
    }

    private function fundedUser(): User
    {
        $user = User::factory()->create();
        Wallet::query()->where('user_id', $user->id)->update(['balance' => 1000]);

        return $user;
    }

    private function openRound(): SevenUpDownRound
    {
        return SevenUpDownRound::query()->create([
            'round_key' => 'sud_'.str()->lower(str()->ulid()),
            'status' => 'open',
            'starts_at' => now()->subSecond(),
            'locks_at' => now()->addMinute(),
            'ends_at' => now()->addSeconds(65),
            'meta' => [
                'display_until' => now()->addSeconds(71)->toIso8601String(),
                'pot_multipliers' => ['DOWN' => 3, 'SEVEN' => 4, 'UP' => 3],
                'outcome_weights' => ['DOWN' => 15, 'SEVEN' => 6, 'UP' => 15],
            ],
        ]);
    }
}
