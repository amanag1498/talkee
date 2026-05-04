<?php

namespace App\Services;

use App\Models\CallSession;
use App\Models\LiveRoom;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CallSessionService
{
    public function __construct(
        private HostAvailabilityService $availabilityService,
        private CallBillingService $billingService,
        private LiveRoomSeatService $liveRoomSeatService,
        private ModerationService $moderation,
    ) {
    }

    public function requestCall(User $caller, int $receiverId, string $type): CallSession
    {
        return DB::transaction(function () use ($caller, $receiverId, $type) {
            if ($caller->id === $receiverId) {
                throw new InvalidArgumentException('Caller cannot call themselves.');
            }

            $receiver = User::query()->with(['host.agency', 'hostAvailability'])->findOrFail($receiverId);
            $host = $receiver->host;
            if (!$host) {
                throw new InvalidArgumentException('Receiver is not a host.');
            }

            if ($this->moderation->isBlockedByHostUserId($receiver->id, $caller->id)) {
                throw new InvalidArgumentException('You were blocked by this host.');
            }

            $existing = CallSession::query()
                ->where('caller_id', $caller->id)
                ->where('receiver_id', $receiver->id)
                ->where('type', $type)
                ->whereIn('status', ['requested', 'ringing', 'accepted'])
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                Log::info('CALL_REQUEST_DUPLICATE_BLOCKED', [
                    'caller_id' => $caller->id,
                    'receiver_id' => $receiver->id,
                    'type' => $type,
                    'call_id' => $existing->id,
                ]);

                return $existing->fresh(['caller', 'receiver', 'host.agency']);
            }

            $callerWallet = Wallet::query()->lockForUpdate()->where('user_id', $caller->id)->first();
            $coinRatePerMinute = $this->resolveCoinRatePerMinute($host, $type);
            $minimumBalance = $this->minimumRequiredBalance($coinRatePerMinute);
            if (!$callerWallet || $callerWallet->balance < $minimumBalance) {
                throw new InvalidArgumentException('Insufficient coins to start call.');
            }

            $receiverAvailability = $this->availabilityService->ensureForUser($receiver);
            if ($receiverAvailability->manual_status !== 'online') {
                throw new InvalidArgumentException('Receiver is offline.');
            }
            if ($receiverAvailability->socket_status !== 'online') {
                throw new InvalidArgumentException('Receiver is not connected.');
            }
            if ($receiverAvailability->call_status !== 'available') {
                throw new InvalidArgumentException('Receiver is busy.');
            }

            $this->assertNoActiveCallForUser($caller->id, 'Caller is already in a call.');
            $this->assertNoActiveCallForUser($receiver->id, 'Receiver is already in a call.');

            $call = CallSession::create([
                'caller_id' => $caller->id,
                'receiver_id' => $receiver->id,
                'host_id' => $host->id,
                'agency_id' => $host->agency_id,
                'type' => $type,
                'status' => 'requested',
                'coin_rate_per_minute' => $coinRatePerMinute,
            ]);

            $call->update([
                'status' => 'ringing',
            ]);

            $this->availabilityService->setCallStatus($receiver->id, 'busy', $call->id);

            $this->publishCallEvent('incoming_call', $call, [
                'caller_id' => $call->caller_id,
                'receiver_id' => $call->receiver_id,
                'type' => $call->type,
                'caller_name' => $caller->name,
                'caller_avatar_url' => $caller->avatar_url,
                'ringing_timeout_seconds' => $this->ringingTimeoutSeconds(),
            ]);

            return $call->fresh(['caller', 'receiver', 'host.agency']);
        });
    }

    public function ringingTimeoutSeconds(): int
    {
        return (int) config('calls.ringing_timeout_seconds', 30);
    }

    public function resolveCoinRatePerMinute(?\App\Models\Host $host, string $type): int
    {
        $type = strtolower($type);
        if ($type === 'video') {
            return (int) ($host?->video_call_rate_per_minute ?: $this->defaultCoinRatePerMinute('video'));
        }

        return (int) ($host?->audio_call_rate_per_minute ?: $this->defaultCoinRatePerMinute('audio'));
    }

    public function minimumRequiredBalance(int $coinRatePerMinute): int
    {
        return max(
            (int) config('calls.minimum_balance_to_start_call'),
            $coinRatePerMinute
        );
    }

    private function defaultCoinRatePerMinute(string $type): int
    {
        $specific = $type === 'video'
            ? config('calls.video_coin_rate_per_minute')
            : config('calls.audio_coin_rate_per_minute');

        return (int) ($specific ?: config('calls.coin_rate_per_minute'));
    }

    public function acceptCall(CallSession $call, User $actor): CallSession
    {
        $liveRoomToEnd = null;

        $acceptedCall = DB::transaction(function () use ($call, $actor, &$liveRoomToEnd) {
            $call = CallSession::query()->lockForUpdate()->with(['host.agency'])->findOrFail($call->id);
            if ((int) $actor->id !== (int) $call->receiver_id) {
                abort(403, 'Only receiver can accept this call.');
            }
            if ($call->status === 'accepted') {
                return $call->fresh();
            }
            if (in_array($call->status, ['rejected', 'missed', 'ended', 'failed'], true)) {
                throw new InvalidArgumentException('Call is already closed.');
            }
            if (!in_array($call->status, ['requested', 'ringing'], true)) {
                throw new InvalidArgumentException('Call cannot be accepted.');
            }

            $call->update([
                'status' => 'accepted',
                'accepted_at' => now(),
                'started_at' => now(),
                'livekit_room_name' => $call->livekit_room_name ?: $this->makeRoomName($call),
            ]);

            $liveRoomToEnd = LiveRoom::query()
                ->where('host_id', $call->host_id)
                ->where('status', 'live')
                ->whereNull('ended_at')
                ->latest('id')
                ->first();

            $this->availabilityService->setCallStatus($call->caller_id, 'busy', $call->id);
            $this->availabilityService->setCallStatus($call->receiver_id, 'busy', $call->id);

            $this->publishCallEvent('call_accepted', $call->fresh(), [
                'caller_id' => $call->caller_id,
                'receiver_id' => $call->receiver_id,
                'livekit_room_name' => $call->livekit_room_name,
                'type' => $call->type,
            ]);

            return $call->fresh();
        });

        if ($liveRoomToEnd) {
            try {
                $this->liveRoomSeatService->endRoom(
                    $liveRoomToEnd,
                    'host_joined_private_call',
                    $actor
                );
            } catch (\Throwable $e) {
                Log::error('CALL_ACCEPT_LIVE_ROOM_END_FAILED', [
                    'call_id' => $acceptedCall->id,
                    'live_room_id' => $liveRoomToEnd->id,
                    'room_id' => $liveRoomToEnd->room_id,
                    'host_id' => $acceptedCall->host_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $acceptedCall;
    }

    public function rejectCall(CallSession $call, User $actor): CallSession
    {
        return DB::transaction(function () use ($call, $actor) {
            $call = CallSession::query()->lockForUpdate()->findOrFail($call->id);
            if (!in_array($actor->id, [$call->caller_id, $call->receiver_id], true)) {
                abort(403, 'You cannot reject this call.');
            }
            if (in_array($call->status, ['rejected', 'missed', 'ended', 'failed'], true)) {
                return $call->fresh();
            }
            if (!in_array($call->status, ['requested', 'ringing'], true)) {
                throw new InvalidArgumentException('Call cannot be rejected.');
            }

            $call->update([
                'status' => 'rejected',
                'ended_at' => now(),
                'end_reason' => $this->deriveRejectReason($call, $actor),
            ]);

            $this->releaseUsers($call);
            $this->publishCallEvent('call_rejected', $call->fresh());

            return $call->fresh();
        });
    }

    public function endCall(CallSession $call, User $actor, ?string $reason = null): CallSession
    {
        return DB::transaction(function () use ($call, $actor, $reason) {
            $call = CallSession::query()->lockForUpdate()->findOrFail($call->id);
            if (!in_array($actor->id, [$call->caller_id, $call->receiver_id], true) && !$actor->hasRole('admin')) {
                abort(403, 'You cannot end this call.');
            }
            if (in_array($call->status, ['ended', 'failed', 'missed', 'rejected'], true)) {
                return $call->fresh();
            }
            if (!in_array($call->status, ['requested', 'ringing', 'accepted'], true)) {
                throw new InvalidArgumentException('Call cannot be ended.');
            }

            $wasAccepted = (bool) $call->accepted_at;
            $call->update([
                'status' => $wasAccepted ? 'ended' : 'failed',
                'ended_at' => now(),
                'end_reason' => $this->normalizeEndReason(
                    $reason,
                    $call,
                    $actor->id === $call->caller_id ? 'caller' : 'receiver'
                ),
            ]);

            try {
                $call = $this->billingService->processEndedCall($call->fresh());
            } catch (\Throwable $e) {
                Log::error('CALL_BILLING_FAIL', [
                    'call_id' => $call->id,
                    'error' => $e->getMessage(),
                ]);
                $call->update([
                    'status' => 'failed',
                    'end_reason' => 'billing_failed',
                ]);
                $call = $call->fresh();
            }
            $this->releaseUsers($call);

            if ($call->status === 'failed') {
                $this->publishCallEvent('call_failed', $call->fresh(), [
                    'reason' => $call->end_reason,
                    'duration_seconds' => $call->duration_seconds,
                ]);
            } else {
                $this->publishCallEvent('call_ended', $call->fresh(), [
                    'duration_seconds' => $call->duration_seconds,
                    'billable_minutes' => $call->billable_minutes,
                    'total_coins_charged' => $call->total_coins_charged,
                ]);
            }

            return $call->fresh();
        });
    }

    public function markMissedCalls(): int
    {
        $cutoff = now()->subSeconds($this->ringingTimeoutSeconds());
        $calls = CallSession::query()
            ->whereIn('status', ['requested', 'ringing'])
            ->where('created_at', '<=', $cutoff)
            ->get();

        foreach ($calls as $call) {
            DB::transaction(function () use ($call) {
                $locked = CallSession::query()->lockForUpdate()->find($call->id);
                if (!$locked || !in_array($locked->status, ['requested', 'ringing'], true)) {
                    return;
                }

                $locked->update([
                    'status' => 'missed',
                    'ended_at' => now(),
                    'end_reason' => 'timeout',
                ]);

                $this->releaseUsers($locked);
                $this->publishCallEvent('call_missed', $locked->fresh());
            });
        }

        return $calls->count();
    }

    public function issueParticipantToken(CallSession $call, User $actor): array
    {
        if (!in_array($actor->id, [$call->caller_id, $call->receiver_id], true)) {
            abort(403, 'You cannot access this token.');
        }
        if ($call->status !== 'accepted' || !$call->livekit_room_name) {
            throw new InvalidArgumentException('Call room is not ready.');
        }

        $role = 'host';
        $token = LivekitToken::issue(
            roomId: $call->livekit_room_name,
            identity: (string) $actor->id,
            name: $actor->name,
            role: $role,
            ttlSec: (int) config('services.livekit.ttl', 3600),
            metadata: [
                'call_id' => $call->id,
                'type' => $call->type,
                'role' => $role,
            ],
        );

        return [
            'call_id' => $call->id,
            'room_name' => $call->livekit_room_name,
            'ws_url' => (string) config('services.livekit.ws_url', 'ws://localhost:7880'),
            'token' => $token,
            'identity' => (string) $actor->id,
            'type' => $call->type,
        ];
    }

    public function handleDisconnect(int $userId): ?CallSession
    {
        return DB::transaction(function () use ($userId) {
            $call = CallSession::query()
                ->whereIn('status', ['requested', 'ringing', 'accepted'])
                ->where(function ($query) use ($userId) {
                    $query->where('caller_id', $userId)->orWhere('receiver_id', $userId);
                })
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (!$call) {
                return null;
            }

            $role = $call->caller_id === $userId ? 'caller' : 'receiver';
            if (in_array($call->status, ['requested', 'ringing'], true)) {
                $call->update([
                    'status' => $role === 'receiver' ? 'missed' : 'failed',
                    'ended_at' => now(),
                    'end_reason' => $role === 'receiver' ? 'receiver_disconnected' : 'caller_disconnected',
                ]);
            } elseif ($call->status === 'accepted') {
                $call->update([
                    'status' => 'ended',
                    'ended_at' => now(),
                    'end_reason' => $role === 'receiver' ? 'receiver_disconnected' : 'caller_disconnected',
                ]);
                $call = $this->billingService->processEndedCall($call->fresh());
            }

            $this->releaseUsers($call);
            $event = $call->status === 'missed'
                ? 'call_missed'
                : ($call->status === 'failed' ? 'call_failed' : 'call_ended');
            $this->publishCallEvent($event, $call->fresh(), [
                'reason' => $call->end_reason,
                'duration_seconds' => $call->duration_seconds,
                'billable_minutes' => $call->billable_minutes,
                'total_coins_charged' => $call->total_coins_charged,
            ]);

            Log::info('CALL_USER_DISCONNECT_HANDLED', [
                'call_id' => $call->id,
                'user_id' => $userId,
                'role' => $role,
                'status' => $call->status,
                'reason' => $call->end_reason,
            ]);

            return $call->fresh();
        });
    }

    private function makeRoomName(CallSession $call): string
    {
        return sprintf('call_%d_%s', $call->id, Str::lower(Str::random(8)));
    }

    private function assertNoActiveCallForUser(int $userId, string $message): void
    {
        $exists = CallSession::query()
            ->activeStates()
            ->where(function ($query) use ($userId) {
                $query->where('caller_id', $userId)->orWhere('receiver_id', $userId);
            })
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException($message);
        }
    }

    private function releaseUsers(CallSession $call): void
    {
        if (User::query()->whereKey($call->caller_id)->exists()) {
            $this->availabilityService->setCallStatus($call->caller_id, 'available', null);
        }
        if (User::query()->whereKey($call->receiver_id)->exists()) {
            $this->availabilityService->setCallStatus($call->receiver_id, 'available', null);
        }
    }

    private function deriveRejectReason(CallSession $call, User $actor): string
    {
        if ((int) $actor->id === (int) $call->receiver_id) {
            return 'receiver_rejected';
        }

        return 'caller_cancelled';
    }

    private function normalizeEndReason(?string $reason, CallSession $call, string $actorRole): string
    {
        $allowed = [
            'completed',
            'caller_cancelled',
            'receiver_rejected',
            'caller_disconnected',
            'receiver_disconnected',
            'timeout',
            'insufficient_balance',
            'billing_failed',
            'system_error',
        ];

        $reason = $reason ? Str::lower(trim($reason)) : null;
        if ($reason && in_array($reason, $allowed, true)) {
            return $reason;
        }

        if (in_array($call->status, ['requested', 'ringing'], true)) {
            return $actorRole === 'caller' ? 'caller_cancelled' : 'receiver_rejected';
        }

        return 'completed';
    }

    private function publishCallEvent(string $event, CallSession $call, array $extra = []): void
    {
        CallRealtimePublisher::publish('calls:events', array_merge([
            'event' => $event,
            'call_id' => (int) $call->id,
            'caller_id' => (int) $call->caller_id,
            'receiver_id' => (int) $call->receiver_id,
        ], $extra));
    }
}
