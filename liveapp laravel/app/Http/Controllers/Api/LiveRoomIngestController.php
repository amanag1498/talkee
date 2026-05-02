<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveRoom;
use App\Models\LiveRoomParticipant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\LivekitToken;
use App\Services\LiveRoomAccessService;
use App\Services\LiveRoomBroadcaster;
use App\Services\EntryPackService;
use App\Services\LiveRoomPkService;
use App\Services\LiveRoomSeatService;
use App\Services\LiveRoomStateService;
use App\Services\ThemeUnlockService;
use App\Support\OpsMetrics;
use App\Models\UserSubscription;
use Laravel\Sanctum\PersonalAccessToken;


class LiveRoomIngestController extends Controller
{
    public function __construct(
        private LiveRoomStateService $state,
        private LiveRoomAccessService $access,
        private EntryPackService $entryPacks,
        private LiveRoomPkService $pk,
        private ThemeUnlockService $themes,
    )
    {
    }

    private function configuredMaxParticipants(LiveRoom $room): int
    {
        $roomType = (string) ($room->room_type ?? 'video');
        $fallback = $roomType === 'audio' ? 50 : 12;

        return max(2, (int) config("live_rooms.{$roomType}.max_participants", $fallback));
    }

    private function configuredMaxSpeakers(LiveRoom $room): int
    {
        $roomType = (string) ($room->room_type ?? 'video');
        $fallback = $roomType === 'audio' ? 8 : 4;

        return max(1, (int) config("live_rooms.{$roomType}.max_speakers", $fallback));
    }

    private function appVersionCodeFromRequest(Request $request): ?int
    {
        $raw = $request->header('X-App-Version-Code');
        if ($raw === null || $raw === '') {
            return null;
        }

        return is_numeric($raw) ? (int) $raw : null;
    }

    private function participantThemePayload(?User $user, string $role, ?int $appVersionCode = null): array
    {
        if (!$user) {
            return [
                'active_theme_key' => ThemeUnlockService::FALLBACK_THEME_KEY,
                'is_vip' => false,
                'is_host' => $role === 'host',
                'level' => null,
                'avatar' => null,
                'avatar_url' => null,
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
            'active_theme_key' => $this->themes->activeThemeKeyFor($user, true, $appVersionCode),
            'is_vip' => $isVip,
            'is_host' => $role === 'host',
            'level' => $user->level?->level !== null ? (int) $user->level->level : null,
            'avatar' => $user->avatar_url,
            'avatar_url' => $user->avatar_url,
        ];
    }

public function join(Request $request, string $room_id)
{
    Log::info('LIVE_INGEST_JOIN_BEGIN', [
        'request_id' => $request->header('X-Request-Id'),
        'room_id' => $room_id,
        'user_id' => $request->user()?->id,
        'ip' => $request->ip(),
    ]);

    $room = LiveRoom::where('room_id', $room_id)->first();
    if (!$room) {
        Log::warning('LIVE_INGEST_JOIN_ROOM_NOT_FOUND', [
            'room_id' => $room_id,
            'user_id' => $request->user()?->id,
        ]);
        abort(404, 'room_not_found');
    }

    if ($room->status !== 'live' || $room->ended_at) {
        Log::warning('LIVE_INGEST_JOIN_NOT_JOINABLE', [
            'room_id' => $room_id,
            'status' => $room->status,
            'ended_at' => optional($room->ended_at)?->toIso8601String(),
        ]);
        abort(409, 'room_not_joinable');
    }

    $data = $request->validate([
        'role'       => 'nullable|in:viewer,listener,speaker,host,moderator',
        'session_id' => 'nullable|string|max:64',
        'device'     => 'nullable|string|max:80',
        'country'    => 'nullable|string|size:2',
    ]);

    // prefer auth user if available
    $resolvedUserId = $this->resolveUserId($request);
    $authUser = $request->user() ?: ($resolvedUserId ? User::query()->find($resolvedUserId) : null);
    $userId   = $authUser?->id ?? $resolvedUserId;
    $sessionId = $data['session_id'] ?? $request->header('X-Session-Id') ?? Str::uuid()->toString();

    // === SANITIZE ROLE ===
    // If caller is the actual host user, force 'host'.
    // If not, never allow client to escalate to 'host'.
    $defaultRole = ($room->room_type ?? 'video') === 'audio' ? 'listener' : 'viewer';
    $role = $data['role'] ?? $defaultRole;
    $isRoomOwner = optional($room->host)->user_id && ($userId === $room->host->user_id);

    if ($isRoomOwner) {
        $role = 'host';
    } elseif (in_array($role, ['host', 'speaker'], true)) {
        $role = $defaultRole;
    }
    // allow 'moderator' only for admins
    if ($role === 'moderator' && !optional($authUser)->hasRole('admin')) {
        $role = $defaultRole;
    }

    $this->access->assertCanJoin($room, $authUser, $userId, $role);

    Log::info('LIVE_INGEST_JOIN_ROLE_DECIDED', [
        'room_id' => $room_id,
        'user_id' => $userId,
        'role' => $role,
    ]);

    // find or create "open" participation
    $participant = DB::transaction(function () use ($request, $room, $userId, $sessionId, $role, $data, $room_id) {
        $query = LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->when($userId, fn ($q) => $q->where('user_id', $userId), fn ($q) => $q->whereNull('user_id')->where('session_id', $sessionId))
            ->whereNull('left_at')
            ->lockForUpdate();

        $open = $query->orderByDesc('id')->get();
        $participant = $open->first();
        $duplicates = $open->slice(1);
        $now = now();

        foreach ($duplicates as $dup) {
            $joined = $dup->joined_at ?? $now;
            $dup->update([
                'left_at' => $now,
                'duration_seconds' => max(0, $joined->diffInSeconds($now)),
            ]);
        }

        if (!$participant) {
            $activeCount = LiveRoomParticipant::query()
                ->where('live_room_id', $room->id)
                ->whereNull('left_at')
                ->count();
            $maxParticipants = max(1, (int) ($room->max_participants ?? $this->configuredMaxParticipants($room)));
            if ($activeCount >= $maxParticipants) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(409, 'room_full');
            }

            $participant = LiveRoomParticipant::create([
                'live_room_id'    => $room->id,
                'user_id'         => $userId,
                'session_id'      => $userId ? null : $sessionId,
                'role'            => $role,
                'joined_at'       => $now,
                'last_seen_at'    => $now,
                'device'          => $data['device'] ?? null,
                'country'         => isset($data['country']) ? strtoupper($data['country']) : null,
                'ip_address'      => $request->ip(),
                'user_agent'      => substr($request->userAgent() ?? '', 0, 500),
            ]);
            Log::info('LIVE_INGEST_PARTICIPANT_CREATED', [
                'room_id' => $room_id,
                'participant_id' => $participant->id,
                'user_id' => $participant->user_id,
                'session_id' => $participant->session_id,
            ]);
        }

        $participant->forceFill([
            'last_seen_at' => $now,
        ])->save();

        $room->forceFill(['last_activity_at' => $now])->save();

        return $participant;
    });

    // === ISSUE LIVEKIT TOKEN ===
    // identity must be unique: prefer user id, else session id
    $identity = $userId ? ("user:".$userId) : ("guest:".$sessionId);
    $name     = $authUser?->name ?: ($userId ? "User#{$userId}" : "Guest");

    $tokenMetadata = array_merge([
        'role' => $role,
        'room_type' => (string) ($room->room_type ?? 'video'),
        'device' => $data['device'] ?? null,
        'user_id' => $authUser?->id,
        'session_id' => $sessionId,
        'name' => $name,
    ], $this->participantThemePayload($authUser, $role, $this->appVersionCodeFromRequest($request)));

    $token = LivekitToken::issue(
        roomId: $room->room_id,
        identity: $identity,
        name: $name,
        role: $role,
        roomType: (string) ($room->room_type ?? 'video'),
        ttlSec: (int) env('LK_TTL', 3600),
        metadata: $tokenMetadata,
    );
    Log::info('LIVE_INGEST_TOKEN_ISSUED', [
        'room_id' => $room_id,
        'participant_id' => $participant->id,
        'identity' => $identity,
        'role' => $role,
    ]);
    OpsMetrics::increment(OpsMetrics::ROOM_JOINS);
    $this->broadcastRoomCount($room->fresh());

    $snapshot = app(LiveRoomSeatService::class)->snapshot($room, $authUser ?? $request->user());
    $entryEffect = null;
    if ($authUser) {
        try {
            $entryEffect = $this->entryPacks->maybeTriggerRoomEntryEffect($room, $authUser);
        } catch (\Throwable $e) {
            Log::warning('LIVE_INGEST_ENTRY_EFFECT_FAILED', [
                'room_id' => $room_id,
                'user_id' => $authUser->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    return response()->json([
        'ok'            => true,
        'participant_id'=> $participant->id,
        'room'          => $room->room_id,
        'room_type'     => (string) ($room->room_type ?? 'video'),
        'identity'      => $identity,
        'role'          => $role,
        'ws_url'        => env('LIVEKIT_WS_URL', 'ws://localhost:7880'),
        'token'         => $token,
        'speakers'      => $snapshot['speakers'] ?? [],
        'participant_count' => (int) ($snapshot['participant_count'] ?? 0),
        'listener_count' => (int) ($snapshot['listener_count'] ?? 0),
        'max_participants' => max(1, (int) ($room->max_participants ?? $this->configuredMaxParticipants($room))),
        'max_speakers' => max(1, (int) ($room->max_speakers ?? $this->configuredMaxSpeakers($room))),
        'entry_effect' => $entryEffect,
    ]);
}


    // POST /api/live/rooms/{room_id}/leave
    public function leave(Request $request, string $room_id)
    {
        Log::info('LIVE_INGEST_LEAVE_BEGIN', [
            'request_id' => $request->header('X-Request-Id'),
            'room_id' => $room_id,
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
        ]);
        $room = LiveRoom::where('room_id', $room_id)->first();
        if (!$room) {
            Log::warning('LIVE_INGEST_LEAVE_ROOM_NOT_FOUND', [
                'room_id' => $room_id,
                'user_id' => $request->user()?->id,
            ]);
            return response()->json([
                'ok' => true,
                'room' => $room_id,
                'closed' => 0,
                'already_missing' => true,
            ]);
        }

        $data = $request->validate([
            'session_id' => 'nullable|string|max:64',
        ]);

    $userId = $request->user()?->id ?? $this->resolveUserId($request);
        $sessionId = $data['session_id'] ?? $request->header('X-Session-Id');

        $closed = DB::transaction(function () use ($room, $userId, $sessionId, $request) {
            $matches = LiveRoomParticipant::query()
                ->where('live_room_id', $room->id)
                ->when($userId, fn ($q) => $q->where('user_id', $userId), fn ($q) => $q->whereNull('user_id')->where('session_id', $sessionId))
                ->whereNull('left_at')
                ->lockForUpdate()
                ->get();

            $left = now();
            foreach ($matches as $participant) {
                $joined = $participant->joined_at ?? $left;
                $participant->update([
                    'left_at' => $left,
                    'duration_seconds' => max(0, $joined->diffInSeconds($left)),
                ]);
            }

            if ($matches->isNotEmpty()) {
                $room->forceFill(['last_activity_at' => $left])->save();
            }

            if ($userId) {
                app(LiveRoomSeatService::class)->handleParticipantExit($room, (int) $userId, $sessionId);
            }

            return $matches;
        });

        if ($closed->isNotEmpty()) {
            Log::info('LIVE_INGEST_LEAVE_PARTICIPANT_UPDATED', [
                'room_id' => $room_id,
                'closed_count' => $closed->count(),
            ]);
            $this->broadcastRoomCount($room->fresh());
        } else {
            Log::warning('LIVE_INGEST_LEAVE_PARTICIPANT_NOT_FOUND', [
                'room_id' => $room_id,
                'user_id' => $userId,
                'session_id' => $sessionId,
            ]);
        }

        return response()->json(['ok' => true]);
    }

    private function broadcastRoomCount(LiveRoom $room): void
    {
        $viewerCount = LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->whereNull('left_at')
            ->where('role', 'viewer')
            ->count();

        if ($viewerCount > (int) ($room->peak_viewers ?? 0)) {
            $room->update(['peak_viewers' => $viewerCount]);
            $room = $room->fresh();
        }

        $this->state->touchRoom($room);

        LiveRoomBroadcaster::broadcast($room, 'updated');
    }

    private function resolveUserId(Request $request): ?int
    {
        $token = $request->bearerToken();
        if (!$token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken || !$accessToken->tokenable_id) {
            return null;
        }

        return (int) $accessToken->tokenable_id;
    }
}
