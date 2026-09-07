<?php

namespace App\Services;

use App\Models\Host;
use App\Models\LiveRoom;
use App\Models\LiveRoomParticipant;
use App\Models\LiveRoomPkBattle;
use App\Models\LiveRoomPkEvent;
use App\Models\LiveRoomSeatRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LiveRoomPkService
{
    private const PENDING_STATUSES = ['pending'];
    private const ACTIVE_STATUSES = ['active'];
    private const OPEN_STATUSES = ['pending', 'active'];

    public function __construct(
        private ModerationService $moderation,
        private UserBlockService $userBlocks,
    )
    {
    }

    public function invite(LiveRoom $room, LiveRoom $targetRoom, User $actor, ?int $durationSeconds = null): LiveRoomPkBattle
    {
        $host = $this->assertHostOwnsRoom($actor, $room);
        $targetHost = $targetRoom->host;

        if (!$targetHost) {
            throw new HttpException(409, 'Target room host is missing.');
        }
        $sourceHostUserId = optional($room->host)->user_id ? (int) $room->host->user_id : null;
        $targetHostUserId = optional($targetRoom->host)->user_id ? (int) $targetRoom->host->user_id : null;
        if ($this->moderation->isBlockedByHostUserId($targetHostUserId, $sourceHostUserId)
            || $this->moderation->isBlockedByHostUserId($sourceHostUserId, $targetHostUserId)) {
            throw new HttpException(403, 'PK is not allowed because one host blocked the other.');
        }
        if ($sourceHostUserId && $targetHostUserId
            && $this->userBlocks->hasBlockBetween($sourceHostUserId, $targetHostUserId)) {
            throw new HttpException(409, 'Unblock this host before starting a PK battle.');
        }
        if ($room->id === $targetRoom->id) {
            throw new HttpException(409, 'Cannot invite your own room.');
        }

        $durationSeconds = max(
            60,
            min(900, $durationSeconds ?? (int) config('live_rooms.pk.default_duration_seconds', 300)),
        );

        return DB::transaction(function () use ($room, $targetRoom, $host, $targetHost, $durationSeconds) {
            $roomA = LiveRoom::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
            $roomB = LiveRoom::query()->whereKey($targetRoom->id)->lockForUpdate()->firstOrFail();
            $this->assertRoomLive($roomA);
            $this->assertRoomLive($roomB);
            $this->assertVideoOnlyCompatibility($roomA, $roomB);

            $this->assertNoActiveBattleForRooms($roomA->id, $roomB->id);

            $existing = LiveRoomPkBattle::query()
                ->whereIn('status', self::OPEN_STATUSES)
                ->where(function ($query) use ($roomA, $roomB) {
                    $query
                        ->where(function ($q) use ($roomA, $roomB) {
                            $q->where('room_a_id', $roomA->id)->where('room_b_id', $roomB->id);
                        })
                        ->orWhere(function ($q) use ($roomA, $roomB) {
                            $q->where('room_a_id', $roomB->id)->where('room_b_id', $roomA->id);
                        });
                })
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existing) {
                return $existing;
            }

            $battle = LiveRoomPkBattle::query()->create([
                'battle_id' => 'pk_'.Str::lower(Str::random(18)),
                'room_a_id' => $roomA->id,
                'room_b_id' => $roomB->id,
                'host_a_id' => $roomA->host_id,
                'host_b_id' => $roomB->host_id,
                'invited_by_host_id' => $host->id,
                'status' => 'pending',
                'duration_seconds' => $durationSeconds,
                'metadata' => [
                    'invited_room_id' => $roomB->room_id,
                    'inviter_room_id' => $roomA->room_id,
                ],
            ]);

            LiveRoomPkBroadcaster::broadcast($battle, 'pk:invite_sent');
            LiveRoomPkBroadcaster::broadcast($battle, 'pk:invite_received');

            return $battle->fresh(['roomA.host.user', 'roomB.host.user', 'hostA.user', 'hostB.user']);
        });
    }

    public function accept(LiveRoom $room, string $battleId, User $actor): LiveRoomPkBattle
    {
        $host = $this->assertHostOwnsRoom($actor, $room);

        $result = DB::transaction(function () use ($room, $battleId, $host, $actor) {
            $battle = $this->lockBattle($battleId);
            $this->assertBattleTouchesRoom($battle, $room);

            if ($battle->status === 'active') {
                return $battle;
            }
            if ($battle->status === 'rejected' || $battle->status === 'cancelled' || $battle->status === 'completed' || $battle->status === 'expired' || $battle->status === 'failed') {
                return $battle;
            }
            if ($battle->status !== 'pending') {
                throw new HttpException(409, 'Battle is not pending.');
            }

            if ((int) $battle->host_b_id !== (int) $host->id) {
                throw new HttpException(403, 'Only the invited host can accept this PK invite.');
            }

            $hostAUserId = $battle->hostA?->user_id;
            $hostBUserId = $battle->hostB?->user_id;
            if ($hostAUserId && $hostBUserId
                && $this->userBlocks->hasBlockBetween($hostAUserId, $hostBUserId)) {
                throw new HttpException(409, 'Unblock this host before accepting the PK battle.');
            }

            $roomA = LiveRoom::query()->whereKey($battle->room_a_id)->lockForUpdate()->firstOrFail();
            $roomB = LiveRoom::query()->whereKey($battle->room_b_id)->lockForUpdate()->firstOrFail();

            $this->assertRoomLive($roomA);
            $this->assertRoomLive($roomB);
            $this->assertVideoOnlyCompatibility($roomA, $roomB);
            $this->assertNoOtherActiveBattle($battle, $roomA->id, $roomB->id);

            $battle->update([
                'status' => 'active',
                'started_at' => now(),
                'ended_at' => null,
                'winner_room_id' => null,
                'end_reason' => null,
            ]);

            $demotions = [
                ...$this->prepareRoomForPk($roomA, $actor),
                ...$this->prepareRoomForPk($roomB, $actor),
            ];

            return [$battle->fresh(['roomA.host.user', 'roomB.host.user', 'hostA.user', 'hostB.user']), $demotions];
        });

        [$battle, $demotions] = $result;

        foreach ($demotions as $demotion) {
            try {
                $this->liveKitAdmin()->setParticipantCanPublish(
                    $demotion['livekit_room_id'],
                    $demotion['identity'],
                    false,
                    [],
                );
            } catch (\Throwable $e) {
                Log::warning('PK_DEMOTE_LIVEKIT_REVOKE_FAILED', [
                    'battle_id' => $battle->battle_id,
                    'room_id' => $demotion['livekit_room_id'],
                    'identity' => $demotion['identity'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        LiveRoomPkBroadcaster::broadcast($battle, 'pk:accepted');
        LiveRoomPkBroadcaster::broadcast($battle, 'pk:started');

        return $battle;
    }

    public function reject(LiveRoom $room, string $battleId, User $actor): LiveRoomPkBattle
    {
        $host = $this->assertHostOwnsRoom($actor, $room);

        return DB::transaction(function () use ($room, $battleId, $host) {
            $battle = $this->lockBattle($battleId);
            $this->assertBattleTouchesRoom($battle, $room);

            if ($battle->status === 'rejected') {
                return $battle;
            }
            if ($battle->status !== 'pending') {
                throw new HttpException(409, 'Only pending invites can be rejected.');
            }
            if ((int) $battle->host_b_id !== (int) $host->id) {
                throw new HttpException(403, 'Only the invited host can reject this PK invite.');
            }

            $battle->update([
                'status' => 'rejected',
                'ended_at' => now(),
                'end_reason' => 'rejected',
            ]);

            LiveRoomPkBroadcaster::broadcast($battle, 'pk:rejected');

            return $battle->fresh(['roomA.host.user', 'roomB.host.user', 'hostA.user', 'hostB.user']);
        });
    }

    public function cancel(LiveRoom $room, string $battleId, User $actor): LiveRoomPkBattle
    {
        $host = $this->assertHostOwnsRoom($actor, $room);

        return DB::transaction(function () use ($room, $battleId, $host) {
            $battle = $this->lockBattle($battleId);
            $this->assertBattleTouchesRoom($battle, $room);

            if ($battle->status === 'cancelled') {
                return $battle;
            }
            if ($battle->status !== 'pending') {
                throw new HttpException(409, 'Only pending invites can be cancelled.');
            }
            if ((int) $battle->invited_by_host_id !== (int) $host->id) {
                throw new HttpException(403, 'Only the inviting host can cancel this PK invite.');
            }

            $battle->update([
                'status' => 'cancelled',
                'ended_at' => now(),
                'end_reason' => 'cancelled',
            ]);

            LiveRoomPkBroadcaster::broadcast($battle, 'pk:cancelled');

            return $battle->fresh(['roomA.host.user', 'roomB.host.user', 'hostA.user', 'hostB.user']);
        });
    }

    public function end(LiveRoom $room, string $battleId, User $actor, string $reason = 'manual_end'): LiveRoomPkBattle
    {
        return DB::transaction(function () use ($room, $battleId, $actor, $reason) {
            $battle = $this->lockBattle($battleId);
            $this->assertBattleTouchesRoom($battle, $room);
            $this->assertCanEndBattle($battle, $room, $actor);

            return $this->completeBattle($battle, $reason);
        });
    }

    public function endForRoomTermination(LiveRoom $room, string $reason = 'room_ended'): void
    {
        $battle = $this->activeBattleForRoom($room);
        if (!$battle) {
            return;
        }

        DB::transaction(function () use ($battle, $reason) {
            $locked = $this->lockBattle($battle->battle_id);
            if ($locked->status === 'active') {
                $this->completeBattle($locked, $reason);
            }
        });
    }

    public function activeForRoom(LiveRoom $room): ?LiveRoomPkBattle
    {
        if (!$this->isVideoRoom($room)) {
            return null;
        }

        return $this->activeBattleForRoom($room)?->loadMissing(['roomA.host.user', 'roomB.host.user', 'hostA.user', 'hostB.user', 'winnerRoom']);
    }

    public function historyForRoom(LiveRoom $room): LengthAwarePaginator
    {
        if (!$this->isVideoRoom($room)) {
            return LiveRoomPkBattle::query()->whereRaw('1 = 0')->paginate(20);
        }

        return LiveRoomPkBattle::query()
            ->with(['roomA.host.user', 'roomB.host.user', 'hostA.user', 'hostB.user', 'winnerRoom'])
            ->where(function ($query) use ($room) {
                $query->where('room_a_id', $room->id)->orWhere('room_b_id', $room->id);
            })
            ->latest('id')
            ->paginate(20);
    }

    public function mediaToken(LiveRoom $room, LiveRoomPkBattle $battle, User $actor, Request $request): array
    {
        $this->assertBattleTouchesRoom($battle, $room);
        if ($battle->status !== 'active') {
            throw new HttpException(409, 'PK battle is not active.');
        }
        if (!$this->battleIsVideoOnly($battle)) {
            throw new HttpException(409, 'incompatible_room_type');
        }

        $participant = LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->where('user_id', $actor->id)
            ->whereNull('left_at')
            ->latest('id')
            ->first();

        if (!$participant && !$actor->hasAnyRole(['admin', 'super-admin'])) {
            throw new HttpException(403, 'Join the room before fetching a PK media token.');
        }

        $isRoomA = (int) $battle->room_a_id === (int) $room->id;
        $opponentRoom = $isRoomA ? $battle->roomB : $battle->roomA;
        $opponentHost = $isRoomA ? $battle->hostB : $battle->hostA;
        if (!$opponentRoom || !$opponentHost) {
            throw new HttpException(409, 'Opponent room is unavailable.');
        }

        $deviceId = (string) $request->header('X-Device-Id', 'pk-sub');
        $identity = sprintf(
            'pk-sub-%s-%d-%s',
            $battle->battle_id,
            $actor->id,
            substr($deviceId, 0, 16),
        );

        $expiresAt = now()->addSeconds(min(600, max(120, $this->remainingSeconds($battle) + 60)));
        $token = LivekitToken::issue(
            roomId: $opponentRoom->room_id,
            identity: $identity,
            name: $actor->name ?? 'Viewer',
            role: 'viewer',
            roomType: (string) ($opponentRoom->room_type ?? 'video'),
            ttlSec: max(60, now()->diffInSeconds($expiresAt)),
            metadata: [
                'role' => 'viewer',
                'scope' => 'pk_subscribe_only',
                'pk_battle_id' => $battle->battle_id,
                'source_room_id' => $room->room_id,
                'opponent_room_id' => $opponentRoom->room_id,
            ],
            publishSources: [],
            canPublishData: false,
            canUpdateOwnMetadata: false,
        );

        return [
            'battle_id' => $battle->battle_id,
            'opponent_room_id' => $opponentRoom->room_id,
            'opponent_livekit_room_name' => $opponentRoom->room_id,
            'opponent_token' => $token,
            'opponent_host_identity' => 'host-'.$opponentHost->user_id,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function recordGiftScore(LiveRoom $room, WalletTransaction $walletTransaction, int $coins, ?int $giftId = null, ?User $sender = null): ?LiveRoomPkBattle
    {
        if ($coins <= 0) {
            return null;
        }

        return DB::transaction(function () use ($room, $walletTransaction, $coins, $giftId, $sender) {
            $battle = $this->activeBattleForRoom($room, lock: true);
            if (!$battle) {
                return null;
            }

            $existing = LiveRoomPkEvent::query()
                ->where('wallet_transaction_id', $walletTransaction->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $battle;
            }

            LiveRoomPkEvent::query()->create([
                'pk_battle_id' => $battle->id,
                'room_id' => $room->id,
                'user_id' => $sender?->id,
                'event_type' => 'gift',
                'coins' => $coins,
                'wallet_transaction_id' => $walletTransaction->id,
                'gift_id' => $giftId,
                'metadata' => [
                    'wallet_reference' => $walletTransaction->reference,
                    'room_id' => $room->room_id,
                ],
            ]);

            if ((int) $battle->room_a_id === (int) $room->id) {
                $battle->increment('score_a', $coins);
            } else {
                $battle->increment('score_b', $coins);
            }

            $battle->refresh();
            LiveRoomPkBroadcaster::broadcast($battle, 'pk:score_updated');

            return $battle;
        });
    }

    public function cleanup(bool $dryRun = false): array
    {
        $report = [
            'expired_pending' => [],
            'completed_active' => [],
            'failed_inconsistent' => [],
        ];

        $pending = LiveRoomPkBattle::query()
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subSeconds($this->pendingExpirySeconds()))
            ->get();

        foreach ($pending as $battle) {
            $report['expired_pending'][] = $battle->battle_id;
            if ($dryRun) {
                continue;
            }
            DB::transaction(function () use ($battle) {
                $locked = $this->lockBattle($battle->battle_id);
                if ($locked->status === 'pending') {
                    $locked->update([
                        'status' => 'expired',
                        'ended_at' => now(),
                        'end_reason' => 'invite_expired',
                    ]);
                    LiveRoomPkBroadcaster::broadcast($locked, 'pk:expired');
                }
            });
        }

        $active = LiveRoomPkBattle::query()
            ->with(['roomA', 'roomB'])
            ->where('status', 'active')
            ->get();

        foreach ($active as $battle) {
            $reason = null;
            if (!$this->battleIsVideoOnly($battle)) {
                $reason = 'incompatible_room_type';
            } elseif (($battle->ends_at && $battle->ends_at->isPast())) {
                $reason = 'timer_expired';
            } elseif ($battle->roomA?->ended_at || $battle->roomB?->ended_at || $battle->roomA?->status !== 'live' || $battle->roomB?->status !== 'live') {
                $reason = 'room_ended';
            }

            if ($reason === null) {
                continue;
            }

            $report['completed_active'][] = ['battle_id' => $battle->battle_id, 'reason' => $reason];
            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($battle, $reason) {
                $locked = $this->lockBattle($battle->battle_id);
                if ($locked->status === 'active') {
                    $this->completeBattle($locked, $reason);
                }
            });
        }

        $inconsistent = LiveRoomPkBattle::query()
            ->whereNull('started_at')
            ->where('status', 'active')
            ->get();

        foreach ($inconsistent as $battle) {
            $report['failed_inconsistent'][] = $battle->battle_id;
            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($battle) {
                $locked = $this->lockBattle($battle->battle_id);
                if ($locked->status === 'active' && !$locked->started_at) {
                    $locked->update([
                        'status' => 'failed',
                        'ended_at' => now(),
                        'end_reason' => 'inconsistent_state',
                    ]);
                    LiveRoomPkBroadcaster::broadcast($locked, 'pk:ended');
                }
            });
        }

        return $report;
    }

    public function payload(?LiveRoomPkBattle $battle): ?array
    {
        if (!$battle) {
            return null;
        }

        $roomA = $battle->roomA;
        $roomB = $battle->roomB;
        $hostA = $battle->hostA;
        $hostB = $battle->hostB;

        return [
            'battle_id' => $battle->battle_id,
            'status' => $battle->status,
            'duration_seconds' => (int) $battle->duration_seconds,
            'score_a' => (int) $battle->score_a,
            'score_b' => (int) $battle->score_b,
            'started_at' => optional($battle->started_at)->toIso8601String(),
            'ended_at' => optional($battle->ended_at)->toIso8601String(),
            'ends_at' => optional($battle->ends_at)->toIso8601String(),
            'winner_room_id' => $battle->winnerRoom?->room_id,
            'end_reason' => $battle->end_reason,
            'room_a' => $roomA ? [
                'id' => $roomA->room_id,
                'title' => $roomA->title,
                'room_type' => $roomA->room_type,
                'host_user_id' => $roomA->host?->user_id,
            ] : null,
            'room_b' => $roomB ? [
                'id' => $roomB->room_id,
                'title' => $roomB->title,
                'room_type' => $roomB->room_type,
                'host_user_id' => $roomB->host?->user_id,
            ] : null,
            'host_a' => $hostA ? [
                'id' => $hostA->id,
                'user_id' => $hostA->user_id,
                'name' => $hostA->stage_name ?: $hostA->user?->name,
                'avatar_url' => $hostA->user?->avatar_url,
            ] : null,
            'host_b' => $hostB ? [
                'id' => $hostB->id,
                'user_id' => $hostB->user_id,
                'name' => $hostB->stage_name ?: $hostB->user?->name,
                'avatar_url' => $hostB->user?->avatar_url,
            ] : null,
            'updated_at' => optional($battle->updated_at)->toIso8601String(),
        ];
    }

    private function completeBattle(LiveRoomPkBattle $battle, string $reason): LiveRoomPkBattle
    {
        if (!in_array($battle->status, ['active', 'pending'], true)) {
            return $battle;
        }

        $status = $battle->status === 'pending' ? 'cancelled' : 'completed';
        $winnerRoomId = null;
        if ($battle->status === 'active') {
            if ((int) $battle->score_a > (int) $battle->score_b) {
                $winnerRoomId = $battle->room_a_id;
            } elseif ((int) $battle->score_b > (int) $battle->score_a) {
                $winnerRoomId = $battle->room_b_id;
            }
        }

        $battle->update([
            'status' => $status,
            'ended_at' => now(),
            'winner_room_id' => $winnerRoomId,
            'end_reason' => $reason,
        ]);

        LiveRoomPkBroadcaster::broadcast($battle, 'pk:ended');

        return $battle->fresh(['roomA.host.user', 'roomB.host.user', 'hostA.user', 'hostB.user', 'winnerRoom']);
    }

    private function prepareRoomForPk(LiveRoom $room, User $actor): array
    {
        $audienceRole = $this->audienceRole($room);
        $demotions = [];

        $speakers = LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->whereNull('left_at')
            ->where('role', 'speaker')
            ->lockForUpdate()
            ->get();

        foreach ($speakers as $speaker) {
            $meta = $speaker->meta ?? [];
            unset($meta['speaker_since']);
            $speaker->update([
                'role' => $audienceRole,
                'meta' => $meta,
                'muted_by_host' => false,
                'removed_by_host' => true,
            ]);

            $request = LiveRoomSeatRequest::query()
                ->where('live_room_id', $room->id)
                ->where('user_id', $speaker->user_id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($request && $request->status === 'accepted') {
                $request->update([
                    'status' => 'removed',
                    'responded_at' => now(),
                    'responded_by' => $actor->id,
                ]);
            }

            $demotions[] = [
                'livekit_room_id' => $room->room_id,
                'identity' => $this->participantIdentity($speaker),
            ];
        }

        LiveRoomSeatRequest::query()
            ->where('live_room_id', $room->id)
            ->where('status', 'pending')
            ->lockForUpdate()
            ->update([
                'status' => 'cancelled',
                'responded_at' => now(),
                'responded_by' => $actor->id,
            ]);

        return $demotions;
    }

    private function activeBattleForRoom(LiveRoom $room, bool $lock = false): ?LiveRoomPkBattle
    {
        $query = LiveRoomPkBattle::query()
            ->with(['roomA.host.user', 'roomB.host.user', 'hostA.user', 'hostB.user', 'winnerRoom'])
            ->where('status', 'active')
            ->where(function ($q) use ($room) {
                $q->where('room_a_id', $room->id)->orWhere('room_b_id', $room->id);
            })
            ->latest('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function lockBattle(string $battleId): LiveRoomPkBattle
    {
        return LiveRoomPkBattle::query()
            ->with(['roomA.host.user', 'roomB.host.user', 'hostA.user', 'hostB.user', 'winnerRoom'])
            ->where('battle_id', $battleId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertHostOwnsRoom(User $actor, LiveRoom $room): Host
    {
        $host = $room->host;
        if (!$host || (int) $host->user_id !== (int) $actor->id) {
            throw new HttpException(403, 'Only the live room host can perform this action.');
        }

        return $host;
    }

    private function assertCanEndBattle(LiveRoomPkBattle $battle, LiveRoom $room, User $actor): void
    {
        if ($actor->hasAnyRole(['admin', 'super-admin'])) {
            return;
        }

        $host = $room->host;
        if (!$host || (int) $host->user_id !== (int) $actor->id) {
            throw new HttpException(403, 'Only a PK host or admin can end the battle.');
        }

        if (!in_array((int) $host->id, [(int) $battle->host_a_id, (int) $battle->host_b_id], true)) {
            throw new HttpException(403, 'Only a PK host or admin can end the battle.');
        }
    }

    private function assertBattleTouchesRoom(LiveRoomPkBattle $battle, LiveRoom $room): void
    {
        if ((int) $battle->room_a_id !== (int) $room->id && (int) $battle->room_b_id !== (int) $room->id) {
            throw new HttpException(404, 'PK battle not found for this room.');
        }
    }

    private function assertRoomLive(LiveRoom $room): void
    {
        if ($room->status !== 'live' || $room->ended_at) {
            throw new HttpException(409, 'Room is not live.');
        }
    }

    private function assertNoActiveBattleForRooms(int $roomAId, int $roomBId): void
    {
        $exists = LiveRoomPkBattle::query()
            ->where('status', 'active')
            ->where(function ($query) use ($roomAId, $roomBId) {
                $query
                    ->where('room_a_id', $roomAId)
                    ->orWhere('room_b_id', $roomAId)
                    ->orWhere('room_a_id', $roomBId)
                    ->orWhere('room_b_id', $roomBId);
            })
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw new HttpException(409, 'One of the rooms already has an active PK battle.');
        }
    }

    private function assertNoOtherActiveBattle(LiveRoomPkBattle $battle, int $roomAId, int $roomBId): void
    {
        $exists = LiveRoomPkBattle::query()
            ->where('status', 'active')
            ->whereKeyNot($battle->id)
            ->where(function ($query) use ($roomAId, $roomBId) {
                $query
                    ->where('room_a_id', $roomAId)
                    ->orWhere('room_b_id', $roomAId)
                    ->orWhere('room_a_id', $roomBId)
                    ->orWhere('room_b_id', $roomBId);
            })
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw new HttpException(409, 'One of the rooms already has an active PK battle.');
        }
    }

    private function participantIdentity(LiveRoomParticipant $participant): string
    {
        if ($participant->user_id) {
            return 'user:'.$participant->user_id;
        }

        return 'guest:'.$participant->session_id;
    }

    private function audienceRole(LiveRoom $room): string
    {
        return (string) ($room->room_type ?? 'video') === 'audio' ? 'listener' : 'viewer';
    }

    private function remainingSeconds(LiveRoomPkBattle $battle): int
    {
        if (!$battle->ends_at) {
            return (int) $battle->duration_seconds;
        }

        return max(0, now()->diffInSeconds($battle->ends_at, false));
    }

    private function pendingExpirySeconds(): int
    {
        return max(30, (int) config('live_rooms.pk.pending_expiry_seconds', 90));
    }

    private function assertVideoOnlyCompatibility(LiveRoom $roomA, LiveRoom $roomB): void
    {
        if (!$this->isVideoRoom($roomA) || !$this->isVideoRoom($roomB)) {
            throw new HttpException(409, 'incompatible_room_type');
        }
    }

    private function isVideoRoom(?LiveRoom $room): bool
    {
        return $room !== null && (string) ($room->room_type ?? 'video') === 'video';
    }

    private function battleIsVideoOnly(LiveRoomPkBattle $battle): bool
    {
        return $this->isVideoRoom($battle->roomA) && $this->isVideoRoom($battle->roomB);
    }

    private function liveKitAdmin(): LiveKitRoomAdminService
    {
        return app(LiveKitRoomAdminService::class);
    }
}
