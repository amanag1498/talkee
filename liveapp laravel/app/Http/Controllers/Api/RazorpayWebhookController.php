<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentOrder;
use App\Services\MetaAppEventRecorder;
use App\Services\RechargeOrderService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class RazorpayWebhookController extends Controller
{
    public function __construct(
        private RechargeOrderService $rechargeOrders,
        private MetaAppEventRecorder $metaEvents,
    ) {
    }

    public function __invoke(Request $request)
    {
        try {
            $result = $this->rechargeOrders->processRazorpayWebhook(
                $request->getContent(),
                $request->header('X-Razorpay-Signature'),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $order = $result['order'] ?? null;
        if ($order instanceof PaymentOrder && $order->status === 'success') {
            $this->metaEvents->recordVerifiedPurchase($order, $request);
        }

        return response()->json([
            'ok' => true,
            'data' => $result,
        ]);
    }
}
