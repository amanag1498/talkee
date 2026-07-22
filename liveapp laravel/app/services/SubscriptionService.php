<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\WalletTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public function grant(User $user, SubscriptionPlan $plan, string $reason = 'signup_gift', ?int $byUserId = null): UserSubscription
    {
        return DB::transaction(function () use ($user, $plan, $reason) {
            $now = now();

            $active = UserSubscription::where('user_id', $user->id)
                ->where('subscription_plan_id', $plan->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            $base = ($active && $active->ends_at && $active->ends_at->gt($now))
                ? $active->ends_at->clone()
                : $now->clone();

            $ends = $base->addDays($plan->duration_days);

            $meta = [
                'source' => $reason,          // 'signup_gift' | 'admin_grant' …
                'charged' => false,            // complimentary
                'granted_at' => $now->toIso8601String(),
                'plan_name' => $plan->name,
                'granted_by' => 'system',
                'welcome_popup_ack_at' => null, // <= IMPORTANT
            ];

            if ($active) {
                $active->update([
                    'ends_at' => $ends,
                    'last_purchased_at' => $now,
                    'status' => 'active',
                    'meta' => array_merge($active->meta ?? [], $meta),
                ]);

                return $active->fresh(['plan']);
            }

            return UserSubscription::create([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => $now,
                'ends_at' => $ends,
                'last_purchased_at' => $now,
                'meta' => $meta,
            ])->load('plan');
        });
    }

    public function purchase(User $user, SubscriptionPlan $plan): UserSubscription
    {
        return DB::transaction(function () use ($user, $plan) {
            // check existing active
            $active = UserSubscription::where('user_id', $user->id)
                ->where('subscription_plan_id', $plan->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            $purchaseKind = $active ? 'renewal' : 'purchase';

            // DEBIT coins via your WalletService
            $walletTx = \App\Services\WalletService::spend(
                user: $user,
                coins: $plan->price_coins,
                category: 'subscription',
                counterparty: null,
                reference: "SUB_PLAN:{$plan->id}",
                meta: [
                    'plan_name' => $plan->name,
                    'subscription_plan_id' => $plan->id,
                    'event' => 'SUBSCRIPTION_PURCHASE',
                    'purchase_kind' => $purchaseKind,
                    'source' => 'app',
                ]
            );

            $now = now();
            $periodStartsAt = ($active && $active->ends_at && $active->ends_at->gt($now))
                ? $active->ends_at->clone()
                : $now->clone();
            $ends = $periodStartsAt->clone()->addDays($plan->duration_days);

            $purchaseMeta = [
                'source' => 'USER_PURCHASE',
                'event' => 'SUBSCRIPTION_PURCHASE',
                'charged' => true,
                'plan_name' => $plan->name,
                'price_coins' => (int) $plan->price_coins,
                'wallet_transaction_id' => $walletTx->id ?? null,
                'purchased_at' => $now->toIso8601String(),
                'last_purchase_kind' => $purchaseKind,
            ];

            if ($active) {
                $meta = $active->meta ?? [];
                if (! empty($meta['source']) && $meta['source'] !== 'USER_PURCHASE') {
                    $meta['previous_source'] = $meta['source'];
                }

                $active->update([
                    'ends_at' => $ends,
                    'last_purchased_at' => $now,
                    'status' => 'active',
                    'meta' => array_merge($meta, $purchaseMeta),
                ]);
                $this->linkSaleToSubscription($walletTx, $active, $plan, $periodStartsAt, $ends, $purchaseKind);
                DB::afterCommit(function () use ($user, $plan, $walletTx) {
                    try {
                        app(LeaderboardService::class)->recordSubscriptionPurchase(
                            userId: (int) $user->id,
                            totalCoins: (int) $plan->price_coins,
                            occurredAt: $walletTx->created_at ?? now(),
                        );
                    } catch (\Throwable $e) {
                        report($e);
                    }
                });

                return $active->fresh(['plan']);
            }

            $subscription = UserSubscription::create([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => $now,
                'ends_at' => $ends,
                'last_purchased_at' => $now,
                'meta' => $purchaseMeta,
            ])->load('plan');

            $this->linkSaleToSubscription($walletTx, $subscription, $plan, $periodStartsAt, $ends, $purchaseKind);

            DB::afterCommit(function () use ($user, $plan, $walletTx) {
                try {
                    app(LeaderboardService::class)->recordSubscriptionPurchase(
                        userId: (int) $user->id,
                        totalCoins: (int) $plan->price_coins,
                        occurredAt: $walletTx->created_at ?? now(),
                    );
                } catch (\Throwable $e) {
                    report($e);
                }
            });

            return $subscription;
        });
    }

    public function cancelNow(UserSubscription $sub): void
    {
        $sub->update(['status' => 'cancelled', 'ends_at' => now()]);
    }

    private function linkSaleToSubscription(
        WalletTransaction $walletTransaction,
        UserSubscription $subscription,
        SubscriptionPlan $plan,
        CarbonInterface $periodStartsAt,
        CarbonInterface $periodEndsAt,
        string $purchaseKind,
    ): void {
        $walletTransaction->update([
            'meta' => array_merge($walletTransaction->meta ?? [], [
                'subscription_id' => $subscription->id,
                'subscription_plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'purchase_kind' => $purchaseKind,
                'period_starts_at' => $periodStartsAt->toIso8601String(),
                'period_ends_at' => $periodEndsAt->toIso8601String(),
            ]),
        ]);
    }
}
