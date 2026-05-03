<?php

namespace App\Services;

use App\Models\CallSession;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class BillingReconciliationService
{
    public function __construct(private RechargeOrderService $rechargeOrders)
    {
    }

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
            ->when($request->filled('call_id'), fn ($q) => $q->where('reference', $this->billingReference($request->integer('call_id'))))
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
            ->get(['id']);

        $callsMissingWallet = $billedEndedCalls->filter(function (CallSession $call) {
            return !WalletTransaction::query()
                ->where('reference', $this->billingReference($call->id))
                ->exists();
        })->count();

        $callsMissingLedger = $billedEndedCalls->filter(fn (CallSession $call) => !$call->earningLedger()->exists())->count();

        $duplicateBilling = WalletTransaction::query()
            ->selectRaw('reference, COUNT(*) as duplicate_count')
            ->where('reference', 'like', 'call_billing:%')
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
            'failed_calls_with_billing_entries' => $failedCallsWithBilling,
            'completed_calls_missing_billing' => $completedMissingBilling,
        ], $this->rechargeOrders->anomalies());
    }

    public function billingReference(int $callId): string
    {
        return 'call_billing:' . $callId;
    }
}
