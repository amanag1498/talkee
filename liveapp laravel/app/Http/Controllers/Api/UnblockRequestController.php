<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UnblockRequest;
use App\Models\User;
use App\Services\ModerationService;
use Illuminate\Http\Request;

class UnblockRequestController extends Controller
{
    public function __construct(private ModerationService $moderation)
    {
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);

        $data = $request->validate([
            'host_user_id' => 'required|integer|exists:users,id',
            'message' => 'nullable|string|max:1000',
        ]);

        $hostUser = User::query()->findOrFail((int) $data['host_user_id']);
        $row = $this->moderation->createUnblockRequest($user, $hostUser, $data['message'] ?? null);

        return response()->json(['ok' => true, 'data' => $row], 201);
    }

    public function mine(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);

        $data = $request->validate([
            'host_user_id' => 'nullable|integer|exists:users,id',
            'status' => 'nullable|in:pending,approved,rejected,cancelled',
        ]);

        $rows = UnblockRequest::query()
            ->with(['hostUser', 'blockedUser', 'requester', 'reviewer'])
            ->where('blocked_user_id', $user->id)
            ->when(
                isset($data['host_user_id']),
                fn ($q) => $q->where('host_user_id', (int) $data['host_user_id'])
            )
            ->when(
                isset($data['status']) && $data['status'] !== '',
                fn ($q) => $q->where('status', (string) $data['status'])
            )
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'ok' => true,
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'has_more' => $rows->hasMorePages(),
                'total' => $rows->total(),
            ],
        ]);
    }
}
