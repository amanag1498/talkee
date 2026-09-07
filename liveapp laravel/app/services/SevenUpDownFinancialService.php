<?php

namespace App\Services;

use App\Models\SevenUpDownBet;
use App\Models\SevenUpDownFinancialAccount;
use App\Models\SevenUpDownFinancialLedgerEntry;
use App\Models\SevenUpDownPayout;
use Illuminate\Support\Facades\DB;

class SevenUpDownFinancialService
{
    public const GAME_KEY = 'seven_up_down';

    public const COMMISSION_PERCENT = 5;

    public function recordBet(SevenUpDownBet $bet): SevenUpDownFinancialLedgerEntry
    {
        $betCoins = (int) $bet->amount;
        $commissionCoins = intdiv(($betCoins * self::COMMISSION_PERCENT) + 99, 100);
        $treasuryCoins = $betCoins - $commissionCoins;

        return $this->applyEntry(
            eventKey: "seven_up_down:bet:{$bet->id}:allocation",
            eventType: 'bet_allocation',
            treasuryDeltaCoins: $treasuryCoins,
            commissionDeltaCoins: $commissionCoins,
            roundId: (int) $bet->seven_up_down_round_id,
            betId: (int) $bet->id,
            meta: [
                'bet_coins' => (int) $bet->amount,
                'treasury_percent' => 95,
                'commission_percent' => self::COMMISSION_PERCENT,
                'rounding' => 'commission_ceil',
            ],
        );
    }

    public function recordPayout(SevenUpDownPayout $payout): SevenUpDownFinancialLedgerEntry
    {
        $payout->loadMissing('bet');
        if ($payout->bet) {
            $this->recordBet($payout->bet);
        }

        return $this->applyEntry(
            eventKey: "seven_up_down:payout:{$payout->id}:treasury_debit",
            eventType: 'payout_debit',
            treasuryDeltaCoins: -(int) $payout->payout_coins,
            commissionDeltaCoins: 0,
            roundId: (int) $payout->seven_up_down_round_id,
            betId: (int) $payout->seven_up_down_bet_id,
            payoutId: (int) $payout->id,
            meta: [
                'payout_coins' => (int) $payout->payout_coins,
            ],
        );
    }

    public function recordRefund(SevenUpDownBet $bet): SevenUpDownFinancialLedgerEntry
    {
        $allocation = $this->recordBet($bet);

        return $this->applyEntry(
            eventKey: "seven_up_down:bet:{$bet->id}:allocation_reversal",
            eventType: 'bet_refund_reversal',
            treasuryDeltaCoins: -(int) $allocation->treasury_delta_coins,
            commissionDeltaCoins: -(int) $allocation->commission_delta_coins,
            roundId: (int) $bet->seven_up_down_round_id,
            betId: (int) $bet->id,
            meta: [
                'refund_coins' => (int) $bet->amount,
                'reverses_event_key' => $allocation->event_key,
            ],
        );
    }

    public function account(): SevenUpDownFinancialAccount
    {
        return SevenUpDownFinancialAccount::query()
            ->where('game_key', self::GAME_KEY)
            ->firstOrFail();
    }

    private function applyEntry(
        string $eventKey,
        string $eventType,
        int $treasuryDeltaCoins,
        int $commissionDeltaCoins,
        ?int $roundId = null,
        ?int $betId = null,
        ?int $payoutId = null,
        array $meta = [],
    ): SevenUpDownFinancialLedgerEntry {
        return DB::transaction(function () use (
            $eventKey,
            $eventType,
            $treasuryDeltaCoins,
            $commissionDeltaCoins,
            $roundId,
            $betId,
            $payoutId,
            $meta,
        ) {
            $account = SevenUpDownFinancialAccount::query()
                ->where('game_key', self::GAME_KEY)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = SevenUpDownFinancialLedgerEntry::query()
                ->where('event_key', $eventKey)
                ->first();
            if ($existing) {
                return $existing;
            }

            $treasuryBalanceAfter = (int) $account->treasury_balance_coins + $treasuryDeltaCoins;
            $commissionBalanceAfter = (int) $account->company_commission_balance_coins + $commissionDeltaCoins;

            $account->forceFill([
                'treasury_balance_coins' => $treasuryBalanceAfter,
                'company_commission_balance_coins' => $commissionBalanceAfter,
            ])->save();

            return SevenUpDownFinancialLedgerEntry::query()->create([
                'seven_up_down_financial_account_id' => $account->id,
                'seven_up_down_round_id' => $roundId,
                'seven_up_down_bet_id' => $betId,
                'seven_up_down_payout_id' => $payoutId,
                'event_key' => $eventKey,
                'event_type' => $eventType,
                'treasury_delta_coins' => $treasuryDeltaCoins,
                'commission_delta_coins' => $commissionDeltaCoins,
                'treasury_balance_after_coins' => $treasuryBalanceAfter,
                'commission_balance_after_coins' => $commissionBalanceAfter,
                'meta' => $meta,
                'occurred_at' => now(),
            ]);
        });
    }
}
