<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallSession;
use App\Services\CallReportService;
use App\Services\CallSessionService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CallController extends Controller
{
    public function __construct(
        private CallSessionService $callSessionService,
        private CallReportService $callReportService,
    ) {
    }

    public function request(Request $request)
    {
        $data = $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'type' => 'required|in:audio,video',
        ]);

        try {
            $call = $this->callSessionService->requestCall($request->user(), (int) $data['receiver_id'], $data['type']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'msg' => $e->getMessage()], 422);
        }

        $payload = $call->toArray();
        $payload['ringing_timeout_seconds'] = $this->callSessionService->ringingTimeoutSeconds();

        return response()->json(['ok' => true, 'data' => $payload], 201);
    }

    public function accept(Request $request, CallSession $call)
    {
        try {
            $call = $this->callSessionService->acceptCall($call, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'data' => $call]);
    }

    public function reject(Request $request, CallSession $call)
    {
        try {
            $call = $this->callSessionService->rejectCall($call, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'data' => $call]);
    }

    public function end(Request $request, CallSession $call)
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:100',
        ]);

        try {
            $call = $this->callSessionService->endCall($call, $request->user(), $data['reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'data' => $call]);
    }

    public function history(Request $request)
    {
        $report = $this->callReportService->forUserHistory($request, $request->user());
        return response()->json([
            'ok' => true,
            'data' => [
                'items' => $report['calls']->items(),
                'pagination' => [
                    'current_page' => $report['calls']->currentPage(),
                    'last_page' => $report['calls']->lastPage(),
                    'total' => $report['calls']->total(),
                ],
                'summary' => $report['summary'],
            ],
        ]);
    }

    public function token(Request $request, CallSession $call)
    {
        try {
            $token = $this->callSessionService->issueParticipantToken($call, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'data' => $token]);
    }
}
