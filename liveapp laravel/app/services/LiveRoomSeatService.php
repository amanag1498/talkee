<?php

namespace App\Services;

use App\Models\LiveRoom;
use App\Models\LiveRoomAdminAudit;
use App\Models\LiveRoomParticipant;
use App\Models\LiveRoomSeatRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Models\UserSubscription;

class LiveRoomSeatService
{
    private function configuredMaxParticipants(LiveRoom $room): int
    {
        $roomType = (string) ($room->room_type ?? 'video');
        $fallback = $roomType === 'audio' ? 50 : 12;

        return max(2, (int) config("live_rooms.{$roomType}.max_participants", $fallback));
    }

    private function configuredMaxSpeakersForRoom(LiveRoom $room): int
    {
        $roomType = (string) ($room->room_type ?? 'video');
        $fallback = $roomType === 'audio' ? 8 : 4;

        return max(1, (int) config("live_rooms.{$roomType}.max_speakers", $fallback));
    }

    public function __construct(
        private LiveKitRoomAdminService $liveKit,
        private LiveRoomStateService $state,
        private LiveRoomPkService $pk,
        private ThemeUnlockService $themes,
        private ProfileFrameService $frames,
    ) {
    }

    private function participantThemePayload(?User $user, string $role): array
    {
        if (!$user) {
            return [
                'active_theme_key' => ThemeUnlockService::FALLBACK_THEME_KEY,
                'is_vip' => false,
                'is_host' => $role === 'host',
                'level' => null,
                'avatar' => null,
                'avatar_url' => null,
                'profile_frame' => null,
            ];
        }

        $user->loadMissing('level');
        $roleNames = method_exists($user, 'getRoleNames')
            ? $user->getRoleNames()->map(fn ($roleName) => strtolower((string) $roleName))->values()->all()
            : [];
        $activePlan = UserSubscription::query()
            ->with('plan:id,name')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()->copy()->addSeconds(5));
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now()->copy()->subSeconds(5));
            })
            ->latest('ends_at')
            ->latest('id')
            ->first();

        $planName = strtolower((string) ($activePlan?->plan?->name ?? ''));
        $isVip = in_array('vip', $roleNames, true)
            || in_array('premium', $roleNames, true)
            || str_contains($planName, 'vip')
            || str_contains($planName, 'premium')
            || str_contains($planName, 'elite')
            || str_contains($planName, 'platinum')
            || str_contains($planName, 'gold');

        return [
            'active_theme_key' => $this->themes->activeThemeKeyFor($user, true),
            'is_vip' => $isVip,
            'is_host' => $role === 'host',
            'level' => $user->level?->level !== null ? (int) $user->level->level : null,
            'avatar' => $user->avatar_url,
            'avatar_url' => $user->avatar_url,
            'profile_frame' => $this->frames->equippedFramePayload($user),
        ];
    }

    public function requestSeat(LiveRoom $room, User $user): LiveRoomSeatRequest
    {
        $this->assertRoomJoinable($room);
        if ($this->pk->activeForRoom($room)) {
            throw new HttpException(409, 'Speaker requests are locked during an active PK battle.');
        }
        if ((bool) ($room->is_locked ?? false)) {
            throw new HttpException(409, 'Room is locked.');
        }

        $request = DB::transaction(function () use ($room, $user) {
            $participant = $this->activeParticipantQuery($room, $user->id)
                ->lockForUpdate()
                ->first();

            if (!$participant) {
                throw new HttpException(409, 'Join the room before requesting a seat.');
            }

            $audienceRole = $this->audienceRole($room);

            if ($participant->role !== $audienceRole) {
                $latest = $this->latestSeatRequestQuery($room, $user->id)->lockForUpdate()->first();
                if ($participant->role === 'speaker' && $latest?->status === 'accepted') {
                    return $latest;
                }
                throw new HttpException(409, sprintf('Only active %ss can request a seat.', $audienceRole));
            }

            if ($this->activeSpeakerCount($room) >= $this->maxSpeakers($room)) {
                throw new HttpException(409, 'Speaker limit reached.');
            }

            $existing = $this->pendingSeatRequestQuery($room, $user->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return LiveRoomSeatRequest::query()->create([
                'live_room_id' => $room->id,
                'user_id' => $user->id,
                'status' => 'pending',
                'requested_at' => now(),
            ]);
        });

        if ($this->speakerRequestsAutoApprove()) {
            Log::info('LIVE_ROOM_SEAT_REQUEST_AUTO_APPROVING', [
                'room_id' => $room->room_id,
                'room_type' => $room->room_type,
                'request_id' => $request->id,
                'user_id' => $user->id,
            ]);

            return $this->acceptRequest($room, $request, $user, automatic: true);
        }

        $this->state->touchRoom($room->fresh());
        $this->publishSeatEvent('seat:request_created', $room, $request, $this->audienceRole($room));

        return $request;
    }

    public function rejectRequest(LiveRoom $room, LiveRoomSeatRequest $seatRequest, User $actor, ?string $reason = null): LiveRoomSeatRequest
    {
        $this->assertCanModerate($room, $actor);

        $result = DB::transaction(function () use ($room, $seatRequest, $actor, $reason) {
            $request = LiveRoomSeatRequest::query()
                ->whereKey($seatRequest->id)
                ->where('live_room_id', $room->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status === 'rejected') {
                return $request;
            }

            if ($request->status !== 'pending') {
                throw new HttpException(409, 'Only pending requests can be rejected.');
            }

            $request->update([
                'status' => 'rejected',
                'responded_at' => now(),
                'responded_by' => $actor->id,
                'remove_reason' => $reason,
            ]);

            return $request->fresh();
        });

        $this->publishSeatEvent('seat:request_rejected', $room, $result, $this->audienceRole($room));
        $this->audit($room, $actor, $result->user_id, 'seat_request_rejected', 'pending', 'rejected', $reason);

        return $result;
    }

    public function cancelRequest(LiveRoom $room, User $user): ?LiveRoomSeatRequest
    {
        $request = DB::transaction(function () use ($room, $user) {
            $locked = $this->pendingSeatRequestQuery($room, $user->id)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                return $this->latestSeatRequestQuery($room, $user->id)->first();
            }

            $locked->update([
                'status' => 'cancelled',
                'responded_at' => now(),
                'responded_by' => $user->id,
            ]);

            return $locked->fresh();
        });

        if ($request) {
            $this->publishSeatEvent('seat:request_cancelled', $room, $request, $this->audienceRole($room));
        }

        return $request;
    }

    public function acceptRequest(
        LiveRoom $room,
        LiveRoomSeatRequest $seatRequest,
        User $actor,
        bool $automatic = false,
    ): LiveRoomSeatRequest
    {
        if (!$automatic) {
            $this->assertCanModerate($room, $actor);
        }
        $this->assertRoomJoinable($room);
        if ($this->pk->activeForRoom($room)) {
            throw new HttpException(409, 'Speaker promotions are locked during an active PK battle.');
        }

        $state = DB::transaction(function () use ($room, $seatRequest, $actor, $automatic) {
            $lockedRoom = LiveRoom::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
            $request = LiveRoomSeatRequest::query()
                ->whereKey($seatRequest->id)
                ->where('live_room_id', $lockedRoom->id)
                ->lockForUpdate()
                ->firstOrFail();

            $participant = $this->activeParticipantQuery($lockedRoom, $request->user_id)
                ->lockForUpdate()
                ->first();

            if (!$participant) {
                throw new HttpException(409, 'Requested user is no longer in the room.');
            }

            if ($request->status === 'accepted' && $participant->role === 'speaker') {
                return ['request' => $request, 'participant' => $participant];
            }

            if ($request->status !== 'pending') {
                throw new HttpException(409, 'Only pending requests can be accepted.');
            }

            if ($participant->role !== $this->audienceRole($lockedRoom)) {
                throw new HttpException(409, 'Only active audience participants can be promoted to speaker.');
            }

            $activeSpeakerCount = $this->activeSpeakerCount($lockedRoom);
            $maxSpeakers = $this->maxSpeakers($lockedRoom);
            if ($activeSpeakerCount >= $maxSpeakers) {
                throw new HttpException(409, 'Speaker limit reached.');
            }

            $meta = $participant->meta ?? [];
            $meta['speaker_since'] = now()->toIso8601String();

            $participant->update([
                'role' => 'speaker',
                'meta' => $meta,
                'muted_by_host' => false,
                'removed_by_host' => false,
            ]);

            $request->update([
                'status' => 'accepted',
                'responded_at' => now(),
                'responded_by' => $automatic ? null : $actor->id,
            ]);

            return ['request' => $request->fresh(), 'participant' => $participant->fresh()];
        });

        $request = $state['request'];
        $participant = $state['participant'];

        try {
            $this->liveKit->setParticipantCanPublish(
                $room->room_id,
                $this->participantIdentity($participant),
                true,
                $this->publishSourcesForRoom($room),
            );
        } catch (\Throwable $e) {
            $this->rollbackAcceptedSeat($room, $request, $participant);
            Log::error('LIVE_ROOM_SEAT_ACCEPT_ENABLE_PUBLISH_FAILED', [
                'room_id' => $room->room_id,
                'request_id' => $request->id,
                'user_id' => $request->user_id,
                'identity' => $this->participantIdentity($participant),
                'error' => $e->getMessage(),
            ]);
            throw new HttpException(502, 'Unable to enable speaker publishing: '.$e->getMessage());
        }

        $this->state->touchRoom($room->fresh());
        $this->publishSeatEvent('seat:request_accepted', $room, $request, 'speaker');
        $this->publishSeatEvent('speaker:added', $room, $request, 'speaker');
        $this->publishSpeakersUpdated($room, $request->user_id, 'speaker');
        if (!$automatic) {
            $this->audit($room, $actor, $request->user_id, 'seat_request_accepted', 'pending', 'accepted');
        }

        return $request;
    }

    public function removeSpeaker(LiveRoom $room, User $targetUser, User $actor, ?string $reason = null): array
    {
        $this->assertCanModerate($room, $actor);

        $state = DB::transaction(function () use ($room, $targetUser, $reason) {
            $participant = $this->activeParticipantQuery($room, $targetUser->id)
                ->lockForUpdate()
                ->first();

            if (!$participant) {
                throw new HttpException(404, 'Speaker participant not found.');
            }

            $request = LiveRoomSeatRequest::query()
                ->where('live_room_id', $room->id)
                ->where('user_id', $targetUser->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($participant->role !== 'speaker') {
                if ($request && $request->status === 'removed') {
                    return ['request' => $request, 'participant' => $participant];
                }
                throw new HttpException(409, 'Only active speakers can be removed.');
            }

            $meta = $participant->meta ?? [];
            unset($meta['speaker_since']);

            $participant->update([
                'role' => $this->audienceRole($room),
                'meta' => $meta,
                'removed_by_host' => true,
                'muted_by_host' => false,
            ]);

            if ($request && $request->status === 'accepted') {
                $request->update([
                    'status' => 'removed',
                    'removed_at' => now(),
                    'remove_reason' => $reason ?: 'removed_by_host',
                    'responded_at' => now(),
                ]);
            }

            return ['request' => $request?->fresh(), 'participant' => $participant->fresh()];
        });

        $participant = $state['participant'];
        $request = $state['request'];

        try {
            $this->liveKit->setParticipantCanPublish($room->room_id, $this->participantIdentity($participant), false, []);
        } catch (\Throwable $e) {
            $this->rollbackRemovedSeat($room, $request, $participant);
            throw new HttpException(502, 'Unable to revoke speaker publishing.');
        }

        $eventRequest = $request ?: $this->latestSeatRequestQuery($room, $targetUser->id)->first();
        if ($eventRequest) {
            $this->publishSeatEvent('speaker:removed', $room, $eventRequest, 'viewer');
            $this->publishSpeakersUpdated($room, $targetUser->id, 'viewer');
        }

        $this->audit(
            $room,
            $actor,
            $targetUser->id,
            'speaker_removed',
            'accepted',
            'removed',
            $reason ?: 'removed_by_host'
        );

        return $state;
    }

    public function muteSpeaker(LiveRoom $room, User $targetUser, User $actor): array
    {
        $this->assertCanModerate($room, $actor);

        $state = DB::transaction(function () use ($room, $targetUser) {
            $participant = $this->activeParticipantQuery($room, $targetUser->id)
                ->lockForUpdate()
                ->first();

            if (!$participant) {
                throw new HttpException(404, 'Speaker participant not found.');
            }

            if ($participant->role !== 'speaker') {
                throw new HttpException(409, 'Only active speakers can be muted.');
            }

            if ((bool) $participant->muted_by_host) {
                return ['participant' => $participant];
            }

            $participant->update([
                'muted_by_host' => true,
            ]);

            return ['participant' => $participant->fresh()];
        });

        $freshRoom = $room->fresh();
        Redis::publish('rooms:seat-events', json_encode([
            'event' => 'speaker:muted',
            'room_id' => (string) $freshRoom->room_id,
            'room_type' => (string) ($freshRoom->room_type ?? 'video'),
            'user_id' => (int) $targetUser->id,
            'role' => 'speaker',
            'speaker_count' => $this->activeSpeakerCount($freshRoom),
            'listener_count' => $this->activeAudienceCount($freshRoom),
            'participant_count' => $this->activeParticipantCount($freshRoom),
            'max_speakers' => $this->maxSpeakers($freshRoom),
            'updated_at' => now()->toIso8601String(),
        ]));

        $this->audit($room, $actor, $targetUser->id, 'speaker_muted', 'speaker', 'speaker', 'muted_by_host');

        return $state;
    }

    public function unmuteSpeaker(LiveRoom $room, User $targetUser, User $actor): array
    {
        $this->assertCanModerate($room, $actor);

        $state = DB::transaction(function () use ($room, $targetUser) {
            $participant = $this->activeParticipantQuery($room, $targetUser->id)
                ->lockForUpdate()
                ->first();

            if (!$participant) {
                throw new HttpException(404, 'Speaker participant not found.');
            }

            if ($participant->role !== 'speaker') {
                throw new HttpException(409, 'Only active speakers can be unmuted.');
            }

            if (!(bool) $participant->muted_by_host) {
                return ['participant' => $participant];
            }

            $participant->update([
                'muted_by_host' => false,
            ]);

            return ['participant' => $participant->fresh()];
        });

        $freshRoom = $room->fresh();
        Redis::publish('rooms:seat-events', json_encode([
            'event' => 'speaker:unmuted',
            'room_id' => (string) $freshRoom->room_id,
            'room_type' => (string) ($freshRoom->room_type ?? 'video'),
            'user_id' => (int) $targetUser->id,
            'role' => 'speaker',
            'speaker_count' => $this->activeSpeakerCount($freshRoom),
            'listener_count' => $this->activeAudienceCount($freshRoom),
            'participant_count' => $this->activeParticipantCount($freshRoom),
            'max_speakers' => $this->maxSpeakers($freshRoom),
            'updated_at' => now()->toIso8601String(),
        ]));

        $this->audit($room, $actor, $targetUser->id, 'speaker_unmuted', 'speaker', 'speaker', 'muted_by_host');

        return $state;
    }

    public function snapshot(LiveRoom $room, User $actor, array $filters = []): array
    {
        $query = LiveRoomSeatRequest::query()
            ->with(['user:id,name,email', 'responder:id,name,email'])
            ->where('live_room_id', $room->id)
            ->latest('id');

        if (!$this->canModerate($room, $actor)) {
            $query->where('user_id', $actor->id);
        }

        if ($status = trim((string) ($filters['status'] ?? ''))) {
            $query->where('status', $status);
        }

        $requests = $query->get()->map(fn (LiveRoomSeatRequest $request) => $this->seatRequestPayload($room, $request));

        $speakers = LiveRoomParticipant::query()
            ->with(['user:id,name,email,avatar_url,level_id', 'user.level:id,level'])
            ->where('live_room_id', $room->id)
            ->whereNull('left_at')
            ->where('role', 'speaker')
            ->orderBy('joined_at')
            ->get()
            ->map(function (LiveRoomParticipant $participant) {
                $user = $participant->user;
                return array_merge([
                    'user_id' => (int) $participant->user_id,
                    'name' => $user?->name,
                    'email' => $user?->email,
                    'role' => $participant->role,
                    'muted_by_host' => (bool) $participant->muted_by_host,
                    'joined_at' => optional($participant->joined_at)?->toIso8601String(),
                    'speaker_since' => data_get($participant->meta, 'speaker_since'),
                    'updated_at' => optional($participant->updated_at)?->toIso8601String(),
                ], $this->participantThemePayload($user, $participant->role));
            })->values();

        return [
            'room_id' => $room->room_id,
            'room_type' => (string) ($room->room_type ?? 'video'),
            'speaker_request_approval_mode' => $this->speakerRequestsAutoApprove() ? 'automatic' : 'host',
            'requests' => $requests->values()->all(),
            'speakers' => $speakers->all(),
            'pending_count' => $requests->where('status', 'pending')->count(),
            'speaker_count' => $this->activeSpeakerCount($room),
            'participant_count' => $this->activeParticipantCount($room),
            'listener_count' => $this->activeAudienceCount($room),
            'max_speakers' => $this->maxSpeakers($room),
            'max_participants' => max(1, (int) ($room->max_participants ?? $this->configuredMaxParticipants($room))),
        ];
    }

    public function handleParticipantExit(LiveRoom $room, ?int $userId, ?string $sessionId = null): void
    {
        if (!$userId) {
            return;
        }

        $state = DB::transaction(function () use ($room, $userId) {
            $pending = $this->pendingSeatRequestQuery($room, $userId)
                ->lockForUpdate()
                ->get();
            $latestAccepted = LiveRoomSeatRequest::query()
                ->where('live_room_id', $room->id)
                ->where('user_id', $userId)
                ->where('status', 'accepted')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $leftAt = now();
            foreach ($pending as $request) {
                $request->update([
                    'status' => 'cancelled',
                    'responded_at' => $leftAt,
                    'responded_by' => $userId,
                ]);
            }

            if ($latestAccepted) {
                $latestAccepted->update([
                    'status' => 'removed',
                    'removed_at' => $leftAt,
                    'remove_reason' => 'left_room',
                    'responded_at' => $leftAt,
                ]);
            }

            return [
                'pending' => $pending,
                'accepted' => $latestAccepted?->fresh(),
            ];
        });

        foreach ($state['pending'] as $request) {
            $this->publishSeatEvent('seat:request_cancelled', $room, $request, $this->audienceRole($room));
        }

        if ($state['accepted']) {
            try {
                $this->liveKit->setParticipantCanPublish($room->room_id, 'user:'.$userId, false, []);
            } catch (\Throwable $e) {
                Log::warning('LIVE_ROOM_SPEAKER_REVOKE_ON_EXIT_FAILED', [
                    'room_id' => $room->room_id,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->publishSeatEvent('speaker:removed', $room, $state['accepted'], $this->audienceRole($room));
            $this->publishSpeakersUpdated($room, $userId, $this->audienceRole($room));
        }
    }

    public function endRoom(LiveRoom $room, string $reason, ?User $actor = null): LiveRoom
    {
        $now = now();
        $revokeIdentities = [];

        DB::transaction(function () use ($room, $reason, $now, &$revokeIdentities) {
            $lockedRoom = LiveRoom::query()->lockForUpdate()->findOrFail($room->id);
            if ($lockedRoom->ended_at || $lockedRoom->status === 'ended') {
                return;
            }

            $openParticipants = LiveRoomParticipant::query()
                ->where('live_room_id', $lockedRoom->id)
                ->whereNull('left_at')
                ->lockForUpdate()
                ->get();

            foreach ($openParticipants as $participant) {
                if ($participant->role === 'speaker') {
                    $revokeIdentities[] = $this->participantIdentity($participant);
                    $meta = $participant->meta ?? [];
                    unset($meta['speaker_since']);
                    $participant->role = $this->audienceRole($lockedRoom);
                    $participant->meta = $meta;
                    $participant->muted_by_host = false;
                    $participant->removed_by_host = true;
                }

                $joinedAt = $participant->joined_at ?? $now;
                $participant->left_at = $now;
                $participant->duration_seconds = max(0, $joinedAt->diffInSeconds($now));
                $participant->save();
            }

            LiveRoomSeatRequest::query()
                ->where('live_room_id', $lockedRoom->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get()
                ->each(function (LiveRoomSeatRequest $request) use ($now) {
                    $request->update([
                        'status' => 'cancelled',
                        'responded_at' => $now,
                    ]);
                });

            LiveRoomSeatRequest::query()
                ->where('live_room_id', $lockedRoom->id)
                ->where('status', 'accepted')
                ->lockForUpdate()
                ->get()
                ->each(function (LiveRoomSeatRequest $request) use ($now, $reason) {
                    $request->update([
                        'status' => 'removed',
                        'removed_at' => $now,
                        'remove_reason' => $reason,
                        'responded_at' => $now,
                    ]);
                });

            $lockedRoom->update([
                'status' => 'ended',
                'ended_at' => $now,
                'end_reason' => $reason,
                'last_activity_at' => $now,
            ]);
        });

        foreach ($revokeIdentities as $identity) {
            try {
                $this->liveKit->setParticipantCanPublish($room->room_id, $identity, false);
            } catch (\Throwable $e) {
                Log::error('LIVE_ROOM_END_REVOKE_FAILED', [
                    'room_id' => $room->room_id,
                    'identity' => $identity,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $fresh = $room->fresh();
        LiveRoomBroadcaster::broadcast($fresh, 'ended');

        if ($actor && $this->isAdminLike($actor)) {
            $this->audit($fresh, $actor, null, 'room_force_ended', $room->status, 'ended', $reason);
        }

        return $fresh;
    }

    public function roomConsistency(LiveRoom $room): array
    {
        $openParticipants = LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->whereNull('left_at')
            ->get();

        $pendingWithoutParticipant = LiveRoomSeatRequest::query()
            ->where('live_room_id', $room->id)
            ->where('status', 'pending')
            ->get()
            ->filter(fn (LiveRoomSeatRequest $request) => !$openParticipants->contains(fn ($participant) => $participant->user_id === $request->user_id))
            ->values();

        $speakerWithoutAccepted = $openParticipants
            ->where('role', 'speaker')
            ->filter(function (LiveRoomParticipant $participant) use ($room) {
                return !LiveRoomSeatRequest::query()
                    ->where('live_room_id', $room->id)
                    ->where('user_id', $participant->user_id)
                    ->where('status', 'accepted')
                    ->exists();
            })->values();

        $redisLive = collect(Redis::smembers('rooms:live') ?: []);
        $redisDoc = Redis::get("rooms:room:{$room->room_id}");

        return [
            'live_room_with_no_active_host' => $room->status === 'live' && !$openParticipants->contains(fn ($participant) => $participant->role === 'host'),
            'ended_room_with_open_participants' => $room->status === 'ended' && $openParticipants->isNotEmpty(),
            'pending_request_for_user_not_in_room' => $pendingWithoutParticipant->map->only(['id', 'user_id'])->all(),
            'speaker_role_without_accepted_request' => $speakerWithoutAccepted->map->only(['id', 'user_id', 'role'])->all(),
            'redis_missing_live_room' => $room->status === 'live' && !$redisLive->contains($room->room_id),
            'redis_has_ended_room' => $room->status === 'ended' && $redisLive->contains($room->room_id),
            'redis_missing_doc' => $room->status === 'live' && empty($redisDoc),
        ];
    }

    private function participantIdentity(LiveRoomParticipant $participant): string
    {
        return $participant->user_id ? 'user:'.$participant->user_id : 'guest:'.$participant->session_id;
    }

    private function publishSeatEvent(string $eventName, LiveRoom $room, LiveRoomSeatRequest $request, string $participantRole): void
    {
        $freshRoom = $room->fresh();
        Redis::publish('rooms:seat-events', json_encode([
            'event' => $eventName,
            'room_id' => (string) $freshRoom->room_id,
            'room_type' => (string) ($freshRoom->room_type ?? 'video'),
            'speaker_request_approval_mode' => $this->speakerRequestsAutoApprove() ? 'automatic' : 'host',
            'user_id' => (int) $request->user_id,
            'request_id' => (int) $request->id,
            'status' => (string) $request->status,
            'role' => $participantRole,
            'speaker_count' => $this->activeSpeakerCount($freshRoom),
            'listener_count' => $this->activeAudienceCount($freshRoom),
            'participant_count' => $this->activeParticipantCount($freshRoom),
            'max_speakers' => $this->maxSpeakers($freshRoom),
            'updated_at' => optional($request->updated_at)?->toIso8601String() ?? now()->toIso8601String(),
        ]));
    }

    private function publishSpeakersUpdated(LiveRoom $room, int $userId, string $role): void
    {
        $freshRoom = $room->fresh();
        Redis::publish('rooms:seat-events', json_encode([
            'event' => 'speakers:updated',
            'room_id' => (string) $freshRoom->room_id,
            'room_type' => (string) ($freshRoom->room_type ?? 'video'),
            'speaker_request_approval_mode' => $this->speakerRequestsAutoApprove() ? 'automatic' : 'host',
            'user_id' => $userId,
            'role' => $role,
            'speaker_count' => $this->activeSpeakerCount($freshRoom),
            'listener_count' => $this->activeAudienceCount($freshRoom),
            'participant_count' => $this->activeParticipantCount($freshRoom),
            'max_speakers' => $this->maxSpeakers($freshRoom),
            'updated_at' => now()->toIso8601String(),
        ]));
    }

    private function pendingSeatRequestQuery(LiveRoom $room, int $userId): Builder
    {
        return LiveRoomSeatRequest::query()
            ->where('live_room_id', $room->id)
            ->where('user_id', $userId)
            ->where('status', 'pending');
    }

    private function latestSeatRequestQuery(LiveRoom $room, int $userId): Builder
    {
        return LiveRoomSeatRequest::query()
            ->where('live_room_id', $room->id)
            ->where('user_id', $userId)
            ->latest('id');
    }

    private function activeParticipantQuery(LiveRoom $room, int $userId): Builder
    {
        return LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->latest('id');
    }

    private function seatRequestPayload(LiveRoom $room, LiveRoomSeatRequest $request): array
    {
        $participant = LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->where('user_id', $request->user_id)
            ->latest('id')
            ->first();

        return [
            'id' => (int) $request->id,
            'request_id' => (int) $request->id,
            'user_id' => (int) $request->user_id,
            'user' => $request->user ? [
                'id' => (int) $request->user->id,
                'name' => $request->user->name,
                'email' => $request->user->email,
                'avatar_url' => $request->user->avatar_url,
                'profile_frame' => $this->frames->equippedFramePayload($request->user),
            ] : null,
            'status' => (string) $request->status,
            'requested_at' => optional($request->requested_at)->toIso8601String(),
            'responded_at' => optional($request->responded_at)->toIso8601String(),
            'responded_by' => $request->responded_by,
            'responded_by_user' => $request->responder ? [
                'id' => (int) $request->responder->id,
                'name' => $request->responder->name,
            ] : null,
            'removed_at' => optional($request->removed_at)->toIso8601String(),
            'remove_reason' => $request->remove_reason,
            'role' => $participant?->role ?? $this->audienceRole($room),
            'participant_joined_at' => optional($participant?->joined_at)->toIso8601String(),
            'muted_by_host' => (bool) ($participant?->muted_by_host ?? false),
            'removed_by_host' => (bool) ($participant?->removed_by_host ?? false),
            'updated_at' => optional($request->updated_at)->toIso8601String(),
        ] + $this->participantThemePayload($request->user, $participant?->role ?? $this->audienceRole($room));
    }

    private function activeSpeakerCount(LiveRoom $room): int
    {
        return LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->whereNull('left_at')
            ->where('role', 'speaker')
            ->count();
    }

    private function activeParticipantCount(LiveRoom $room): int
    {
        return LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->whereNull('left_at')
            ->count();
    }

    private function activeAudienceCount(LiveRoom $room): int
    {
        return LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->whereNull('left_at')
            ->where('role', $this->audienceRole($room))
            ->count();
    }

    private function maxSpeakers(LiveRoom $room): int
    {
        return max(1, (int) ($room->max_speakers ?? $this->configuredMaxSpeakersForRoom($room)));
    }

    private function rollbackAcceptedSeat(LiveRoom $room, LiveRoomSeatRequest $request, LiveRoomParticipant $participant): void
    {
        Log::warning('LIVE_ROOM_SEAT_ACCEPT_ROLLBACK', [
            'room_id' => $room->room_id,
            'request_id' => $request->id,
            'user_id' => $request->user_id,
        ]);

        DB::transaction(function () use ($room, $request, $participant) {
            $lockedRequest = LiveRoomSeatRequest::query()->lockForUpdate()->findOrFail($request->id);
            $lockedParticipant = LiveRoomParticipant::query()->lockForUpdate()->findOrFail($participant->id);
            $meta = $lockedParticipant->meta ?? [];
            unset($meta['speaker_since']);

            $lockedParticipant->update([
                'role' => $this->audienceRole($room),
                'meta' => $meta,
                'muted_by_host' => false,
                'removed_by_host' => false,
            ]);

            $lockedRequest->update([
                'status' => 'pending',
                'responded_at' => null,
                'responded_by' => null,
            ]);
        });
    }

    private function rollbackRemovedSeat(LiveRoom $room, ?LiveRoomSeatRequest $request, LiveRoomParticipant $participant): void
    {
        Log::warning('LIVE_ROOM_SPEAKER_REMOVE_ROLLBACK', [
            'room_id' => $room->room_id,
            'request_id' => $request?->id,
            'user_id' => $participant->user_id,
        ]);

        DB::transaction(function () use ($request, $participant) {
            $lockedParticipant = LiveRoomParticipant::query()->lockForUpdate()->findOrFail($participant->id);
            $meta = $lockedParticipant->meta ?? [];
            $meta['speaker_since'] = now()->toIso8601String();

            $lockedParticipant->update([
                'role' => 'speaker',
                'meta' => $meta,
                'muted_by_host' => false,
                'removed_by_host' => false,
            ]);

            if ($request) {
                $lockedRequest = LiveRoomSeatRequest::query()->lockForUpdate()->findOrFail($request->id);
                $lockedRequest->update([
                    'status' => 'accepted',
                    'removed_at' => null,
                    'remove_reason' => null,
                ]);
            }
        });
    }

    private function audit(LiveRoom $room, User $actor, ?int $targetUserId, string $action, ?string $before, ?string $after, ?string $reason = null, array $meta = []): void
    {
        if (!$this->isAdminLike($actor)) {
            return;
        }

        LiveRoomAdminAudit::query()->create([
            'live_room_id' => $room->id,
            'admin_id' => $actor->id,
            'target_user_id' => $targetUserId,
            'action' => $action,
            'before_status' => $before,
            'after_status' => $after,
            'reason' => $reason,
            'meta' => $meta,
        ]);
    }

    private function canModerate(LiveRoom $room, User $actor): bool
    {
        return $this->isAdminLike($actor) || optional($room->host)->user_id === $actor->id;
    }

    private function assertCanModerate(LiveRoom $room, User $actor): void
    {
        if (!$this->canModerate($room, $actor)) {
            throw new HttpException(403, 'You are not allowed to manage seat requests for this room.');
        }
    }

    private function isAdminLike(User $actor): bool
    {
        return $actor->hasAnyRole(['admin', 'super-admin']);
    }

    private function assertRoomJoinable(LiveRoom $room): void
    {
        if ($room->status !== 'live' || $room->ended_at) {
            throw new HttpException(409, 'Room is not live.');
        }
    }

    private function audienceRole(LiveRoom $room): string
    {
        return ($room->room_type ?? 'video') === 'audio' ? 'listener' : 'viewer';
    }

    private function speakerRequestsAutoApprove(): bool
    {
        return (bool) config('live_rooms.speaker_requests.auto_approve', false);
    }

    private function publishSourcesForRoom(LiveRoom $room): array
    {
        return ($room->room_type ?? 'video') === 'audio'
            ? ['microphone']
            : ['camera', 'microphone'];
    }
}
