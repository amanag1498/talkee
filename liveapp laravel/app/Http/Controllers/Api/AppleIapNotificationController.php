<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RechargeOrderService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AppleIapNotificationController extends Controller
{
    public function __construct(private RechargeOrderService $rechargeOrders) {}

    public function __invoke(Request $request)
    {
        $signedPayload = trim((string) $request->input('signedPayload', ''));
        if ($signedPayload === '') {
            return response()->json([
                'ok' => false,
                'message' => 'Apple signedPayload is required.',
            ], 422);
        }

        try {
            $result = $this->rechargeOrders->processAppleNotification($signedPayload);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'data' => $result,
        ]);
    }
}
