<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FortuneWheelService;
use Illuminate\Http\Request;

class FortuneWheelController extends Controller
{
    public function __construct(private FortuneWheelService $fortuneWheel) {}

    public function snapshot(Request $request)
    {
        return response()->json([
            'ok' => true,
            'data' => $this->fortuneWheel->snapshot($request->user()),
        ]);
    }

    public function spin(Request $request)
    {
        $data = $request->validate([
            'idempotency_key' => 'nullable|string|max:150',
        ]);

        return response()->json([
            'ok' => true,
            'data' => $this->fortuneWheel->spin(
                $request->user(),
                $data['idempotency_key'] ?? $request->header('Idempotency-Key'),
            ),
        ]);
    }

    public function history(Request $request)
    {
        return response()->json([
            'ok' => true,
            'data' => $this->fortuneWheel->history($request->user()),
        ]);
    }
}
