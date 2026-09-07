<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveRoom;
use App\Models\User;
use App\Models\UserReport;
use App\Services\AutoModerationService;
use App\Services\ModerationService;
use App\Services\UserBlockService;
use Illuminate\Http\Request;

class WsModerationController extends Controller
{
    public function __construct(
        private ModerationService $moderation,
        private AutoModerationService $autoModeration,
        private UserBlockService $userBlocks,
    ) {
    }

    private function assertInternal(Request $request): void
    {
        $expected = trim((string) config('services.websocket.internal_key', ''));
        $provided = trim((string) $request->header('X-WS-Internal-Key', ''));

        if ($expected !== '') {
            abort_unless(hash_equals($expected, $provided), 403);
            return;
        }
    }

    public function snapshot(Request $request)
    {
        $this->assertInternal($request);

        return response()->json([
            'ok' => true,
            'data' => $this->moderation->moderationSnapshotPayload(),
        ]);
    }

    public function joinCheck(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);
        $data = $request->validate([
            'room_id' => 'required|string|exists:live_rooms,room_id',
        ]);

        $room = LiveRoom::query()->where('room_id', $data['room_id'])->firstOrFail();
        $hostUserId = $this->moderation->hostUserIdForRoom($room);
        $isBlocked = $this->moderation->isBlockedByHostUserId($hostUserId, $user->id);
        $blockedHost = $hostUserId && $this->userBlocks->hasBlocked($user, $hostUserId);
        if (!$isBlocked && !$blockedHost) {
            $this->autoModeration->clearChatState($room, $user);
        }

        if ($blockedHost) {
            return response()->json([
                'ok' => true,
                'allow' => false,
                'code' => 'YOU_BLOCKED_HOST',
                'reason' => 'You blocked this host. Unblock them to join this room.',
                'host_user_id' => $hostUserId,
                'target_user_id' => (int) $user->id,
            ]);
        }

        return response()->json([
            'ok' => true,
            'allow' => !$isBlocked,
            'reason' => $isBlocked
                ? 'You were blocked by this host.'
                : null,
        ]);
    }

    public function chatCheck(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);
        $data = $request->validate([
            'room_id' => 'required|string|exists:live_rooms,room_id',
            'message' => 'required|string|max:1000',
        ]);

        $room = LiveRoom::query()->where('room_id', $data['room_id'])->firstOrFail();
        $hostUserId = $this->moderation->hostUserIdForRoom($room);
        if ($this->moderation->isBlockedByHostUserId($hostUserId, $user->id)) {
            return response()->json([
                'ok' => true,
                'allow' => false,
                'action' => 'block',
                'message' => 'You were blocked by this host.',
            ]);
        }

        return response()->json([
            'ok' => true,
            ...$this->autoModeration->evaluateChatMessage($room, $user, $data['message']),
        ]);
    }

    public function persistChatAction(Request $request)
    {
        $this->assertInternal($request);

        $data = $request->validate([
            'action_type' => 'required|in:review,kick,block',
            'room_id' => 'required|string|exists:live_rooms,room_id',
            'target_user_id' => 'required|integer|exists:users,id',
            'host_user_id' => 'required|integer|exists:users,id',
            'room_type' => 'nullable|in:audio,video',
            'reason' => 'nullable|string|max:500',
            'message' => 'nullable|string|max:1000',
            'rule_key' => 'nullable|string|max:120',
        ]);

        $room = LiveRoom::query()->where('room_id', $data['room_id'])->firstOrFail();
        $target = User::query()->findOrFail((int) $data['target_user_id']);
        $hostUser = User::query()->findOrFail((int) $data['host_user_id']);
        $reason = $data['reason'] ?? 'Auto moderation';

        if ((int) ($this->moderation->hostUserIdForRoom($room) ?? 0) !== (int) $hostUser->id) {
            abort(422, 'Host does not match room owner.');
        }

        return match ($data['action_type']) {
            'review' => $this->persistReview($room, $target, $hostUser, $reason, $data),
            'kick' => $this->persistKick($room, $target, $hostUser, $reason),
            'block' => $this->persistBlock($room, $target, $hostUser, $reason, $data),
        };
    }

    private function persistReview(LiveRoom $room, User $target, User $hostUser, string $reason, array $data)
    {
        $message = trim((string) ($data['message'] ?? ''));
        $description = $message !== '' ? $reason.' | '.$message : $reason;
        $report = UserReport::query()
            ->where('reporter_user_id', $hostUser->id)
            ->where('reported_user_id', $target->id)
            ->where('reason_type', 'auto_moderation')
            ->where('room_id', (string) $room->room_id)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->latest('id')
            ->first();

        if (!$report) {
            $report = $this->moderation->createReport($hostUser, [
                'reported_user_id' => $target->id,
                'host_user_id' => $hostUser->id,
                'room_id' => (string) $room->room_id,
                'room_type' => (string) ($data['room_type'] ?? $room->room_type ?? 'video'),
                'reason_type' => 'auto_moderation',
                'description' => $description,
            ]);
        } elseif ($message !== '') {
            $report->update([
                'description' => $description,
                'status' => 'pending',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'admin_notes' => null,
            ]);
        }

        $this->moderation->recordAction(
            actionType: 'auto_review',
            actor: null,
            target: $target,
            hostUserId: $hostUser->id,
            roomId: (string) $room->room_id,
            roomType: (string) ($data['room_type'] ?? $room->room_type ?? 'video'),
            reason: $reason,
            metadata: [
                'rule_key' => $data['rule_key'] ?? null,
                'message' => $message !== '' ? $message : null,
                'report_id' => $report->id,
            ],
        );

        return response()->json(['ok' => true, 'data' => ['report_id' => $report->id]]);
    }

    private function persistKick(LiveRoom $room, User $target, User $hostUser, string $reason)
    {
        $kick = $this->moderation->kickUserFromRoom($room, $target, $hostUser, $reason, true);

        $this->moderation->recordAction(
            actionType: 'auto_kick',
            actor: null,
            target: $target,
            hostUserId: $hostUser->id,
            roomId: (string) $room->room_id,
            roomType: (string) ($room->room_type ?? 'video'),
            reason: $reason,
            metadata: ['mode' => 'node_cache'],
        );

        return response()->json(['ok' => true, 'data' => ['kick_id' => $kick->id]]);
    }

    private function persistBlock(LiveRoom $room, User $target, User $hostUser, string $reason, array $data)
    {
        $block = $this->moderation->blockUserForHost(
            $hostUser,
            $target,
            $hostUser,
            $reason,
            $room,
            (string) ($data['room_type'] ?? $room->room_type ?? 'video'),
            true,
        );

        $this->moderation->recordAction(
            actionType: 'auto_block',
            actor: null,
            target: $target,
            hostUserId: $hostUser->id,
            roomId: (string) $room->room_id,
            roomType: (string) ($data['room_type'] ?? $room->room_type ?? 'video'),
            reason: $reason,
            metadata: ['mode' => 'node_cache'],
        );

        return response()->json(['ok' => true, 'data' => ['block_id' => $block->id]]);
    }
}
