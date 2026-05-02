<?php

namespace App\Services;

use App\Models\HostUserBlock;
use App\Models\LiveRoom;
use App\Models\LiveRoomParticipant;
use App\Models\ModerationRule;
use App\Models\ModerationAction;
use App\Models\RoomUserKick;
use App\Models\UnblockRequest;
use App\Models\User;
use App\Models\UserReport;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ModerationService
{
    public function hostUserIdForRoom(LiveRoom $room): ?int
    {
        return optional($room->host)->user_id ? (int) $room->host->user_id : null;
    }

    public function isBlockedByHostUserId(?int $hostUserId, ?int $userId): bool
    {
        if (!$hostUserId || !$userId || $hostUserId === $userId) {
            return false;
        }

        return HostUserBlock::query()
            ->where('host_user_id', $hostUserId)
            ->where('blocked_user_id', $userId)
            ->exists();
    }

    public function assertNotBlockedByHostUserId(?int $hostUserId, ?int $userId, string $message = 'You were blocked by this host.'): void
    {
        if ($this->isBlockedByHostUserId($hostUserId, $userId)) {
            throw new HttpException(403, $message);
        }
    }

    public function canModerateRoom(User $actor, LiveRoom $room): bool
    {
        $hostUserId = $this->hostUserIdForRoom($room);

        return $actor->hasAnyRole(['admin', 'super-admin'])
            || ($hostUserId !== null && (int) $actor->id === (int) $hostUserId);
    }

    public function assertCanModerateRoom(User $actor, LiveRoom $room): void
    {
        if (!$this->canModerateRoom($actor, $room)) {
            throw new HttpException(403, 'You cannot moderate this room.');
        }
    }

    public function hostBlockedUsersQuery(User $hostUser): Builder
    {
        return HostUserBlock::query()
            ->with(['blockedUser.level'])
            ->where('host_user_id', $hostUser->id)
            ->latest('id');
    }

    public function hostModerationHistoryQuery(User $hostUser): Builder
    {
        return ModerationAction::query()
            ->with(['actor', 'targetUser'])
            ->where('host_user_id', $hostUser->id)
            ->latest('id');
    }

    public function adminModerationHistoryQuery(): Builder
    {
        return ModerationAction::query()
            ->with(['actor', 'targetUser', 'hostUser'])
            ->latest('id');
    }

    public function adminBlockedUsersQuery(): Builder
    {
        return HostUserBlock::query()
            ->with(['hostUser', 'blockedUser', 'blockedBy'])
            ->latest('id');
    }

    public function adminReportsQuery(): Builder
    {
        return UserReport::query()
            ->with(['reporter', 'reportedUser', 'hostUser', 'reviewer'])
            ->latest('id');
    }

    public function blockUserForHost(
        User $hostUser,
        User $target,
        User $actor,
        ?string $reason = null,
        ?LiveRoom $room = null,
        ?string $roomType = null,
        bool $systemInitiated = false,
    ): HostUserBlock {
        if ((int) $hostUser->id === (int) $target->id) {
            throw new HttpException(422, 'Host cannot block self.');
        }

        if (
            (int) $actor->id !== (int) $hostUser->id
            && !$actor->hasAnyRole(['admin', 'super-admin'])
        ) {
            throw new HttpException(403, 'Only the host or admin can block this user.');
        }

        [$block, $activeRoomId] = DB::transaction(function () use ($hostUser, $target, $actor, $reason, $room, $roomType, $systemInitiated) {
            $block = HostUserBlock::query()->updateOrCreate(
                [
                    'host_user_id' => $hostUser->id,
                    'blocked_user_id' => $target->id,
                ],
                [
                    'reason' => $reason,
                    'blocked_by_user_id' => $systemInitiated ? null : $actor->id,
                    'blocked_by_role' => $systemInitiated ? 'system' : $this->actorRole($actor, $hostUser),
                ],
            );

            $resolvedRoom = $room;
            if (!$resolvedRoom) {
                $resolvedRoom = LiveRoom::query()
                    ->whereHas('host', fn (Builder $query) => $query->where('user_id', $hostUser->id))
                    ->where('status', 'live')
                    ->whereNull('ended_at')
                    ->latest('id')
                    ->first();
            }

            if ($resolvedRoom) {
                $roomHostUserId = $this->hostUserIdForRoom($resolvedRoom);
                if (
                    (int) $roomHostUserId !== (int) $hostUser->id
                    && !$actor->hasAnyRole(['admin', 'super-admin'])
                ) {
                    throw new HttpException(403, 'You cannot moderate another host\'s room.');
                }
                $this->removeParticipantFromRoom($resolvedRoom, $target, $actor, $reason, 'block');
            }

            $this->recordAction(
                actionType: 'block',
                actor: $systemInitiated ? null : $actor,
                target: $target,
                hostUserId: $hostUser->id,
                roomId: $resolvedRoom?->room_id,
                roomType: $roomType ?? $resolvedRoom?->room_type,
                reason: $reason,
                metadata: ['mode' => $systemInitiated ? 'system_block' : 'host_block'],
            );

            return [$block->fresh(['hostUser', 'blockedUser']), $resolvedRoom?->room_id];
        });

        $this->broadcastModerationEvent('room:user:blocked', [
            'host_user_id' => $hostUser->id,
            'target_user_id' => $target->id,
            'reason' => $reason,
            'room_id' => $activeRoomId,
            'room_type' => $roomType ?? $room?->room_type,
            'message' => sprintf('%s was %s', $target->name ?: 'User', $systemInitiated ? 'blocked by system' : 'blocked by host'),
        ]);
        $this->publishModerationCacheInvalidation('host_blocks', $hostUser->id);

        return $block;
    }

    public function unblockUserForHost(
        User $hostUser,
        User $target,
        User $actor,
        ?string $reason = null,
    ): bool {
        if (
            (int) $actor->id !== (int) $hostUser->id
            && !$actor->hasAnyRole(['admin', 'super-admin'])
        ) {
            throw new HttpException(403, 'Only the host or admin can unblock this user.');
        }

        $deleted = HostUserBlock::query()
            ->where('host_user_id', $hostUser->id)
            ->where('blocked_user_id', $target->id)
            ->delete();

        if ($deleted > 0) {
            $this->recordAction(
                actionType: 'unblock',
                actor: $actor,
                target: $target,
                hostUserId: $hostUser->id,
                roomId: null,
                roomType: null,
                reason: $reason,
                metadata: ['mode' => 'host_unblock'],
            );

            $this->broadcastModerationEvent('room:user:unblocked', [
                'host_user_id' => $hostUser->id,
                'target_user_id' => $target->id,
                'reason' => $reason,
                'message' => sprintf('%s was unblocked by host', $target->name ?: 'User'),
            ]);
            $this->publishModerationCacheInvalidation('host_blocks', $hostUser->id);
        }

        return $deleted > 0;
    }

    public function kickUserFromRoom(
        LiveRoom $room,
        User $target,
        User $actor,
        ?string $reason = null,
        bool $systemInitiated = false,
    ): RoomUserKick {
        $this->assertCanModerateRoom($actor, $room);
        $hostUserId = $this->hostUserIdForRoom($room);

        if ($hostUserId && (int) $target->id === (int) $hostUserId) {
            throw new HttpException(422, 'Host cannot kick self.');
        }

        return DB::transaction(function () use ($room, $target, $actor, $reason, $hostUserId, $systemInitiated) {
            $this->removeParticipantFromRoom($room, $target, $actor, $reason, 'kick');

            $kick = RoomUserKick::query()->create([
                'room_id' => (string) $room->room_id,
                'room_type' => (string) ($room->room_type ?? 'video'),
                'host_user_id' => $hostUserId,
                'kicked_user_id' => $target->id,
                'reason' => $reason,
                'kicked_by_user_id' => $systemInitiated ? null : $actor->id,
            ]);

            $this->recordAction(
                actionType: 'kick',
                actor: $systemInitiated ? null : $actor,
                target: $target,
                hostUserId: $hostUserId,
                roomId: (string) $room->room_id,
                roomType: (string) ($room->room_type ?? 'video'),
                reason: $reason,
                metadata: [
                    'room_db_id' => $room->id,
                    'mode' => $systemInitiated ? 'system_kick' : 'host_kick',
                ],
            );

            $this->broadcastModerationEvent('room:user:kicked', [
                'room_id' => (string) $room->room_id,
                'room_type' => (string) ($room->room_type ?? 'video'),
                'host_user_id' => $hostUserId,
                'target_user_id' => $target->id,
                'reason' => $reason,
                'message' => sprintf('%s was %s', $target->name ?: 'User', $systemInitiated ? 'removed by system' : 'removed by host'),
            ]);

            return $kick;
        });
    }

    public function createReport(User $reporter, array $payload): UserReport
    {
        $reportedUser = User::query()->findOrFail((int) $payload['reported_user_id']);
        if ((int) $reporter->id === (int) $reportedUser->id) {
            throw new HttpException(422, 'You cannot report yourself.');
        }

        $duplicate = UserReport::query()
            ->where('reporter_user_id', $reporter->id)
            ->where('reported_user_id', $reportedUser->id)
            ->where('reason_type', (string) $payload['reason_type'])
            ->where('host_user_id', $payload['host_user_id'] ?? null)
            ->where('room_id', $payload['room_id'] ?? null)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($duplicate) {
            throw new HttpException(409, 'You already submitted a similar report recently.');
        }

        $report = UserReport::query()->create([
            'reporter_user_id' => $reporter->id,
            'reported_user_id' => $reportedUser->id,
            'host_user_id' => $payload['host_user_id'] ?? null,
            'room_id' => $payload['room_id'] ?? null,
            'room_type' => $payload['room_type'] ?? null,
            'reason_type' => (string) $payload['reason_type'],
            'description' => $payload['description'] ?? null,
            'status' => 'pending',
        ]);

        $this->recordAction(
            actionType: 'report',
            actor: $reporter,
            target: $reportedUser,
            hostUserId: $payload['host_user_id'] ?? null,
            roomId: $payload['room_id'] ?? null,
            roomType: $payload['room_type'] ?? null,
            reason: $payload['reason_type'],
            metadata: [
                'description' => $payload['description'] ?? null,
                'report_id' => $report->id,
            ],
        );

        return $report->fresh(['reporter', 'reportedUser', 'hostUser']);
    }

    public function reviewReport(UserReport $report, User $admin, string $status, ?string $adminNotes = null): UserReport
    {
        if (!$admin->hasAnyRole(['admin', 'super-admin'])) {
            throw new HttpException(403, 'Only admin can review reports.');
        }

        if (!in_array($status, UserReport::STATUSES, true)) {
            throw new HttpException(422, 'Invalid review status.');
        }

        $report->update([
            'status' => $status,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'admin_notes' => $adminNotes,
        ]);

        $this->recordAction(
            actionType: 'review',
            actor: $admin,
            target: $report->reportedUser,
            hostUserId: $report->host_user_id,
            roomId: $report->room_id,
            roomType: $report->room_type,
            reason: $status,
            metadata: [
                'report_id' => $report->id,
                'admin_notes' => $adminNotes,
            ],
        );

        return $report->fresh(['reporter', 'reportedUser', 'hostUser', 'reviewer']);
    }

    public function createUnblockRequest(User $requester, User $hostUser, ?string $message = null): UnblockRequest
    {
        if (!$this->isBlockedByHostUserId($hostUser->id, $requester->id)) {
            throw new HttpException(422, 'You are not blocked by this host.');
        }

        $pendingExists = UnblockRequest::query()
            ->where('host_user_id', $hostUser->id)
            ->where('blocked_user_id', $requester->id)
            ->where('status', 'pending')
            ->exists();

        if ($pendingExists) {
            throw new HttpException(409, 'A pending unblock request already exists.');
        }

        $request = UnblockRequest::query()->create([
            'host_user_id' => $hostUser->id,
            'blocked_user_id' => $requester->id,
            'requested_by_user_id' => $requester->id,
            'message' => $message,
            'status' => 'pending',
        ]);

        $this->recordAction(
            actionType: 'appeal_created',
            actor: $requester,
            target: $requester,
            hostUserId: $hostUser->id,
            roomId: null,
            roomType: null,
            reason: $message,
            metadata: ['unblock_request_id' => $request->id],
        );

        return $request->fresh(['hostUser', 'blockedUser', 'requester']);
    }

    public function hostUnblockRequestsQuery(User $hostUser): Builder
    {
        return UnblockRequest::query()
            ->with(['blockedUser.level', 'requester'])
            ->where('host_user_id', $hostUser->id)
            ->latest('id');
    }

    public function approveUnblockRequest(UnblockRequest $request, User $actor): UnblockRequest
    {
        if (
            (int) $actor->id !== (int) $request->host_user_id
            && !$actor->hasAnyRole(['admin', 'super-admin'])
        ) {
            throw new HttpException(403, 'You cannot approve this request.');
        }

        $request->update([
            'status' => 'approved',
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);

        $hostUser = User::query()->findOrFail($request->host_user_id);
        $blockedUser = User::query()->findOrFail($request->blocked_user_id);
        $this->unblockUserForHost($hostUser, $blockedUser, $actor, 'Appeal approved');

        $this->recordAction(
            actionType: 'appeal_approved',
            actor: $actor,
            target: $blockedUser,
            hostUserId: $hostUser->id,
            roomId: null,
            roomType: null,
            reason: 'Appeal approved',
            metadata: ['unblock_request_id' => $request->id],
        );

        return $request->fresh(['hostUser', 'blockedUser', 'requester', 'reviewer']);
    }

    public function rejectUnblockRequest(UnblockRequest $request, User $actor, ?string $notes = null): UnblockRequest
    {
        if (
            (int) $actor->id !== (int) $request->host_user_id
            && !$actor->hasAnyRole(['admin', 'super-admin'])
        ) {
            throw new HttpException(403, 'You cannot reject this request.');
        }

        $request->update([
            'status' => 'rejected',
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);

        $this->recordAction(
            actionType: 'appeal_rejected',
            actor: $actor,
            target: User::query()->findOrFail($request->blocked_user_id),
            hostUserId: $request->host_user_id,
            roomId: null,
            roomType: null,
            reason: $notes,
            metadata: ['unblock_request_id' => $request->id],
        );

        return $request->fresh(['hostUser', 'blockedUser', 'requester', 'reviewer']);
    }

    public function analyticsPayload(): array
    {
        $today = now()->startOfDay();

        return [
            'total_blocks' => HostUserBlock::query()->count(),
            'total_kicks' => RoomUserKick::query()->count(),
            'total_reports' => UserReport::query()->count(),
            'pending_reports' => UserReport::query()->where('status', 'pending')->count(),
            'blocks_today' => HostUserBlock::query()->where('created_at', '>=', $today)->count(),
            'kicks_today' => RoomUserKick::query()->where('created_at', '>=', $today)->count(),
            'auto_moderation_triggers' => ModerationAction::query()
                ->whereIn('action_type', ['auto_warn', 'auto_kick', 'auto_block', 'auto_review'])
                ->count(),
            'top_reported_users' => UserReport::query()
                ->selectRaw('reported_user_id, COUNT(*) as report_count')
                ->with('reportedUser:id,name')
                ->groupBy('reported_user_id')
                ->orderByDesc('report_count')
                ->limit(10)
                ->get(),
            'hosts_with_most_blocks' => HostUserBlock::query()
                ->selectRaw('host_user_id, COUNT(*) as block_count')
                ->with('hostUser:id,name')
                ->groupBy('host_user_id')
                ->orderByDesc('block_count')
                ->limit(10)
                ->get(),
            'actions_by_day' => ModerationAction::query()
                ->selectRaw('DATE(created_at) as action_date, COUNT(*) as action_count')
                ->where('created_at', '>=', now()->subDays(30)->startOfDay())
                ->groupByRaw('DATE(created_at)')
                ->orderBy('action_date')
                ->get(),
            'reason_breakdown' => UserReport::query()
                ->selectRaw('reason_type, COUNT(*) as report_count')
                ->groupBy('reason_type')
                ->orderByDesc('report_count')
                ->get(),
        ];
    }

    public function moderationSnapshotPayload(): array
    {
        $rules = ModerationRule::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get([
                'id',
                'rule_key',
                'rule_type',
                'pattern',
                'threshold',
                'action',
                'duration_minutes',
                'severity',
                'is_active',
                'updated_at',
            ])
            ->map(fn (ModerationRule $rule) => [
                'id' => (int) $rule->id,
                'rule_key' => (string) $rule->rule_key,
                'rule_type' => (string) $rule->rule_type,
                'pattern' => $rule->pattern !== null ? (string) $rule->pattern : null,
                'threshold' => $rule->threshold !== null ? (int) $rule->threshold : null,
                'action' => (string) $rule->action,
                'duration_minutes' => $rule->duration_minutes !== null ? (int) $rule->duration_minutes : null,
                'severity' => (string) $rule->severity,
                'is_active' => (bool) $rule->is_active,
                'updated_at' => optional($rule->updated_at)?->toIso8601String(),
            ])
            ->values()
            ->all();

        $blocks = HostUserBlock::query()
            ->select(['host_user_id', 'blocked_user_id'])
            ->orderBy('host_user_id')
            ->orderBy('blocked_user_id')
            ->get()
            ->groupBy('host_user_id')
            ->map(fn ($rows) => $rows->pluck('blocked_user_id')->map(fn ($id) => (int) $id)->values()->all())
            ->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'rules' => $rules,
            'host_blocks' => $blocks,
        ];
    }

    public function publishModerationCacheInvalidation(string $scope = 'all', ?int $hostUserId = null): void
    {
        Redis::publish('rooms:moderation-events', json_encode([
            'event' => 'moderation:cache:invalidate',
            'scope' => $scope,
            'host_user_id' => $hostUserId,
            'created_at' => now()->toIso8601String(),
        ]));
    }

    public function recordAction(
        string $actionType,
        ?User $actor,
        User $target,
        ?int $hostUserId,
        ?string $roomId,
        ?string $roomType,
        ?string $reason,
        ?array $metadata = null,
    ): ModerationAction {
        return ModerationAction::query()->create([
            'action_type' => $actionType,
            'actor_user_id' => $actor?->id,
            'actor_role' => $actor ? $this->actorRole($actor, $hostUserId ? User::query()->find($hostUserId) : null) : null,
            'target_user_id' => $target->id,
            'host_user_id' => $hostUserId,
            'room_id' => $roomId,
            'room_type' => $roomType,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }

    public function broadcastModerationEvent(string $event, array $payload): void
    {
        $basePayload = [
            ...$payload,
            'created_at' => now()->toIso8601String(),
        ];

        Redis::publish('rooms:moderation-events', json_encode([
            'event' => $event,
            ...$basePayload,
        ]));

        $aliases = match ($event) {
            'room:user:kicked' => ['room:user:kick'],
            'room:user:blocked' => ['room:user:block'],
            'room:user:unblocked' => ['room:user:unblock'],
            default => [],
        };

        foreach ($aliases as $alias) {
            $aliasPayload = $basePayload;
            unset($aliasPayload['message']);
            Redis::publish('rooms:moderation-events', json_encode([
                'event' => $alias,
                ...$aliasPayload,
            ]));
        }
    }

    public function removeParticipantFromRoom(
        LiveRoom $room,
        User $target,
        User $actor,
        ?string $reason,
        string $mode = 'kick',
    ): void {
        $participant = LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->where('user_id', $target->id)
            ->whereNull('left_at')
            ->lockForUpdate()
            ->latest('id')
            ->first();

        if (!$participant) {
            return;
        }

        $leftAt = now();
        $joinedAt = $participant->joined_at ?? $leftAt;
        $meta = $participant->meta ?? [];
        $meta['moderation_reason'] = $reason;
        $meta['moderation_mode'] = $mode;
        $meta['moderated_by_user_id'] = $actor->id;

        $participant->update([
            'removed_by_host' => true,
            'left_at' => $leftAt,
            'duration_seconds' => max(0, $joinedAt->diffInSeconds($leftAt)),
            'meta' => $meta,
        ]);
    }

    private function actorRole(User $actor, ?User $hostUser = null): string
    {
        if ($actor->hasAnyRole(['admin', 'super-admin'])) {
            return 'admin';
        }
        if ($hostUser && (int) $actor->id === (int) $hostUser->id) {
            return 'host';
        }
        if ($actor->hasRole('host')) {
            return 'host';
        }

        return 'user';
    }
}
