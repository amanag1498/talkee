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

        return response()->json([
            'ok' => true,
            'data' => [
                'balance' => (int) $wallet->balance,
                'payment_ready' => (bool) config('services.mock_payments.enabled', true),
                'message' => config('services.mock_payments.enabled', true)
                    ? 'Mock payment gateway enabled.'
                    : 'Payment setup required.',
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
