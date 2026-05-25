<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TeenPattiService;
use Illuminate\Http\Request;

class TeenPattiController extends Controller
{
    public function __construct(private TeenPattiService $teenPatti)
    {
    }

    public function snapshot(Request $request)
    {
        return response()->json([
            'ok' => true,
            'data' => $this->teenPatti->snapshotForUser($request->user()),
        ]);
    }

    public function history()
    {
        return response()->json([
            'ok' => true,
            'data' => $this->teenPatti->historyPayload(),
        ]);
    }

    public function placeBet(Request $request)
    {
        $data = $request->validate([
            'pot' => 'required|string|in:A,B,C,a,b,c',
            'amount' => 'required|integer|min:1',
            'idempotency_key' => 'nullable|string|max:120',
        ]);

        return response()->json([
            'ok' => true,
            'data' => $this->teenPatti->placeBet(
                $request->user(),
                (string) $data['pot'],
                (int) $data['amount'],
                $data['idempotency_key'] ?? null,
            ),
        ]);
    }

    public function publicSnapshot()
    {
        return response()->json($this->teenPatti->publicRoundSnapshot());
    }

    public function internalSnapshot(Request $request)
    {
        $this->assertInternal($request);

        return response()->json($this->teenPatti->publicRoundSnapshot());
    }

    private function assertInternal(Request $request): void
    {
        $expected = trim((string) env('WS_INTERNAL_KEY', ''));
        $provided = trim((string) $request->header('X-WS-Internal-Key', ''));

        if ($expected !== '') {
            abort_unless(hash_equals($expected, $provided), 403);
        }
    }
}
