<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SevenUpDownService;
use Illuminate\Http\Request;

class SevenUpDownController extends Controller
{
    public function __construct(private SevenUpDownService $sevenUpDown) {}

    public function snapshot(Request $request)
    {
        return response()->json([
            'ok' => true,
            'data' => $this->sevenUpDown->snapshotForUser($request->user()),
        ]);
    }

    public function history()
    {
        return response()->json([
            'ok' => true,
            'data' => $this->sevenUpDown->historyPayload(),
        ]);
    }

    public function placeBet(Request $request)
    {
        $data = $request->validate([
            'pot' => 'required|string|in:DOWN,SEVEN,UP,down,seven,up',
            'amount' => 'required|integer|min:1',
            'idempotency_key' => 'nullable|string|max:120',
        ]);

        return response()->json([
            'ok' => true,
            'data' => $this->sevenUpDown->placeBet(
                $request->user(),
                (string) $data['pot'],
                (int) $data['amount'],
                $data['idempotency_key'] ?? null,
            ),
        ]);
    }

    public function publicSnapshot()
    {
        return response()->json($this->sevenUpDown->publicRoundSnapshot());
    }

    public function internalSnapshot(Request $request)
    {
        $this->assertInternal($request);

        return response()->json($this->sevenUpDown->publicRoundSnapshot());
    }

    private function assertInternal(Request $request): void
    {
        $expected = trim((string) config('services.websocket.internal_key', ''));
        $provided = trim((string) $request->header('X-WS-Internal-Key', ''));

        if ($expected !== '') {
            abort_unless(hash_equals($expected, $provided), 403);
        }
    }
}
