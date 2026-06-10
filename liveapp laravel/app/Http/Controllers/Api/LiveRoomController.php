<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HostFollower;
use App\Models\Host;
use App\Models\LiveRoom;
use App\Models\LiveRoomParticipant;
use App\Models\LiveRoomReminder;
use App\Services\LiveRoomBroadcaster;
use App\Services\EntryPackService;
use App\Services\LiveRoomPkService;
use App\Services\LiveRoomSeatService;
use App\Services\LiveRoomStateService;
use App\Services\NotifyUser;
use App\Services\ThemeUnlockService;
use App\Services\LivekitToken;
use App\Models\UserSubscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LiveRoomController extends Controller
{
    public function __construct(
        private LiveRoomStateService $state,
        private LiveRoomSeatService $seats,
        private EntryPackService $entryPacks,
        private LiveRoomPkService $pk,
        private ThemeUnlockService $themes,
    )
    {
    }

    private function configuredMaxParticipants(string $roomType): int
    {
        return max(2, (int) config("live_rooms.{$roomType}.max_participants", $roomType === 'audio' ? 50 : 12));
    }

    private function configuredMaxSpeakers(string $roomType): int
    {
        return max(1, (int) config("live_rooms.{$roomType}.max_speakers", $roomType === 'audio' ? 8 : 4));
    }

    private function hostTokenMetadata(User $user, Host $host, string $roomType, string $deviceId, ?int $appVersionCode = null): array
    {
        $user->loadMissing('level');
        $roleNames = method_exists($user, 'getRoleNames')
            ? $user->getRoleNames()->map(fn ($role) => strtolower((string) $role))->values()->all()
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
            'role' => 'host',
            'room_type' => $roomType,
            'device_id' => $deviceId,
            'user_id' => $user->id,
            'name' => $host->stage_name ?? ($user->name ?? 'Host'),
            'active_theme_key' => $this->themes->activeThemeKeyFor($user, true, $appVersionCode),
            'is_vip' => $isVip,
            'is_host' => true,
            'level' => $user->level?->level !== null ? (int) $user->level->level : null,
            'avatar' => $user->avatar_url,
            'avatar_url' => $user->avatar_url,
        ];
    }

    public function index(Request $request)
    {
        $viewer = $request->user();
        abort_unless($viewer, 401);

        $enabledTypes = collect([
            'audio' => (bool) config('app_features.platform.android.audio_rooms_enabled', true),
            'video' => (bool) config('app_features.platform.android.video_rooms_enabled', true),
        ])->filter()->keys()->values();

        abort_if($enabledTypes->isEmpty(), 403, 'Live rooms are currently unavailable.');

        $this->state->syncRedis();

        $rooms = $this->discoverRoomsQuery($request->boolean('include_scheduled'))
            ->whereIn('room_type', $enabledTypes->all())
            ->when($request->filled('room_type'), fn ($q) => $q->where('room_type', $request->string('room_type')->trim()->toString()))
            ->paginate(20);

        return response()->json([
            'ok' => true,
            'data' => $this->state->payloads($rooms->getCollection(), $viewer)->values(),
            'meta' => [
                'current_page' => $rooms->currentPage(),
                'per_page' => $rooms->perPage(),
                'has_more' => $rooms->hasMorePages(),
                'total' => $rooms->total(),
            ],
        ]);
    }

    public function audioIndex(Request $request)
    {
        $request->merge(['room_type' => 'audio']);
        return $this->index($request);
    }

    public function heartbeat(Request $request, LiveRoom $live_room)
    {
        $user = $request->user();
        $ownerUserId = optional($live_room->host)->user_id;
        abort_unless($user && ($user->id === $ownerUserId || $user->hasAnyRole(['admin', 'super-admin'])), 403, 'Not your room.');
        abort_if($live_room->status !== 'live' || $live_room->ended_at, 409, 'Room is not live.');

        $live_room->forceFill(['last_activity_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    public function setReminder(Request $request, LiveRoom $live_room)
    {
        abort_if($live_room->status !== 'scheduled', 422, 'Reminders are only available for scheduled rooms.');
        abort_if($live_room->ended_at !== null, 422, 'This room is no longer active.');

        $reminder = LiveRoomReminder::query()->firstOrCreate(
            [
                'live_room_id' => $live_room->id,
                'user_id' => $request->user()->id,
            ],
            [
                'meta' => ['source' => 'app'],
            ],
        );

        return response()->json([
            'ok' => true,
            'data' => [
                'has_reminder' => true,
                'reminder_id' => $reminder->id,
            ],
        ]);
    }

    public function clearReminder(Request $request, LiveRoom $live_room)
    {
        LiveRoomReminder::query()
            ->where('live_room_id', $live_room->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'ok' => true,
            'data' => [
                'has_reminder' => false,
            ],
        ]);
    }

    /**
     * POST /api/live/rooms
     * - If room_id is omitted: creates a new room (start_now defaults true).
     * - If room_id is provided: starts that existing room.
     */
    public function createOrStart(Request $request)
    {
        $user = $request->user();
        Log::info('LIVE_ROOM_CREATE_OR_START_BEGIN', [
            'request_id' => $request->header('X-Request-Id'),
            'user_id' => $user?->id,
            'ip' => $request->ip(),
        ]);
        abort_unless($user && $user->hasAnyRole(['host', 'admin', 'super-admin']), 403, 'Only hosts can manage rooms.');

        $data = $request->validate([
            'room_id'      => 'nullable|string|exists:live_rooms,room_id',
            'title'        => 'required_without:room_id|string|max:120',
            'room_type'    => 'nullable|in:video,audio',
            'start_now'    => 'nullable|boolean',
            'scheduled_at' => 'nullable|date',
            'max_speakers' => 'nullable|integer|min:1',
            'max_participants' => 'nullable|integer|min:2',
            'is_locked' => 'nullable|boolean',
            'topic' => 'nullable|string|max:120',
            'language' => 'nullable|string|max:32',
            'meta'         => 'nullable|array',
        ]);

        $roomType = (string) ($data['room_type'] ?? 'video');
        $configuredMaxSpeakers = $this->configuredMaxSpeakers($roomType);
        $configuredMaxParticipants = $this->configuredMaxParticipants($roomType);
        $resolvedMaxSpeakers = (int) ($data['max_speakers'] ?? $configuredMaxSpeakers);
        $resolvedMaxParticipants = (int) ($data['max_participants'] ?? $configuredMaxParticipants);
        abort_if($resolvedMaxSpeakers > $configuredMaxSpeakers, 422, "max_speakers cannot exceed the configured {$roomType} room limit of {$configuredMaxSpeakers}.");
        abort_if($resolvedMaxParticipants > $configuredMaxParticipants, 422, "max_participants cannot exceed the configured {$roomType} room limit of {$configuredMaxParticipants}.");
        abort_if($resolvedMaxSpeakers >= $resolvedMaxParticipants, 422, 'max_speakers must be less than max_participants.');

        $host = Host::where('user_id', $user->id)->first();
        abort_unless($host, 403, 'Host profile missing.');
        Log::info('LIVE_ROOM_HOST_PROFILE_OK', ['user_id' => $user->id, 'host_id' => $host->id]);

        $startNow = (bool) ($data['start_now'] ?? true);
        $wsUrl    = (string) config('services.livekit.ws_url', 'ws://localhost:7880');
        $deviceId = (string) $request->header('X-Device-Id', 'dev'); // <- align with client

        // ─── START EXISTING ─────────────────────────────────────────
        if (!empty($data['room_id'])) {
            /** @var LiveRoom $room */
            $room = LiveRoom::where('room_id', $data['room_id'])->firstOrFail();
            Log::info('LIVE_ROOM_START_EXISTING_LOOKUP_OK', ['room_id' => $room->room_id, 'db_id' => $room->id]);
            $effectiveRoomType = (string) ($data['room_type'] ?? $room->room_type ?? 'video');
            abort_if(!$host->roomTypeEnabled($effectiveRoomType), 403, ucfirst($effectiveRoomType).' rooms are disabled for this host.');

            $ownerUserId = optional($room->host)->user_id;
            abort_unless($user->id === $ownerUserId || $user->hasAnyRole(['admin', 'super-admin']), 403, 'Not your room.');
            abort_if($room->ended_at, 409, 'Room already ended.');

            $wasStarted  = (bool) $room->started_at;
            $beforeTitle = $room->title;
            $beforeMeta  = $room->meta ?? [];

            $updates = [];
            if (!empty($data['title'])) {
                $updates['title'] = $data['title'];
            }
            if (array_key_exists('meta', $data)) {
                $updates['meta'] = $data['meta'];
            }
            if (array_key_exists('max_speakers', $data)) {
                $updates['max_speakers'] = $resolvedMaxSpeakers;
            }
            if (array_key_exists('max_participants', $data)) {
                $updates['max_participants'] = $resolvedMaxParticipants;
            }
            if (array_key_exists('is_locked', $data)) {
                $updates['is_locked'] = (bool) $data['is_locked'];
            }
            if (array_key_exists('topic', $data)) {
                $updates['topic'] = $data['topic'];
            }
            if (array_key_exists('language', $data)) {
                $updates['language'] = $data['language'];
            }
            if (array_key_exists('room_type', $data)) {
                $updates['room_type'] = $roomType;
            }
            if (!$room->started_at) {
                $updates['status']     = 'live';
                $updates['started_at'] = now();
            } elseif ($room->status !== 'live') {
                $updates['status'] = 'live';
            }

            if (!empty($updates)) {
                $updates['last_activity_at'] = now();
                $room->update($updates);
                Log::info('LIVE_ROOM_START_EXISTING_UPDATED', ['room_id' => $room->room_id, 'updates' => array_keys($updates)]);
            }

            // ensure host participant open row exists
            $this->ensureHostParticipant($request, $room, $user->id);
            Log::info('LIVE_ROOM_START_EXISTING_HOST_PARTICIPANT_OK', ['room_id' => $room->room_id, 'user_id' => $user->id]);

            // Issue a host token if live
            $hostToken = null;
            $identity  = null;
            if ($room->status === 'live') {
                // Unique identity per device to avoid collisions if user reconnects
                $identity = sprintf('host-%d-%s', $user->id, substr($deviceId, 0, 16));
                $name     = $host->stage_name ?? ($user->name ?? 'Host');

                $hostToken = LivekitToken::issue(
                    roomId:   $room->room_id,
                    identity: $identity,
                    name:     $name,
                    role:     'host',
                    roomType: (string) ($room->room_type ?? $roomType),
                    ttlSec:   (int) config('services.livekit.ttl', 3600),
                    metadata: $this->hostTokenMetadata($user, $host, (string) ($room->room_type ?? $roomType), $deviceId)
                );
                Log::info('LIVE_ROOM_START_EXISTING_TOKEN_ISSUED', ['room_id' => $room->room_id, 'identity' => $identity]);
            }

            $entryEffect = null;
            if ($hostToken) {
                try {
                    $entryEffect = $this->entryPacks->maybeTriggerRoomEntryEffect($room, $user);
                } catch (\Throwable $e) {
                    Log::warning('LIVE_ROOM_START_EXISTING_ENTRY_EFFECT_FAILED', [
                        'room_id' => $room->room_id,
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // broadcast
            if (!$wasStarted && $room->status === 'live') {
                LiveRoomBroadcaster::broadcast($room, 'live');
                $this->notifyScheduledRoomLive($room, $host, $user);
                $this->notifyLiveRoomStartedFollowers($room, $host, $user, LiveRoomReminder::query()
                    ->where('live_room_id', $room->id)
                    ->pluck('user_id'));
                Log::info('LIVE_ROOM_START_EXISTING_BROADCAST', ['room_id' => $room->room_id, 'event' => 'live']);
            } else {
                $afterMeta = $room->meta ?? [];
                $changed = ($beforeTitle !== $room->title) || (json_encode($beforeMeta) !== json_encode($afterMeta));
                LiveRoomBroadcaster::broadcast($room, $changed ? 'updated' : 'live');
                Log::info('LIVE_ROOM_START_EXISTING_BROADCAST', [
                    'room_id' => $room->room_id,
                    'event' => $changed ? 'updated' : 'live',
                ]);
            }

            return response()->json([
                'ok'       => true,
                'room'     => $this->roomPayload($room),
                'ws_url'   => $wsUrl,
                'token'    => $hostToken,
                'identity' => $identity,
                'role'     => $hostToken ? 'host' : null,
                'entry_effect' => $entryEffect,
            ]);
        }

        // ─── CREATE (optionally start immediately) ──────────────────
        $hostToken = null;
        $identity  = null;

        /** @var LiveRoom $room */
        $room = DB::transaction(function () use ($request, $host, $data, $startNow, $user, $deviceId, $roomType, $resolvedMaxSpeakers, $resolvedMaxParticipants, &$hostToken, &$identity) {
            abort_if(!$host->roomTypeEnabled($roomType), 403, ucfirst($roomType).' rooms are disabled for this host.');
            $roomId = $this->generateRoomId();

            $room = LiveRoom::create([
                'host_id'      => $host->id,
                'room_id'      => $roomId,
                'title'        => $data['title'],
                'room_type'    => $roomType,
                'status'       => $startNow ? 'live' : 'scheduled',
                'scheduled_at' => $data['scheduled_at'] ?? ($startNow ? now() : null),
                'started_at'   => $startNow ? now() : null,
                'last_activity_at' => now(),
                'peak_viewers' => 0,
                'max_speakers' => $resolvedMaxSpeakers,
                'max_participants' => $resolvedMaxParticipants,
                'is_locked' => (bool) ($data['is_locked'] ?? false),
                'topic' => $data['topic'] ?? null,
                'language' => $data['language'] ?? null,
                'meta'         => $data['meta'] ?? null,
            ]);
            Log::info('LIVE_ROOM_CREATE_DB_ROW_OK', ['room_id' => $room->room_id, 'db_id' => $room->id, 'start_now' => $startNow]);

            if ($startNow) {
                // create host participant entry immediately
                $this->ensureHostParticipant($request, $room, $host->user_id);
                Log::info('LIVE_ROOM_CREATE_HOST_PARTICIPANT_OK', ['room_id' => $room->room_id, 'user_id' => $host->user_id]);

                // unique identity per device
                $identity = sprintf('host-%d-%s', $user->id, substr($deviceId, 0, 16));
                $name     = $host->stage_name ?? ($user->name ?? 'Host');

                $hostToken = LivekitToken::issue(
                    roomId:   $room->room_id,
                    identity: $identity,
                    name:     $name,
                    role:     'host',
                    roomType: $roomType,
                    ttlSec:   (int) config('services.livekit.ttl', 3600),
                    metadata: $this->hostTokenMetadata($user, $host, $roomType, $deviceId),
                    publishSources: $roomType === 'audio' ? ['microphone'] : ['camera', 'microphone']
                );
                Log::info('LIVE_ROOM_CREATE_TOKEN_ISSUED', ['room_id' => $room->room_id, 'identity' => $identity]);
            }

            return $room;
        });

        // publish after commit on the latest persisted state
        $room = LiveRoom::query()->findOrFail($room->id);
        LiveRoomBroadcaster::broadcast($room, $startNow ? 'live' : 'created');
        Log::info('LIVE_ROOM_CREATE_BROADCAST', ['room_id' => $room->room_id, 'event' => $startNow ? 'live' : 'created']);

        if ($room->status === 'scheduled') {
            $this->notifyScheduledRoomCreated($room, $host, $user);
        } elseif ($room->status === 'live') {
            $this->notifyLiveRoomStartedFollowers($room, $host, $user);
        }

        $entryEffect = null;
        if ($startNow && $hostToken) {
            try {
                $entryEffect = $this->entryPacks->maybeTriggerRoomEntryEffect($room, $user);
            } catch (\Throwable $e) {
                Log::warning('LIVE_ROOM_CREATE_ENTRY_EFFECT_FAILED', [
                    'room_id' => $room->room_id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'ok'       => true,
            'room'     => $this->roomPayload($room),
            'ws_url'   => $wsUrl,
            'token'    => $hostToken,
            'identity' => $identity,
            'role'     => $hostToken ? 'host' : null,
            'entry_effect' => $entryEffect,
        ], 201);
    }

    public function createAudio(Request $request)
    {
        $request->merge([
            'room_type' => 'audio',
            'max_participants' => $request->input('max_participants', $this->configuredMaxParticipants('audio')),
            'max_speakers' => $request->input('max_speakers', $this->configuredMaxSpeakers('audio')),
        ]);

        return $this->createOrStart($request);
    }

    /**
     * POST /api/live/rooms/{live_room:room_id}/end
     */
    public function end(Request $request, LiveRoom $live_room)
    {
        $user = $request->user();
        Log::info('LIVE_ROOM_END_BEGIN', [
            'request_id' => $request->header('X-Request-Id'),
            'user_id' => $user?->id,
            'room_id' => $live_room->room_id,
        ]);
        $ownerUserId = optional($live_room->host)->user_id;
        abort_unless($user && ($user->id === $ownerUserId || $user->hasAnyRole(['admin', 'super-admin'])), 403, 'Not your room.');

        if (!$live_room->ended_at) {
            $live_room = $this->seats->endRoom($live_room, 'host_ended', $user);
            $this->pk->endForRoomTermination($live_room, 'room_ended');
            Log::info('LIVE_ROOM_END_BROADCAST', ['room_id' => $live_room->room_id, 'event' => 'ended']);
        }

        return response()->json([
            'ok'   => true,
            'room' => $this->roomPayload($live_room),
        ]);
    }

    /** Create an open host participant row if missing */
    protected function ensureHostParticipant(Request $request, LiveRoom $room, int $userId): void
    {
        $exists = LiveRoomParticipant::where('live_room_id', $room->id)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->exists();

        if (!$exists) {
            LiveRoomParticipant::create([
                'live_room_id' => $room->id,
                'user_id'      => $userId,
                'session_id'   => null,
                'role'         => 'host',
                'joined_at'    => now(),
                // align headers
                'device'       => substr((string) $request->header('X-Device-Id') ?: '', 0, 80),
                'country'      => substr((string) $request->header('X-Country') ?: '', 0, 2) ?: null,
                'ip_address'   => $request->ip(),
                'user_agent'   => substr($request->userAgent() ?? '', 0, 500),
            ]);
            Log::info('LIVE_ROOM_HOST_PARTICIPANT_CREATED', ['room_id' => $room->room_id, 'user_id' => $userId]);
        }
    }

    protected function roomPayload(LiveRoom $room): array
    {
        return [
            'room_id'      => $room->room_id,
            'title'        => $room->title,
            'room_type'    => $room->room_type ?? 'video',
            'status'       => $room->status,
            'scheduled_at' => optional($room->scheduled_at)?->toIso8601String(),
            'started_at'   => optional($room->started_at)?->toIso8601String(),
            'ended_at'     => optional($room->ended_at)?->toIso8601String(),
            'end_reason'   => $room->end_reason,
            'peak_viewers' => (int) ($room->peak_viewers ?? 0),
            'max_speakers' => max(1, (int) ($room->max_speakers ?? $this->configuredMaxSpeakers((string) ($room->room_type ?? 'video')))),
            'max_participants' => max(1, (int) ($room->max_participants ?? $this->configuredMaxParticipants((string) ($room->room_type ?? 'video')))),
            'is_locked' => (bool) ($room->is_locked ?? false),
            'topic' => $room->topic,
            'language' => $room->language,
            'meta'         => $room->meta,
        ];
    }

    private function discoverRoomsQuery(bool $includeScheduled)
    {
        return LiveRoom::query()
            ->with(['host.user'])
            ->whereNull('ended_at')
            ->where(function ($query) use ($includeScheduled) {
                $query->where('status', 'live');
                if ($includeScheduled) {
                    $query->orWhere(function ($scheduled) {
                        $scheduled
                            ->where('status', 'scheduled')
                            ->whereNotNull('scheduled_at')
                            ->where('scheduled_at', '>=', now()->subMinutes(2));
                    });
                }
            })
            ->withCount([
                'participants as participant_count' => fn ($q) => $q->whereNull('left_at'),
                'participants as viewer_count' => fn ($q) => $q->whereNull('left_at')->where('role', 'viewer'),
                'participants as listener_count' => fn ($q) => $q->whereNull('left_at')->where('role', 'listener'),
                'participants as speaker_count' => fn ($q) => $q->whereNull('left_at')->where('role', 'speaker'),
                'participants as open_host_count' => fn ($q) => $q->whereNull('left_at')->where('role', 'host'),
                'seatRequests as pending_seat_request_count' => fn ($q) => $q->where('status', 'pending'),
                'reminders as reminder_count',
            ])
            ->orderByRaw("CASE WHEN status = 'live' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN room_type = 'audio' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN status = 'scheduled' THEN scheduled_at END ASC")
            ->orderByDesc('started_at')
            ->orderByDesc('id');
    }

    private function notifyScheduledRoomCreated(LiveRoom $room, Host $host, User $hostUser): void
    {
        $audience = HostFollower::query()
            ->where('host_id', $host->id)
            ->pluck('user_id');

        if ($audience->isEmpty()) {
            return;
        }

        $hostName = $host->stage_name ?: ($hostUser->name ?: 'A host');
        $scheduledLabel = optional($room->scheduled_at)?->timezone(config('app.timezone'))->format('d M, h:i A');

        NotifyUser::sendMany($audience, [
            'type' => 'scheduled_live_created',
            'title' => $hostName . ' scheduled a live',
            'body' => $scheduledLabel
                ? '"' . $room->title . '" is set for ' . $scheduledLabel . '.'
                : 'A followed host scheduled "' . $room->title . '".',
            'screen' => 'notifications',
            'room_id' => $room->room_id,
            'meta' => [
                'room_id' => $room->room_id,
                'host_id' => $hostUser->id,
                'host_name' => $hostName,
                'scheduled_at' => optional($room->scheduled_at)?->toIso8601String(),
            ],
        ]);
    }

    private function notifyScheduledRoomLive(LiveRoom $room, Host $host, User $hostUser): void
    {
        $audience = LiveRoomReminder::query()
            ->where('live_room_id', $room->id)
            ->pluck('user_id');

        if ($audience->isEmpty()) {
            return;
        }

        $hostName = $host->stage_name ?: ($hostUser->name ?: 'A host');

        NotifyUser::sendMany($audience, [
            'type' => 'scheduled_live_started',
            'title' => $hostName . ' is live now',
            'body' => '"' . $room->title . '" has started. Join the room now.',
            'screen' => 'notifications',
            'room_id' => $room->room_id,
            'meta' => [
                'room_id' => $room->room_id,
                'host_id' => $hostUser->id,
                'host_name' => $hostName,
                'started_at' => optional($room->started_at)?->toIso8601String(),
            ],
        ]);
    }

    private function notifyLiveRoomStartedFollowers(LiveRoom $room, Host $host, User $hostUser, $excludeUserIds = []): void
    {
        if ($host->is_blocked || $hostUser->is_blocked) {
            return;
        }

        $excluded = collect($excludeUserIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $audienceQuery = HostFollower::query()
            ->where('host_id', $host->id)
            ->where('notify_when_online', true);

        if ($excluded->isNotEmpty()) {
            $audienceQuery->whereNotIn('user_id', $excluded->all());
        }

        $audience = $audienceQuery->pluck('user_id');
        if ($audience->isEmpty()) {
            return;
        }

        $hostName = $host->stage_name ?: ($hostUser->name ?: 'A host');
        $roomType = (string) ($room->room_type ?? 'video');
        $roomLabel = $roomType === 'audio' ? 'audio room' : 'video room';
        $roomArticle = $roomType === 'audio' ? 'an' : 'a';

        NotifyUser::sendMany($audience, [
            'type' => 'host_live_started',
            'title' => $hostName . ' is live now',
            'body' => $hostName . ' started ' . $roomArticle . ' ' . $roomLabel . '.',
            'screen' => 'room',
            'room_id' => $room->room_id,
            'meta' => [
                'room_id' => $room->room_id,
                'room_type' => $roomType,
                'host_id' => $host->id,
                'host_user_id' => $hostUser->id,
                'host_name' => $hostName,
                'notification_type' => 'host_live_started',
                'screen' => 'room',
                'started_at' => optional($room->started_at)?->toIso8601String(),
            ],
        ]);
    }

    protected function generateRoomId(int $len = 8): string
    {
        do {
            $id = Str::lower(Str::random($len));
        } while (LiveRoom::where('room_id', $id)->exists());

        return $id;
    }
}
