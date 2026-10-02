<?php

namespace App\Services;

use App\Models\CallSession;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class BillingReconciliationService
{
    public function __construct(private RechargeOrderService $rechargeOrders) {}

    public function walletTransactionsQuery(Request $request): Builder
    {
        $paymentOrdersAvailable = $this->rechargeOrders->paymentOrdersAvailable();

        return WalletTransaction::query()
            ->with(['wallet.user', 'counterparty'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('category'), function ($q) use ($request) {
                $category = $request->string('category')->toString();
                if ($category === 'entry_pack_purchase') {
                    $q->where('type', 'debit')
                        ->where('reference', 'like', 'ENTRY_PACK_PURCHASE:%');

                    return;
                }
                $q->where('category', $category);
            })
            ->when($paymentOrdersAvailable && $request->filled('recharge_status'), function ($q) use ($request) {
                $q->where('category', 'recharge')
                    ->whereExists(function ($subQuery) use ($request) {
                        $subQuery->selectRaw('1')
                            ->from('payment_orders')
                            ->whereColumn('payment_orders.id', 'wallet_transactions.reference_id')
                            ->where('wallet_transactions.reference_type', 'payment_order')
                            ->where('payment_orders.status', $request->string('recharge_status'));
                    });
            })
            ->when($request->filled('call_id'), function ($q) use ($request) {
                $this->whereCallReference($q, $request->integer('call_id'));
            })
            ->when($request->filled('user_id'), function ($q) use ($request) {
                $q->whereHas('wallet', fn ($wallet) => $wallet->where('user_id', $request->integer('user_id')));
            })
            ->when($request->filled('host_id'), function ($q) use ($request) {
                $hostUserId = \App\Models\Host::query()->whereKey($request->integer('host_id'))->value('user_id');
                if ($hostUserId) {
                    $q->where('counterparty_user_id', $hostUserId);
                }
            })
            ->when($request->filled('agency_id'), function ($q) use ($request) {
                $hostUserIds = \App\Models\Host::query()
                    ->where('agency_id', $request->integer('agency_id'))
                    ->pluck('user_id');
                $q->whereIn('counterparty_user_id', $hostUserIds);
            })
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->string('date_to')))
            ->latest('id');
    }

    public function anomalies(): array
    {
        $billedEndedCalls = CallSession::query()
            ->where('status', 'ended')
            ->whereNotNull('billing_processed_at')
            ->where('total_coins_charged', '>', 0)
            ->pluck('total_coins_charged', 'id');

        $walletReferences = [];
        $walletDebitTotals = [];
        $walletRefundTotals = [];
        if ($billedEndedCalls->isNotEmpty()) {
            WalletTransaction::query()
                ->where('reference', 'like', 'call_billing:%')
                ->select(['id', 'reference', 'type', 'coins'])
                ->chunkById(1000, function ($transactions) use ($billedEndedCalls, &$walletReferences, &$walletDebitTotals): void {
                    foreach ($transactions as $transaction) {
                        if (! preg_match('/^call_billing:([0-9]+)(?::.*)?$/', (string) $transaction->reference, $matches)) {
                            continue;
                        }

                        $callId = (int) $matches[1];
                        if (! $billedEndedCalls->has($callId)) {
                            continue;
                        }

                        $walletReferences[$callId] = true;
                        if ($transaction->type === 'debit') {
                            $walletDebitTotals[$callId] = ($walletDebitTotals[$callId] ?? 0) + (int) $transaction->coins;
                        }
                    }
                });

            WalletTransaction::query()
                ->where('type', 'credit')
                ->where('reference', 'like', 'call_billing_refund:%')
                ->select(['id', 'reference', 'coins'])
                ->chunkById(1000, function ($transactions) use ($billedEndedCalls, &$walletRefundTotals): void {
                    foreach ($transactions as $transaction) {
                        if (! preg_match('/^call_billing_refund:([0-9]+)$/', (string) $transaction->reference, $matches)) {
                            continue;
                        }

                        $callId = (int) $matches[1];
                        if ($billedEndedCalls->has($callId)) {
                            $walletRefundTotals[$callId] = ($walletRefundTotals[$callId] ?? 0) + (int) $transaction->coins;
                        }
                    }
                });
        }

        $callsMissingWallet = 0;
        $walletDebitMismatch = 0;
        foreach ($billedEndedCalls as $callId => $chargedCoins) {
            $callsMissingWallet += ! isset($walletReferences[$callId]) ? 1 : 0;
            $netDebit = ($walletDebitTotals[$callId] ?? 0) - ($walletRefundTotals[$callId] ?? 0);
            $walletDebitMismatch += $netDebit !== (int) $chargedCoins ? 1 : 0;
        }

        $callsMissingLedger = CallSession::query()
            ->where('status', 'ended')
            ->whereNotNull('billing_processed_at')
            ->where('total_coins_charged', '>', 0)
            ->whereDoesntHave('earningLedger')
            ->count();

        $duplicateBilling = WalletTransaction::query()
            ->selectRaw('reference, COUNT(*) as duplicate_count')
            ->where('type', 'debit')
            ->where('reference', 'like', 'call_billing:%:%')
            ->groupBy('reference')
            ->having('duplicate_count', '>', 1)
            ->get()
            ->count();

        $failedCallsWithBilling = CallSession::query()
            ->where('status', 'failed')
            ->where(function ($query) {
                $query->where('total_coins_charged', '>', 0)->orWhereHas('earningLedger');
            })
            ->count();

        $completedMissingBilling = CallSession::query()
            ->where('status', 'ended')
            ->whereNull('billing_processed_at')
            ->count();

        return array_merge([
            'calls_missing_wallet_transaction' => $callsMissingWallet,
            'calls_missing_earning_ledger' => $callsMissingLedger,
            'duplicate_billing_references' => $duplicateBilling,
            'call_wallet_debit_total_mismatch' => $walletDebitMismatch,
            'failed_calls_with_billing_entries' => $failedCallsWithBilling,
            'completed_calls_missing_billing' => $completedMissingBilling,
        ], $this->rechargeOrders->anomalies());
    }

    public function billingReference(int $callId): string
    {
        return 'call_billing:'.$callId;
    }

    private function whereCallReference(Builder $query, int $callId): Builder
    {
        $reference = $this->billingReference($callId);

        return $query->where(function ($referenceQuery) use ($reference) {
            $referenceQuery->where('reference', $reference)
                ->orWhere('reference', 'like', $reference.':%');
        });
    }
}
