<?php

namespace App\Services;

use App\Models\PaymentOrder;
use App\Models\RechargePlan;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RechargeOrderService
{
    public function paymentOrdersAvailable(): bool
    {
        return Schema::hasTable('payment_orders');
    }

    private function rechargeLedgerColumnsAvailable(): bool
    {
        return Schema::hasColumn('wallet_transactions', 'reference_type')
            && Schema::hasColumn('wallet_transactions', 'reference_id')
            && Schema::hasColumn('wallet_transactions', 'category');
    }

    public function createOrder(User $user, int $planId, ?string $gateway = null): PaymentOrder
    {
        if (!$this->paymentOrdersAvailable()) {
            throw new InvalidArgumentException('Recharge setup is incomplete. Run the latest migrations.');
        }

        $plan = RechargePlan::query()
            ->whereKey($planId)
            ->where('is_active', true)
            ->first();

        if (!$plan) {
            throw new InvalidArgumentException('Recharge plan is unavailable.');
        }

        return PaymentOrder::query()->create([
            'user_id' => $user->id,
            'recharge_plan_id' => $plan->id,
            'order_id' => 'talkee_ro_' . Str::lower((string) Str::uuid()),
            'amount_rupees' => $plan->amount_rupees,
            'coins' => $plan->coins,
            'bonus_coins' => $plan->bonus_coins,
            'total_coins' => $plan->total_coins,
            'status' => config('services.mock_payments.enabled', true) ? 'pending' : 'created',
            'gateway' => $gateway ?: 'mock',
        ]);
    }

    public function verifyOrder(User $user, string $orderId, array $payload = []): array
    {
        if (!$this->paymentOrdersAvailable() || !$this->rechargeLedgerColumnsAvailable()) {
            throw new InvalidArgumentException('Recharge setup is incomplete. Run the latest migrations.');
        }

        return DB::transaction(function () use ($user, $orderId, $payload) {
            $order = PaymentOrder::query()
                ->where('order_id', $orderId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            WalletService::getOrCreate($user);

            $wallet = Wallet::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingTx = WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('reference_type', 'payment_order')
                ->where('reference_id', $order->id)
                ->where('category', 'recharge')
                ->first();

            if ($existingTx) {
                if ($order->status !== 'success') {
                    $order->forceFill([
                        'status' => 'success',
                        'verified_at' => $order->verified_at ?: now(),
                    ])->save();
                }

                return [
                    'order' => $order->fresh(),
                    'wallet' => $wallet->fresh(),
                    'transaction' => $existingTx,
                    'already_processed' => true,
                ];
            }

            if (in_array($order->status, ['failed', 'cancelled'], true)) {
                throw new InvalidArgumentException('Recharge order is not payable.');
            }

            $result = strtolower((string) ($payload['result'] ?? 'success'));
            $gatewayPaymentId = $payload['gateway_payment_id'] ?? null;
            $gatewayResponse = $payload['gateway_response'] ?? [];

            if (!config('services.mock_payments.enabled', true) && $order->gateway === 'mock') {
                throw new InvalidArgumentException('Mock payments are disabled.');
            }

            if ($result !== 'success') {
                $mappedStatus = in_array($result, ['failed', 'cancelled'], true) ? $result : 'failed';
                $order->forceFill([
                    'status' => $mappedStatus,
                    'gateway_payment_id' => $gatewayPaymentId,
                    'gateway_response' => $gatewayResponse ?: ['result' => $mappedStatus],
                ])->save();

                return [
                    'order' => $order->fresh(),
                    'wallet' => $wallet->fresh(),
                    'transaction' => null,
                    'already_processed' => false,
                ];
            }

            $balanceBefore = (int) $wallet->balance;
            $balanceAfter = $balanceBefore + (int) $order->total_coins;

            $transaction = WalletTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'coins' => (int) $order->total_coins,
                'amount' => $order->amount_rupees,
                'currency' => 'INR',
                'category' => 'recharge',
                'reference' => 'payment_order:' . $order->id,
                'reference_type' => 'payment_order',
                'reference_id' => $order->id,
                'transaction_id' => $gatewayPaymentId,
                'gateway' => $order->gateway,
                'description' => 'Recharge ₹' . number_format((float) $order->amount_rupees, 0),
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'meta' => [
                    'plan_id' => $order->recharge_plan_id,
                    'bonus_coins' => (int) $order->bonus_coins,
                    'order_id' => $order->order_id,
                ],
            ]);

            $wallet->update(['balance' => $balanceAfter]);

            $order->forceFill([
                'status' => 'success',
                'gateway_payment_id' => $gatewayPaymentId ?: $order->gateway_payment_id,
                'gateway_response' => $gatewayResponse ?: ['result' => 'success'],
                'verified_at' => now(),
            ])->save();

            return [
                'order' => $order->fresh(),
                'wallet' => $wallet->fresh(),
                'transaction' => $transaction,
                'already_processed' => false,
            ];
        });
    }

    public function ordersFor(User $user)
    {
        if (!$this->paymentOrdersAvailable()) {
            throw new InvalidArgumentException('Recharge setup is incomplete. Run the latest migrations.');
        }

        return PaymentOrder::query()
            ->with('rechargePlan')
            ->where('user_id', $user->id)
            ->latest('id')
            ->paginate(20);
    }

    public function transactionsFor(User $user, ?string $filter = null)
    {
        return WalletTransaction::query()
            ->with('counterparty')
            ->whereHas('wallet', fn ($wallet) => $wallet->where('user_id', $user->id))
            ->when($filter && $filter !== 'all', function ($query) use ($filter) {
                if ($filter === 'earning') {
                    $query->where('type', 'credit')->whereNotIn('category', ['recharge', 'purchase', 'adjustment']);
                    return;
                }
                if ($filter === 'recharge') {
                    $query->where('category', 'recharge');
                    return;
                }
                $query->where('type', $filter);
            })
            ->latest('id')
            ->paginate(25);
    }

    public function anomalies(): array
    {
        if (!$this->paymentOrdersAvailable() || !$this->rechargeLedgerColumnsAvailable()) {
            return [
                'payment_success_without_wallet_transaction' => 0,
                'wallet_transaction_without_payment_order' => 0,
                'duplicate_recharge_credits' => 0,
                'mismatched_recharge_coin_amount' => 0,
            ];
        }

        $successfulWithoutTx = PaymentOrder::query()
            ->where('status', 'success')
            ->get()
            ->filter(fn (PaymentOrder $order) => !WalletTransaction::query()
                ->where('reference_type', 'payment_order')
                ->where('reference_id', $order->id)
                ->where('category', 'recharge')
                ->exists())
            ->count();

        $txWithoutOrder = WalletTransaction::query()
            ->where('category', 'recharge')
            ->where('reference_type', 'payment_order')
            ->get()
            ->filter(fn (WalletTransaction $transaction) => !PaymentOrder::query()->whereKey($transaction->reference_id)->exists())
            ->count();

        $duplicates = WalletTransaction::query()
            ->selectRaw('reference_type, reference_id, COUNT(*) as duplicate_count')
            ->where('category', 'recharge')
            ->where('reference_type', 'payment_order')
            ->groupBy('reference_type', 'reference_id')
            ->having('duplicate_count', '>', 1)
            ->get()
            ->count();

        $mismatchedCoins = PaymentOrder::query()
            ->where('status', 'success')
            ->get()
            ->filter(function (PaymentOrder $order) {
                $tx = WalletTransaction::query()
                    ->where('reference_type', 'payment_order')
                    ->where('reference_id', $order->id)
                    ->where('category', 'recharge')
                    ->first();

                return $tx && (int) $tx->coins !== (int) $order->total_coins;
            })
            ->count();

        return [
            'payment_success_without_wallet_transaction' => $successfulWithoutTx,
            'wallet_transaction_without_payment_order' => $txWithoutOrder,
            'duplicate_recharge_credits' => $duplicates,
            'mismatched_recharge_coin_amount' => $mismatchedCoins,
        ];
    }
}
