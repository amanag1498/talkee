<?php

namespace App\Services;

use App\Models\CallEarningLedger;
use App\Models\CallSession;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class CallBillingService
{
    public function processEndedCall(CallSession $call): CallSession
    {
        return DB::transaction(function () use ($call) {
            $call = CallSession::query()->lockForUpdate()->with(['caller.wallet', 'host.agency'])->findOrFail($call->id);

            if ($call->billing_processed_at) {
                return $call;
            }

            if (!$call->accepted_at) {
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

            $wallet = Wallet::query()
                ->where('user_id', $call->caller_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet || $wallet->balance < $totalCoins) {
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

            $hostSharePercent = (float) config('calls.host_share_percent', 60);
            $agencySharePercent = (float) config('calls.agency_share_percent', 10);
            $platformSharePercent = (float) config('calls.platform_share_percent', 30);

            $hostEarning = (int) floor(($totalCoins * $hostSharePercent) / 100);
            $agencyEarning = $call->agency_id ? (int) floor(($totalCoins * $agencySharePercent) / 100) : 0;
            $platformEarning = max(0, $totalCoins - $hostEarning - $agencyEarning);

            $billingReference = $this->billingReference($call->id);
            $existingBilling = WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('type', 'debit')
                ->where('reference', $billingReference)
                ->lockForUpdate()
                ->first();

            if (!$existingBilling) {
                $balanceBefore = (int) $wallet->balance;
                $balanceAfter = $balanceBefore - $totalCoins;
                $wallet->decrement('balance', $totalCoins);

                WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'type' => 'debit',
                    'coins' => $totalCoins,
                    'category' => $call->type === 'video' ? 'video_call' : 'audio_call',
                    'reference' => $billingReference,
                    'counterparty_user_id' => $call->receiver_id,
                    'meta' => [
                        'call_session_id' => $call->id,
                        'description' => sprintf(
                            '%s call billed for %d minute(s)',
                            ucfirst($call->type),
                            $billableMinutes
                        ),
                        'duration_seconds' => $durationSeconds,
                        'billable_minutes' => $billableMinutes,
                        'rate_per_minute' => (int) $call->coin_rate_per_minute,
                    ],
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'description' => sprintf('%s call billed for %d minute(s)', ucfirst($call->type), $billableMinutes),
                ]);
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
        return 'call_billing:' . $callId;
    }
}
