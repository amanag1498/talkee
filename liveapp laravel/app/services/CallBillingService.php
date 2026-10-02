<?php

namespace App\Services;

use App\Models\CallEarningLedger;
use App\Models\CallSession;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class CallBillingService
{
    public function ensureInitialCharge(CallSession $call): void
    {
        $rate = (int) $call->coin_rate_per_minute;
        if ($rate <= 0) {
            return;
        }

        $this->chargeDelta($call, $rate, 1, 'Call first minute billed on accept');
    }

    public function syncAcceptedCallBilling(CallSession $call): bool
    {
        return DB::transaction(function () use ($call) {
            $call = CallSession::query()->lockForUpdate()->findOrFail($call->id);

            if ($call->status !== 'accepted' || ! $call->accepted_at || $call->billing_processed_at) {
                return true;
            }

            $rate = (int) $call->coin_rate_per_minute;
            if ($rate <= 0) {
                return true;
            }

            $durationSeconds = max(0, $call->started_at?->diffInSeconds(now()) ?? 0);
            $elapsedBillableMinutes = max(
                (int) config('calls.minimum_billable_minutes', 1),
                (int) ceil($durationSeconds / 60)
            );
            $alreadyBilledCoins = $this->billedCoinsForCall($call);
            $alreadyBilledMinutes = intdiv($alreadyBilledCoins, $rate);
            $paidThroughSeconds = $alreadyBilledMinutes * 60;
            $minutesToCharge = max(0, $elapsedBillableMinutes - $alreadyBilledMinutes);

            if ($minutesToCharge <= 0) {
                if ($paidThroughSeconds > 0 && $durationSeconds >= $paidThroughSeconds) {
                    $wallet = Wallet::query()
                        ->where('user_id', $call->caller_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $wallet || (int) $wallet->balance < $rate) {
                        return false;
                    }

                    $this->createDebit(
                        $call,
                        $wallet,
                        $rate,
                        $alreadyBilledMinutes + 1,
                        'Call next minute prepaid'
                    );
                }

                return true;
            }

            $wallet = Wallet::query()
                ->where('user_id', $call->caller_id)
                ->lockForUpdate()
                ->first();

            $affordableMinutes = $wallet ? intdiv((int) $wallet->balance, $rate) : 0;
            $minutesCharged = $this->createMinuteDebits(
                $call,
                $wallet,
                min($minutesToCharge, $affordableMinutes),
                $alreadyBilledMinutes + 1,
                'Call active minute billed'
            );

            return $minutesCharged === $minutesToCharge;
        });
    }

    public function processEndedCall(CallSession $call): CallSession
    {
        return DB::transaction(function () use ($call) {
            $call = CallSession::query()->lockForUpdate()->with(['caller.wallet', 'host.agency'])->findOrFail($call->id);

            if ($call->billing_processed_at) {
                return $call;
            }

            if (! $call->accepted_at) {
                $call->update([
                    'duration_seconds' => 0,
                    'billable_minutes' => 0,
                    'total_coins_charged' => 0,
                    'host_earning' => 0,
                    'agency_earning' => 0,
                    'platform_earning' => 0,
                    'billing_processed_at' => now(),
                ]);

                return $call->fresh();
            }

            $endTime = $call->ended_at ?? now();
            $durationSeconds = max(0, $call->started_at?->diffInSeconds($endTime) ?? 0);
            $billableMinutes = max(
                (int) config('calls.minimum_billable_minutes', 1),
                (int) ceil($durationSeconds / 60)
            );
            $totalCoins = $billableMinutes * (int) $call->coin_rate_per_minute;
            $rate = (int) $call->coin_rate_per_minute;

            $wallet = Wallet::query()
                ->where('user_id', $call->caller_id)
                ->lockForUpdate()
                ->first();

            $alreadyBilledCoins = $this->billedCoinsForCall($call);
            if ($alreadyBilledCoins > $totalCoins && $wallet) {
                $this->refundOverbilledCoins($call, $wallet, $alreadyBilledCoins - $totalCoins);
            }
            $deltaCoins = max(0, $totalCoins - $alreadyBilledCoins);

            if ($deltaCoins > 0 && (! $wallet || $wallet->balance < $deltaCoins)) {
                $affordableAdditionalMinutes = $wallet && $rate > 0
                    ? intdiv((int) $wallet->balance, $rate)
                    : 0;

                if ($affordableAdditionalMinutes > 0) {
                    $this->createMinuteDebits(
                        $call,
                        $wallet,
                        $affordableAdditionalMinutes,
                        intdiv($alreadyBilledCoins, max(1, $rate)) + 1,
                        'Call final billing until wallet balance was exhausted'
                    );
                    $alreadyBilledCoins = $this->billedCoinsForCall($call);
                }

                if ($alreadyBilledCoins <= 0) {
                    $call->update([
                        'status' => 'failed',
                        'duration_seconds' => $durationSeconds,
                        'billable_minutes' => 0,
                        'total_coins_charged' => 0,
                        'host_earning' => 0,
                        'agency_earning' => 0,
                        'platform_earning' => 0,
                        'end_reason' => 'insufficient_balance',
                        'billing_processed_at' => now(),
                    ]);

                    return $call->fresh();
                }

                $totalCoins = $alreadyBilledCoins;
                $billableMinutes = $rate > 0 ? max(1, intdiv($alreadyBilledCoins, $rate)) : 0;
                $call->update([
                    'end_reason' => 'insufficient_balance',
                ]);
            }

            $hostSharePercent = (float) config('calls.host_share_percent', 60);
            $agencySharePercent = (float) config('calls.agency_share_percent', 10);
            $platformSharePercent = (float) config('calls.platform_share_percent', 30);

            $hostEarning = (int) floor(($totalCoins * $hostSharePercent) / 100);
            $agencyEarning = $call->agency_id ? (int) floor(($totalCoins * $agencySharePercent) / 100) : 0;
            $platformEarning = max(0, $totalCoins - $hostEarning - $agencyEarning);

            if ($deltaCoins > 0 && $wallet && $wallet->balance >= $deltaCoins) {
                $this->createMinuteDebits(
                    $call,
                    $wallet,
                    intdiv($deltaCoins, max(1, $rate)),
                    intdiv($alreadyBilledCoins, max(1, $rate)) + 1,
                    sprintf('%s call billed for %d minute(s)', ucfirst($call->type), $billableMinutes)
                );
            }

            $ledger = CallEarningLedger::query()->updateOrCreate(
                ['call_session_id' => $call->id],
                [
                    'caller_id' => $call->caller_id,
                    'host_id' => $call->host_id,
                    'agency_id' => $call->agency_id,
                    'total_coins' => $totalCoins,
                    'host_earning' => $hostEarning,
                    'agency_earning' => $agencyEarning,
                    'platform_earning' => $platformEarning,
                    'duration_seconds' => $durationSeconds,
                    'billable_minutes' => $billableMinutes,
                ]
            );

            if ($ledger->wasRecentlyCreated) {
                DB::afterCommit(function () use ($call, $totalCoins) {
                    try {
                        app(LeaderboardService::class)->recordCallSuccess(
                            callerUserId: (int) $call->caller_id,
                            hostId: (int) $call->host_id,
                            agencyId: $call->agency_id ? (int) $call->agency_id : null,
                            totalCoins: (int) $totalCoins,
                            occurredAt: $call->ended_at ?? now(),
                        );
                    } catch (\Throwable $e) {
                        report($e);
                    }
                });
            }

            $call->update([
                'duration_seconds' => $durationSeconds,
                'billable_minutes' => $billableMinutes,
                'total_coins_charged' => $totalCoins,
                'host_earning' => $hostEarning,
                'agency_earning' => $agencyEarning,
                'platform_earning' => $platformEarning,
                'billing_processed_at' => now(),
            ]);

            return $call->fresh();
        });
    }

    public function billingReference(int $callId): string
    {
        return 'call_billing:'.$callId;
    }

    private function chargeDelta(CallSession $call, int $coins, int $billableMinutes, string $description): void
    {
        if ($coins <= 0) {
            return;
        }

        DB::transaction(function () use ($call, $coins, $billableMinutes, $description) {
            $call = CallSession::query()->lockForUpdate()->findOrFail($call->id);

            $alreadyBilledCoins = $this->billedCoinsForCall($call);
            if ($alreadyBilledCoins >= $coins) {
                return;
            }

            $wallet = Wallet::query()
                ->where('user_id', $call->caller_id)
                ->lockForUpdate()
                ->first();

            if (! $wallet || (int) $wallet->balance < $coins) {
                throw new \InvalidArgumentException('Insufficient coins to continue call.');
            }

            $this->createDebit($call, $wallet, $coins, $billableMinutes, $description);
        });
    }

    private function createMinuteDebits(
        CallSession $call,
        ?Wallet $wallet,
        int $minuteCount,
        int $firstMinute,
        string $description
    ): int {
        if (! $wallet || $minuteCount <= 0) {
            return 0;
        }

        $charged = 0;
        $rate = (int) $call->coin_rate_per_minute;

        for ($offset = 0; $offset < $minuteCount; $offset++) {
            if ((int) $wallet->balance < $rate) {
                break;
            }

            if ($this->createDebit($call, $wallet, $rate, $firstMinute + $offset, $description)) {
                $charged++;
            }
        }

        return $charged;
    }

    private function createDebit(CallSession $call, Wallet $wallet, int $coins, int $billableMinutes, string $description): ?WalletTransaction
    {
        if ($coins <= 0) {
            return null;
        }

        $reference = sprintf('%s:%d', $this->billingReference($call->id), $billableMinutes);
        $existing = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', 'debit')
            ->where('reference', $reference)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return null;
        }

        $balanceBefore = (int) $wallet->balance;
        $balanceAfter = $balanceBefore - $coins;
        $wallet->decrement('balance', $coins);
        $wallet->balance = $balanceAfter;

        return WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'debit',
            'coins' => $coins,
            'category' => $call->type === 'video' ? 'video_call' : 'audio_call',
            'reference' => $reference,
            'counterparty_user_id' => $call->receiver_id,
            'meta' => [
                'call_session_id' => $call->id,
                'description' => $description,
                'billable_minutes' => $billableMinutes,
                'rate_per_minute' => (int) $call->coin_rate_per_minute,
            ],
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'description' => $description,
        ]);
    }

    private function refundOverbilledCoins(CallSession $call, Wallet $wallet, int $coins): void
    {
        $reference = 'call_billing_refund:'.$call->id;
        if (WalletTransaction::query()->where('reference', $reference)->lockForUpdate()->exists()) {
            return;
        }

        $balanceBefore = (int) $wallet->balance;
        $balanceAfter = $balanceBefore + $coins;
        $wallet->increment('balance', $coins);
        $wallet->balance = $balanceAfter;

        WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'coins' => $coins,
            'category' => 'refund',
            'reference' => $reference,
            'counterparty_user_id' => $call->receiver_id,
            'meta' => [
                'call_session_id' => $call->id,
                'description' => 'Unused call minutes refunded after media disconnect',
            ],
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'description' => 'Unused call minutes refunded after media disconnect',
        ]);
    }

    private function billedCoinsForCall(CallSession $call): int
    {
        return (int) WalletTransaction::query()
            ->whereHas('wallet', fn ($query) => $query->where('user_id', $call->caller_id))
            ->where('type', 'debit')
            ->where(function ($query) use ($call) {
                $reference = $this->billingReference($call->id);
                $query->where('reference', $reference)
                    ->orWhere('reference', 'like', $reference.':%');
            })
            ->sum('coins');
    }
}
