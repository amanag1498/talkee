<?php

namespace App\Services;

use App\Models\HostAvailability;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HostAvailabilityService
{
    public function __construct(
        private HostFollowService $follows,
        private HostNotificationService $notifications,
        private ProfileFrameService $frames,
    ) {
    }

    public function ensureForUser(User $user): HostAvailability
    {
        return HostAvailability::firstOrCreate(
            ['user_id' => $user->id],
            ['manual_status' => 'offline', 'socket_status' => 'offline', 'call_status' => 'available']
        );
    }

    public function toggleManualStatus(User $user, string $manualStatus): HostAvailability
    {
        if (!$user->hasRole('host')) {
            abort(403, 'Only hosts can toggle availability.');
        }

        return DB::transaction(function () use ($user, $manualStatus) {
            $availability = $this->ensureForUser($user);
            $before = $availability->replicate();
            $availability->update([
                'manual_status' => $manualStatus,
                'call_status' => $manualStatus === 'offline' && $availability->call_status !== 'busy'
                    ? 'available'
                    : $availability->call_status,
                'current_call_session_id' => $manualStatus === 'offline' && $availability->call_status !== 'busy'
                    ? null
                    : $availability->current_call_session_id,
                'last_seen_at' => now(),
            ]);

            Log::info('HOST_AVAILABILITY_MANUAL_TOGGLE', [
                'user_id' => $user->id,
                'manual_status' => $manualStatus,
            ]);
            $fresh = $availability->fresh();
            $this->publishAvailability($fresh);
            $this->notifications->handleAvailabilityTransition($user, $before, $fresh);
            return $fresh;
        });
    }

    public function updateSocketStatus(int $userId, string $socketStatus): HostAvailability
    {
        return DB::transaction(function () use ($userId, $socketStatus) {
            $user = User::query()->findOrFail($userId);
            $availability = $this->ensureForUser($user);
            $before = $availability->replicate();

            $availability->update([
                'socket_status' => $socketStatus,
                'call_status' => $socketStatus === 'offline' && $availability->call_status !== 'busy'
                    ? 'available'
                    : $availability->call_status,
                'last_seen_at' => now(),
            ]);

            Log::info('HOST_AVAILABILITY_SOCKET_STATUS', [
                'user_id' => $userId,
                'socket_status' => $socketStatus,
                'current_call_session_id' => $availability->current_call_session_id,
            ]);

            if ($socketStatus === 'offline') {
                app(CallSessionService::class)->handleDisconnect($userId);
                $availability->refresh();
            }

            $fresh = $availability->fresh();
            $this->publishAvailability($fresh);
            $this->notifications->handleAvailabilityTransition($user, $before, $fresh);
            return $fresh;
        });
    }

    public function setCallStatus(int $userId, string $callStatus, ?int $callSessionId = null): HostAvailability
    {
        return DB::transaction(function () use ($userId, $callStatus, $callSessionId) {
            $user = User::query()->findOrFail($userId);
            $availability = $this->ensureForUser($user);
            $before = $availability->replicate();

            $availability->update([
                'call_status' => $callStatus,
                'current_call_session_id' => $callSessionId,
                'last_seen_at' => now(),
            ]);

            Log::info('HOST_AVAILABILITY_CALL_STATUS', [
                'user_id' => $userId,
                'call_status' => $callStatus,
                'call_session_id' => $callSessionId,
            ]);
            $fresh = $availability->fresh();
            $this->publishAvailability($fresh);
            $this->notifications->handleAvailabilityTransition($user, $before, $fresh);
            return $fresh;
        });
    }

    public function visibleLiveUsersFor(User $viewer, int $page = 1, int $perPage = 50): LengthAwarePaginator
    {
        $resolvedPerPage = max(1, min($perPage, 100));

        $hostUsers = User::query()
            ->role('host')
            ->with([
                'host.agency',
                'hostAvailability',
            ])
            ->where('is_blocked', false)
            ->whereHas('host')
            ->whereHas('hostAvailability', function ($query) {
                $query
                    ->where('manual_status', 'online')
                    ->where('socket_status', 'online');
            })
            ->paginate($resolvedPerPage, ['*'], 'page', max(1, $page));

        $hostUserCollection = $hostUsers->getCollection();

        $followSummary = $this->follows->decorateHostUsers($hostUserCollection, $viewer);
        $counts = $followSummary['counts'] ?? collect();
        $followingIds = $followSummary['following_ids'] ?? [];

        $viewerWalletBalance = (int) Wallet::query()->where('user_id', $viewer->id)->value('balance');
        $baseMinimumBalance = (int) config('calls.minimum_balance_to_start_call');
        $callSessionService = app(CallSessionService::class);

        $users = $hostUserCollection->map(function (User $hostUser) use ($viewerWalletBalance, $baseMinimumBalance, $callSessionService, $counts, $followingIds) {
            $host = $hostUser->host;
            $availability = $hostUser->hostAvailability;
            $isOnline = $availability->manual_status === 'online' && $availability->socket_status === 'online';
            $isAvailable = $availability->call_status === 'available';
            $reason = $this->availabilityReason($availability);
            $audioRate = $callSessionService->resolveCoinRatePerMinute($host, 'audio');
            $videoRate = $callSessionService->resolveCoinRatePerMinute($host, 'video');
            $audioRequired = max($baseMinimumBalance, $audioRate);
            $videoRequired = max($baseMinimumBalance, $videoRate);
            $canCallAny = $isOnline && $isAvailable && $viewerWalletBalance >= min($audioRequired, $videoRequired);

            return [
                'id' => $hostUser->id,
                'name' => $host?->stage_name ?: $hostUser->name,
                'avatar_url' => $hostUser->avatar_url,
                'profile_frame' => $this->frames->equippedFramePayload($hostUser),
                'roles' => $hostUser->getRoleNames()->values()->all(),
                'badge' => 'host',
                'agency' => $host?->agency ? [
                    'id' => $host->agency->id,
                    'name' => $host->agency->name,
                ] : null,
                'host_profile' => [
                    'host_id' => $host?->id,
                    'stage_name' => $host?->stage_name,
                    'country' => $host?->country,
                    'city' => $host?->city,
                    'bio' => $host?->bio,
                    'audio_call_rate_per_minute' => $host?->audio_call_rate_per_minute,
                    'video_call_rate_per_minute' => $host?->video_call_rate_per_minute,
                ],
                'audio_call_rate_per_minute' => $audioRate,
                'video_call_rate_per_minute' => $videoRate,
                'audio_minimum_balance_required' => $audioRequired,
                'video_minimum_balance_required' => $videoRequired,
                'availability' => [
                    'manual_status' => $availability->manual_status,
                    'socket_status' => $availability->socket_status,
                    'call_status' => $availability->call_status,
                    'current_call_session_id' => $availability->current_call_session_id,
                    'last_seen_at' => optional($availability->last_seen_at)?->toIso8601String(),
                    'is_online' => $isOnline,
                    'is_available' => $isOnline && $isAvailable,
                    'is_busy' => $isOnline && !$isAvailable,
                    'reason' => $reason,
                ],
                'is_online' => $isOnline,
                'is_available' => $isOnline && $isAvailable,
                'is_busy' => $isOnline && !$isAvailable,
                'is_following' => isset($followingIds[(int) $host?->id]),
                'follower_count' => (int) ($counts[$host?->id] ?? 0),
                'can_call' => $canCallAny,
                'call_unavailable_reason' => !$canCallAny && $viewerWalletBalance < min($audioRequired, $videoRequired)
                    ? 'viewer_low_balance'
                    : $reason,
                'unavailable_reason' => !$canCallAny && $viewerWalletBalance < min($audioRequired, $videoRequired)
                    ? 'viewer_low_balance'
                    : $reason,
            ];
        })->sortBy(function (array $user) {
            if (($user['is_available'] ?? false)) {
                return 0;
            }
            if (($user['is_busy'] ?? false)) {
                return 1;
            }

            return 2;
        })->values()->all();

        $hostUsers->setCollection(collect([
            'viewer_balance' => $viewerWalletBalance,
            'minimum_balance_to_start_call' => $baseMinimumBalance,
            'audio_call_rate_per_minute' => (int) (config('calls.audio_coin_rate_per_minute') ?: config('calls.coin_rate_per_minute')),
            'video_call_rate_per_minute' => (int) (config('calls.video_coin_rate_per_minute') ?: config('calls.coin_rate_per_minute')),
            'users' => $users,
        ]));

        return $hostUsers;
    }

    public function cleanupStaleSocketStatuses(int $seconds = 120): int
    {
        $cutoff = now()->subSeconds($seconds);
        $rows = HostAvailability::query()
            ->where('socket_status', 'online')
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff);
            })
            ->get();

        foreach ($rows as $availability) {
            $this->updateSocketStatus($availability->user_id, 'offline');
        }

        return $rows->count();
    }

    private function availabilityReason(HostAvailability $availability): string
    {
        if ($availability->manual_status !== 'online') {
            return 'manually_unavailable';
        }
        if ($availability->socket_status !== 'online') {
            return 'offline';
        }
        if ($availability->call_status !== 'available') {
            return $availability->current_call_session_id ? 'in_another_call' : 'busy';
        }

        return 'available';
    }

    public function publishAvailability(HostAvailability $availability): void
    {
        CallRealtimePublisher::publish('users:availability', [
            'event' => 'user_availability_updated',
            'user_id' => (int) $availability->user_id,
            'manual_status' => $availability->manual_status,
            'socket_status' => $availability->socket_status,
            'call_status' => $availability->call_status,
            'current_call_session_id' => $availability->current_call_session_id,
            'reason' => $this->availabilityReason($availability),
        ]);
    }
}
