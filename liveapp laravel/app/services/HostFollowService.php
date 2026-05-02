<?php

namespace App\Services;

use App\Models\Host;
use App\Models\HostAvailability;
use App\Models\HostFollower;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class HostFollowService
{
    public function follow(User $user, Host $host, array $preferences = []): HostFollower
    {
        if ((int) $host->user_id === (int) $user->id) {
            throw new InvalidArgumentException('You cannot follow your own host profile.');
        }

        return HostFollower::query()->updateOrCreate(
            [
                'host_id' => $host->id,
                'user_id' => $user->id,
            ],
            [
                'notify_when_online' => $preferences['notify_when_online'] ?? true,
                'notify_when_available' => $preferences['notify_when_available'] ?? true,
            ],
        );
    }

    public function unfollow(User $user, Host $host): void
    {
        HostFollower::query()
            ->where('host_id', $host->id)
            ->where('user_id', $user->id)
            ->delete();
    }

    public function followState(User $viewer, Host $host): array
    {
        $follow = HostFollower::query()
            ->where('host_id', $host->id)
            ->where('user_id', $viewer->id)
            ->first();

        return [
            'host_id' => $host->id,
            'host_user_id' => $host->user_id,
            'is_following' => $follow !== null,
            'follower_count' => (int) HostFollower::query()->where('host_id', $host->id)->count(),
            'notify_when_online' => $follow?->notify_when_online ?? true,
            'notify_when_available' => $follow?->notify_when_available ?? true,
        ];
    }

    public function profileCounts(User $user): array
    {
        return [
            'following_count' => (int) HostFollower::query()->where('user_id', $user->id)->count(),
            'follower_count' => $user->host
                ? (int) HostFollower::query()->where('host_id', $user->host->id)->count()
                : 0,
        ];
    }

    public function decorateHostUsers(Collection|EloquentCollection $hostUsers, User $viewer): array
    {
        $hostIds = $hostUsers->pluck('host.id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($hostIds->isEmpty()) {
            return [];
        }

        $counts = HostFollower::query()
            ->selectRaw('host_id, COUNT(*) as aggregate_count')
            ->whereIn('host_id', $hostIds)
            ->groupBy('host_id')
            ->pluck('aggregate_count', 'host_id');

        $followingIds = HostFollower::query()
            ->where('user_id', $viewer->id)
            ->whereIn('host_id', $hostIds)
            ->pluck('host_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'counts' => $counts,
            'following_ids' => array_fill_keys($followingIds, true),
        ];
    }

    public function followingList(User $viewer): array
    {
        $rows = HostFollower::query()
            ->with([
                'host.user.roles',
                'host.user.hostAvailability',
                'host.agency',
            ])
            ->where('user_id', $viewer->id)
            ->latest('id')
            ->get();

        return $rows->map(fn (HostFollower $follow) => $this->hostCardPayload($follow->host, $viewer, $follow))->values()->all();
    }

    public function followersList(User $hostUser): array
    {
        $host = $hostUser->host;
        if (!$host) {
            throw new InvalidArgumentException('Only hosts can view followers.');
        }

        $rows = HostFollower::query()
            ->with('user')
            ->where('host_id', $host->id)
            ->latest('id')
            ->get();

        return $rows->map(function (HostFollower $follow) {
            return [
                'id' => $follow->id,
                'user_id' => $follow->user_id,
                'name' => $follow->user?->name,
                'email' => $follow->user?->email,
                'avatar_url' => $follow->user?->avatar_url,
                'notify_when_online' => (bool) $follow->notify_when_online,
                'notify_when_available' => (bool) $follow->notify_when_available,
                'followed_at' => optional($follow->created_at)?->toIso8601String(),
            ];
        })->values()->all();
    }

    public function hostCardPayload(?Host $host, User $viewer, ?HostFollower $existingFollow = null): array
    {
        if (!$host || !$host->user) {
            return [];
        }

        $availability = $host->user->hostAvailability ?? app(HostAvailabilityService::class)->ensureForUser($host->user);
        $isOnline = $availability->manual_status === 'online' && $availability->socket_status === 'online';
        $isAvailable = $isOnline && $availability->call_status === 'available';
        $isBusy = $isOnline && !$isAvailable;
        $audioRate = app(CallSessionService::class)->resolveCoinRatePerMinute($host, 'audio');
        $videoRate = app(CallSessionService::class)->resolveCoinRatePerMinute($host, 'video');
        $minimum = (int) config('calls.minimum_balance_to_start_call');
        $state = $existingFollow
            ? [
                'is_following' => true,
                'notify_when_online' => (bool) $existingFollow->notify_when_online,
                'notify_when_available' => (bool) $existingFollow->notify_when_available,
              ]
            : $this->followState($viewer, $host);

        return [
            'id' => $host->user->id,
            'host_id' => $host->id,
            'name' => $host->stage_name ?: $host->user->name,
            'avatar_url' => $host->user->avatar_url,
            'agency' => $host->agency ? [
                'id' => $host->agency->id,
                'name' => $host->agency->name,
            ] : null,
            'audio_call_rate_per_minute' => $audioRate,
            'video_call_rate_per_minute' => $videoRate,
            'audio_minimum_balance_required' => max($minimum, $audioRate),
            'video_minimum_balance_required' => max($minimum, $videoRate),
            'availability' => [
                'is_online' => $isOnline,
                'is_available' => $isAvailable,
                'is_busy' => $isBusy,
                'manual_status' => $availability->manual_status,
                'socket_status' => $availability->socket_status,
                'call_status' => $availability->call_status,
            ],
            'is_following' => (bool) $state['is_following'],
            'follower_count' => (int) ($state['follower_count'] ?? HostFollower::query()->where('host_id', $host->id)->count()),
            'notify_when_online' => (bool) ($state['notify_when_online'] ?? true),
            'notify_when_available' => (bool) ($state['notify_when_available'] ?? true),
        ];
    }
}
