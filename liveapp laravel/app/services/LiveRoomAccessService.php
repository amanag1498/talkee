<?php

namespace App\Services;

use App\Models\LiveRoom;
use App\Models\User;
use App\Models\UserSubscription;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LiveRoomAccessService
{
    public function __construct(
        private ModerationService $moderation,
        private UserBlockService $userBlocks,
    ) {
    }

    public function assertCanJoin(LiveRoom $room, ?User $authUser, ?int $userId, string $role): void
    {
        $hostUserId = optional($room->host)->user_id ? (int) $room->host->user_id : null;

        $this->moderation->assertNotBlockedByHostUserId(
            $hostUserId,
            $userId,
        );

        if ($role === 'host') {
            return;
        }

        if ($authUser?->hasAnyRole(['admin', 'super-admin'])) {
            return;
        }

        $ownerUserId = optional($room->host)->user_id;
        if ($ownerUserId && $userId === (int) $ownerUserId) {
            return;
        }

        if (!$authUser || !$userId) {
            throw new HttpException(401, 'Login is required to join live rooms.');
        }

        if ($hostUserId && $this->userBlocks->hasBlocked($authUser, $hostUserId)) {
            throw new HttpException(409, 'You blocked this host. Unblock them to join this room.');
        }

        if (($room->room_type ?? 'video') === 'audio') {
            return;
        }

        if (!$this->hasActiveSubscription($authUser)) {
            throw new HttpException(402, 'An active subscription is required to join live rooms.');
        }
    }

    public function hasActiveSubscription(User $user): bool
    {
        $now = now();

        return UserSubscription::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where(function ($query) use ($now) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now->copy()->addSeconds(5));
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', $now->copy()->subSeconds(5));
            })
            ->exists();
    }
}
