<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RechargeOrderService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class RechargeOrderController extends Controller
{
    public function __construct(private RechargeOrderService $service)
    {
    }

    public function index(Request $request)
    {
        try {
            $orders = $this->service->ordersFor($request->user());
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'orders' => $orders->items(),
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'total' => $orders->total(),
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'plan_id' => 'required|integer|exists:recharge_plans,id',
            'gateway' => 'nullable|string|max:40',
        ]);

        try {
            $order = $this->service->createOrder($request->user(), (int) $data['plan_id'], $data['gateway'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'data' => $order->load('rechargePlan'),
            'message' => 'Recharge order created.',
        ], 201);
    }

    public function verify(Request $request, string $orderId)
    {
        $data = $request->validate([
            'result' => 'nullable|string|in:success,failed,cancelled',
            'gateway_payment_id' => 'nullable|string|max:120',
            'gateway_response' => 'nullable|array',
        ]);

        try {
            $result = $this->service->verifyOrder($request->user(), $orderId, $data);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $status = $result['order']->status;

        return response()->json([
            'ok' => $status === 'success',
            'message' => $status === 'success'
                ? ($result['already_processed'] ? 'Recharge already verified.' : 'Recharge successful.')
                : 'Recharge verification failed.',
            'data' => [
                'order' => $result['order'],
                'wallet_balance' => (int) $result['wallet']->balance,
                'transaction' => $result['transaction'],
                'already_processed' => $result['already_processed'],
            ],
        ]);
    }
}
