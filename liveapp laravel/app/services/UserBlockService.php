<?php

namespace App\Services;

use App\Models\HostFollower;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UserBlockService
{
    public function __construct(private AdminAuditService $audits) {}

    public function blockedUsersQuery(User $blocker): Builder
    {
        return UserBlock::query()
            ->with(['blockedUser.level', 'blockedUser.host'])
            ->where('blocker_user_id', $blocker->id)
            ->latest('id');
    }

    public function blockedUserIds(User|int $blocker): array
    {
        $blockerUserId = $blocker instanceof User ? $blocker->id : $blocker;

        return UserBlock::query()
            ->where('blocker_user_id', $blockerUserId)
            ->pluck('blocked_user_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function hasBlocked(User|int $blocker, User|int $target): bool
    {
        $blockerUserId = $blocker instanceof User ? $blocker->id : $blocker;
        $targetUserId = $target instanceof User ? $target->id : $target;

        if ((int) $blockerUserId === (int) $targetUserId) {
            return false;
        }

        return UserBlock::query()
            ->where('blocker_user_id', $blockerUserId)
            ->where('blocked_user_id', $targetUserId)
            ->exists();
    }

    public function hasBlockBetween(User|int $first, User|int $second): bool
    {
        $firstUserId = $first instanceof User ? $first->id : $first;
        $secondUserId = $second instanceof User ? $second->id : $second;

        if ((int) $firstUserId === (int) $secondUserId) {
            return false;
        }

        return UserBlock::query()
            ->where(function (Builder $query) use ($firstUserId, $secondUserId) {
                $query->where('blocker_user_id', $firstUserId)
                    ->where('blocked_user_id', $secondUserId);
            })
            ->orWhere(function (Builder $query) use ($firstUserId, $secondUserId) {
                $query->where('blocker_user_id', $secondUserId)
                    ->where('blocked_user_id', $firstUserId);
            })
            ->exists();
    }

    public function block(User $blocker, User $target): UserBlock
    {
        if ((int) $blocker->id === (int) $target->id) {
            throw new InvalidArgumentException('You cannot block yourself.');
        }

        return DB::transaction(function () use ($blocker, $target) {
            $block = UserBlock::query()->firstOrCreate([
                'blocker_user_id' => $blocker->id,
                'blocked_user_id' => $target->id,
            ]);

            $blocker->loadMissing('host');
            $target->loadMissing('host');

            if ($target->host) {
                HostFollower::query()
                    ->where('host_id', $target->host->id)
                    ->where('user_id', $blocker->id)
                    ->delete();
            }

            if ($blocker->host) {
                HostFollower::query()
                    ->where('host_id', $blocker->host->id)
                    ->where('user_id', $target->id)
                    ->delete();
            }

            return $block->fresh(['blockedUser.level', 'blockedUser.host']);
        });
    }

    public function unblock(User $blocker, User $target): bool
    {
        return UserBlock::query()
            ->where('blocker_user_id', $blocker->id)
            ->where('blocked_user_id', $target->id)
            ->delete() > 0;
    }

    public function removeByAdmin(UserBlock $block, User $admin, ?string $reason = null): void
    {
        $block->loadMissing(['blocker', 'blockedUser']);

        DB::transaction(function () use ($block, $admin, $reason) {
            $blocker = $block->blocker;
            $blockedUser = $block->blockedUser;
            $before = [
                'id' => (int) $block->id,
                'blocker_user_id' => (int) $block->blocker_user_id,
                'blocked_user_id' => (int) $block->blocked_user_id,
                'created_at' => optional($block->created_at)->toIso8601String(),
            ];

            $block->delete();

            $this->audits->log(
                area: 'moderation',
                action: 'personal_block_removed',
                admin: $admin,
                targetUser: $blockedUser,
                entity: $block,
                before: $before,
                after: ['deleted' => true],
                reason: $reason ?: 'Admin support override',
                meta: [
                    'blocker_user_id' => $blocker?->id,
                    'blocker_name' => $blocker?->name,
                ],
            );
        });
    }

    public function payload(UserBlock $block): array
    {
        $user = $block->blockedUser;

        return [
            'user_id' => (int) $block->blocked_user_id,
            'name' => (string) ($user?->name ?? 'User'),
            'avatar_url' => $user?->avatar_url,
            'level' => $user?->level?->level,
            'is_host' => $user?->host !== null,
            'blocked_at' => optional($block->created_at)->toIso8601String(),
        ];
    }
}
