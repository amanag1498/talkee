<?php

namespace App\Services;

use App\Models\{User, SubscriptionPlan, UserSubscription};
use Illuminate\Support\Facades\DB;

class SubscriptionService
{// app/Services/SubscriptionService.php

public function grant(User $user, SubscriptionPlan $plan, string $reason = 'signup_gift', ?int $byUserId = null): UserSubscription
{
    return DB::transaction(function () use ($user, $plan, $reason, $byUserId) {
        $now  = now();

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
            'source'      => $reason,          // 'signup_gift' | 'admin_grant' …
            'charged'     => false,            // complimentary
            'granted_at'  => $now->toIso8601String(),
            'plan_name'   => $plan->name,
            'granted_by' => 'system',
            'welcome_popup_ack_at' => null, // <= IMPORTANT
        ];

        if ($active) {
            $active->update([
                'ends_at'           => $ends,
                'last_purchased_at' => $now,
                'status'            => 'active',
                'meta'              => array_merge($active->meta ?? [], $meta),
            ]);
            return $active->fresh(['plan']);
        }

        return UserSubscription::create([
            'user_id'              => $user->id,
            'subscription_plan_id' => $plan->id,
            'status'               => 'active',
            'starts_at'            => $now,
            'ends_at'              => $ends,
            'last_purchased_at'    => $now,
            'meta'                 => $meta,
        ])->load('plan');
    });
}

    public function purchase(User $user, SubscriptionPlan $plan): UserSubscription
    {
        return DB::transaction(function () use ($user, $plan) {
            // check existing active
            $active = UserSubscription::where('user_id',$user->id)
                ->where('subscription_plan_id',$plan->id)
                ->where('status','active')
                ->lockForUpdate()
                ->first();

            // DEBIT coins via your WalletService
            $walletTx = \App\Services\WalletService::spend(
                user: $user,
                coins: $plan->price_coins,
                category: 'subscription',
                counterparty: null,
                reference: "SUB_PLAN:{$plan->id}",
                meta: ['plan_name'=>$plan->name,'event'=>'SUBSCRIPTION_PURCHASE']
            );

            $now = now();
            $base = ($active && $active->ends_at && $active->ends_at->gt($now)) ? $active->ends_at->clone() : $now->clone();
            $ends = $base->addDays($plan->duration_days);

            if ($active) {
                $meta = $active->meta ?? [];
                $meta['source'] = $meta['source'] ?? 'USER_PURCHASE';
                $active->update([
                    'ends_at'           => $ends,
                    'last_purchased_at' => $now,
                    'status'            => 'active',
                    'meta'              => $meta,
                ]);
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
                'user_id'              => $user->id,
                'subscription_plan_id' => $plan->id,
                'status'               => 'active',
                'starts_at'            => $now,
                'ends_at'              => $ends,
                'last_purchased_at'    => $now,
                'meta'                 => ['source' => 'USER_PURCHASE'],   // 👈
            ])->load('plan');

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
        $sub->update(['status'=>'cancelled', 'ends_at'=>now()]);
    }
}
