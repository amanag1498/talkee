<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GreedyGameService;
use Illuminate\Http\Request;

class GreedyGameController extends Controller
{
    public function __construct(private GreedyGameService $greedy)
    {
    }

    public function snapshot(Request $request)
    {
        return response()->json([
            'ok' => true,
            'data' => $this->greedy->snapshotForUser($request->user()),
        ]);
    }

    public function history()
    {
        return response()->json([
            'ok' => true,
            'data' => $this->greedy->historyPayload(),
        ]);
    }

    public function placeBet(Request $request)
    {
        $data = $request->validate([
            'pot' => 'required|string|in:A,B,C,D,a,b,c,d',
            'amount' => 'required|integer|min:1',
            'idempotency_key' => 'nullable|string|max:120',
        ]);

        return response()->json([
            'ok' => true,
            'data' => $this->greedy->placeBet(
                $request->user(),
                (string) $data['pot'],
                (int) $data['amount'],
                $data['idempotency_key'] ?? null,
            ),
        ]);
    }

    public function publicSnapshot()
    {
        return response()->json($this->greedy->publicRoundSnapshot());
    }

    public function internalSnapshot(Request $request)
    {
        $this->assertInternal($request);

        return response()->json($this->greedy->publicRoundSnapshot());
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
