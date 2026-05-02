<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveRoom;
use App\Models\ModerationAction;
use App\Models\UnblockRequest;
use App\Models\User;
use App\Services\ModerationService;
use Illuminate\Http\Request;

class HostModerationController extends Controller
{
    public function __construct(private ModerationService $moderation)
    {
    }

    public function blockedUsers(Request $request)
    {
        $hostUser = $request->user();
        abort_unless($hostUser && $hostUser->hasRole('host'), 403);

        $rows = $this->moderation->hostBlockedUsersQuery($hostUser)->paginate(20);

        return response()->json([
            'ok' => true,
            'data' => $rows->getCollection()->map(function ($row) {
                return [
                    'user_id' => (int) $row->blocked_user_id,
                    'name' => (string) ($row->blockedUser?->name ?? 'User'),
                    'avatar' => $row->blockedUser?->avatar_url,
                    'level' => $row->blockedUser?->level?->level,
                    'is_vip' => $row->blockedUser?->hasAnyRole(['vip', 'premium']) ?? false,
                    'blocked_at' => optional($row->created_at)->toIso8601String(),
                    'reason' => $row->reason,
                    'blocked_by_role' => $row->blocked_by_role,
                ];
            })->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'has_more' => $rows->hasMorePages(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function blockUser(Request $request)
    {
        $hostUser = $request->user();
        abort_unless($hostUser && $hostUser->hasRole('host'), 403);

        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'reason' => 'nullable|string|max:500',
            'room_id' => 'nullable|string|exists:live_rooms,room_id',
            'room_type' => 'nullable|in:audio,video',
        ]);

        $target = User::query()->findOrFail((int) $data['user_id']);
        $room = !empty($data['room_id'])
            ? LiveRoom::query()->where('room_id', $data['room_id'])->first()
            : null;

        $block = $this->moderation->blockUserForHost(
            $hostUser,
            $target,
            $hostUser,
            $data['reason'] ?? null,
            $room,
            $data['room_type'] ?? null,
        );

        return response()->json(['ok' => true, 'data' => $block]);
    }

    public function unblockUser(Request $request)
    {
        $hostUser = $request->user();
        abort_unless($hostUser && $hostUser->hasRole('host'), 403);

        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $target = User::query()->findOrFail((int) $data['user_id']);
        $this->moderation->unblockUserForHost($hostUser, $target, $hostUser);

        return response()->json(['ok' => true]);
    }

    public function kickUser(Request $request)
    {
        $hostUser = $request->user();
        abort_unless($hostUser && $hostUser->hasRole('host'), 403);

        $data = $request->validate([
            'room_id' => 'required|string|exists:live_rooms,room_id',
            'room_type' => 'required|in:audio,video',
            'user_id' => 'required|integer|exists:users,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $room = LiveRoom::query()->where('room_id', $data['room_id'])->firstOrFail();
        $target = User::query()->findOrFail((int) $data['user_id']);
        $kick = $this->moderation->kickUserFromRoom($room, $target, $hostUser, $data['reason'] ?? null);

        return response()->json(['ok' => true, 'data' => $kick]);
    }

    public function history(Request $request)
    {
        $hostUser = $request->user();
        abort_unless($hostUser && $hostUser->hasRole('host'), 403);

        $query = $this->moderation->hostModerationHistoryQuery($hostUser)
            ->when($request->filled('action_type'), fn ($q) => $q->where('action_type', $request->string('action_type')->trim()))
            ->when($request->filled('user_id'), fn ($q) => $q->where('target_user_id', $request->integer('user_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()));

        $rows = $query->paginate(30);

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

    public function unblockRequests(Request $request)
    {
        $hostUser = $request->user();
        abort_unless($hostUser && $hostUser->hasRole('host'), 403);

        $rows = $this->moderation->hostUnblockRequestsQuery($hostUser)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->trim()))
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

    public function approveUnblockRequest(Request $request, int $id)
    {
        $hostUser = $request->user();
        abort_unless($hostUser && $hostUser->hasRole('host'), 403);

        $row = UnblockRequest::query()->findOrFail($id);
        $row = $this->moderation->approveUnblockRequest($row, $hostUser);

        return response()->json(['ok' => true, 'data' => $row]);
    }

    public function rejectUnblockRequest(Request $request, int $id)
    {
        $hostUser = $request->user();
        abort_unless($hostUser && $hostUser->hasRole('host'), 403);
        $data = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $row = UnblockRequest::query()->findOrFail($id);
        $row = $this->moderation->rejectUnblockRequest($row, $hostUser, $data['notes'] ?? null);

        return response()->json(['ok' => true, 'data' => $row]);
    }
}
