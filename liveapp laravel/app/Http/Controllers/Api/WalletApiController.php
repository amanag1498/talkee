<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RechargePlanService;
use App\Services\RechargeOrderService;
use App\Services\WalletService;
use Illuminate\Http\Request;

class WalletApiController extends Controller
{
    public function __construct(
        private RechargePlanService $plans,
        private RechargeOrderService $rechargeOrders,
    )
    {
    }

    public function summary(Request $request)
    {
        $wallet = WalletService::getOrCreate($request->user());
        $paymentReady = $this->rechargeOrders->paymentReady();

        return response()->json([
            'ok' => true,
            'data' => [
                'balance' => (int) $wallet->balance,
                'payment_ready' => $paymentReady,
                'message' => $this->rechargeOrders->paymentSummaryMessage(),
                'quick_packs' => $this->plans->activePlans(),
            ],
        ]);
    }

    public function transactions(Request $request)
    {
        $transactions = $this->rechargeOrders->transactionsFor(
            $request->user(),
            $request->string('filter')->toString() ?: null
        );

        return response()->json([
            'ok' => true,
            'data' => [
                'transactions' => $transactions->items(),
                'meta' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'total' => $transactions->total(),
                ],
            ],
        ]);
    }
}
