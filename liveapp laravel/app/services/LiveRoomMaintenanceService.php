<?php

namespace App\Services;

use App\Models\LiveRoom;
use App\Models\LiveRoomParticipant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class LiveRoomMaintenanceService
{
    public function __construct(
        private LiveRoomStateService $state,
        private LiveRoomSeatService $seats,
    )
    {
    }

    public function cleanup(int $staleMinutes = 2): array
    {
        $cutoff = now()->subMinutes($staleMinutes);
        $ended = [];

        LiveRoom::query()
            ->with(['participants'])
            ->where('status', 'live')
            ->whereNull('ended_at')
            ->orderBy('id')
            ->chunkById(100, function ($rooms) use ($cutoff, &$ended) {
                foreach ($rooms as $room) {
                    $reason = $this->cleanupReason($room, $cutoff);
                    if (!$reason) {
                        continue;
                    }

                    $this->endRoom($room, $reason);
                    $ended[] = [
                        'room_id' => $room->room_id,
                        'reason' => $reason,
                    ];
                }
            });

        return [
            'count' => count($ended),
            'ended' => $ended,
        ];
    }

    public function reconcile(bool $fix = false): array
    {
        $liveWithoutHost = LiveRoom::query()
            ->with('participants')
            ->where('status', 'live')
            ->whereNull('ended_at')
            ->get()
            ->filter(fn (LiveRoom $room) => !$room->participants->contains(fn ($p) => $p->role === 'host' && $p->left_at === null))
            ->values();

        $endedWithOpenParticipants = LiveRoom::query()
            ->with(['participants' => fn ($q) => $q->whereNull('left_at')])
            ->where('status', 'ended')
            ->orWhereNotNull('ended_at')
            ->get()
            ->filter(fn (LiveRoom $room) => $room->participants->isNotEmpty())
            ->values();

        $duplicateParticipants = $this->duplicateOpenParticipants();

        $dbLiveIds = $this->state->liveRoomsPayload()->pluck('room_id')->map(fn ($id) => (string) $id)->values();
        $redisLiveIds = collect(Redis::smembers('rooms:live') ?: [])->map(fn ($id) => (string) $id)->values();
        $redisMismatch = [
            'missing_in_redis' => $dbLiveIds->diff($redisLiveIds)->values()->all(),
            'extra_in_redis' => $redisLiveIds->diff($dbLiveIds)->values()->all(),
        ];

        $fixed = [
            'ended_rooms' => 0,
            'closed_participants' => 0,
            'duplicate_groups_resolved' => 0,
            'redis_synced' => false,
        ];

        if ($fix) {
            foreach ($liveWithoutHost as $room) {
                $this->endRoom($room, 'host_disconnected');
                $fixed['ended_rooms']++;
            }

            foreach ($endedWithOpenParticipants as $room) {
                $fixed['closed_participants'] += $this->closeOpenParticipants($room);
            }

            foreach ($duplicateParticipants as $group) {
                $this->resolveDuplicateGroup((array) $group);
                $fixed['duplicate_groups_resolved']++;
            }

            $this->state->syncRedis();
            $fixed['redis_synced'] = true;
        }

        return [
            'live_room_without_host' => [
                'count' => $liveWithoutHost->count(),
                'rooms' => $liveWithoutHost->pluck('room_id')->values()->all(),
            ],
            'ended_room_with_open_participants' => [
                'count' => $endedWithOpenParticipants->count(),
                'rooms' => $endedWithOpenParticipants->pluck('room_id')->values()->all(),
            ],
            'duplicate_open_participants' => [
                'count' => count($duplicateParticipants),
                'groups' => $duplicateParticipants,
            ],
            'redis_db_mismatch' => $redisMismatch,
            'fixed' => $fixed,
        ];
    }

    public function endRoom(LiveRoom $room, string $reason): LiveRoom
    {
        $room = $this->seats->endRoom($room, $reason);

        Log::info('LIVE_ROOM_MAINTENANCE_ENDED', [
            'room_id' => $room->room_id,
            'reason' => $reason,
        ]);

        return $room;
    }

    private function cleanupReason(LiveRoom $room, $cutoff): ?string
    {
        $hasOpenHost = $room->participants->contains(fn ($p) => $p->role === 'host' && $p->left_at === null);
        $hasAnyHost = $room->participants->contains(fn ($p) => $p->role === 'host');
        $lastActivity = $room->last_activity_at ?? $room->updated_at ?? $room->started_at ?? $room->created_at;

        if ($lastActivity && $lastActivity->lte($cutoff)) {
            return 'stale_cleanup';
        }

        if ($hasOpenHost) {
            return null;
        }

        return $hasAnyHost ? 'host_left' : 'host_disconnected';
    }

    private function closeOpenParticipants(LiveRoom $room): int
    {
        $now = now();
        $participants = LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->whereNull('left_at')
            ->lockForUpdate()
            ->get();

        foreach ($participants as $participant) {
            $joinedAt = $participant->joined_at ?? $now;
            $participant->update([
                'left_at' => $now,
                'duration_seconds' => max(0, $joinedAt->diffInSeconds($now)),
            ]);
        }

        return $participants->count();
    }

    private function duplicateOpenParticipants(): array
    {
        $byUser = LiveRoomParticipant::query()
            ->selectRaw('live_room_id, user_id, COUNT(*) as dup_count')
            ->whereNotNull('user_id')
            ->whereNull('left_at')
            ->groupBy('live_room_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->map(fn ($row) => [
                'type' => 'user',
                'live_room_id' => (int) $row->live_room_id,
                'user_id' => (int) $row->user_id,
                'dup_count' => (int) $row->dup_count,
            ])
            ->all();

        $bySession = LiveRoomParticipant::query()
            ->selectRaw('live_room_id, session_id, COUNT(*) as dup_count')
            ->whereNull('user_id')
            ->whereNotNull('session_id')
            ->whereNull('left_at')
            ->groupBy('live_room_id', 'session_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->map(fn ($row) => [
                'type' => 'session',
                'live_room_id' => (int) $row->live_room_id,
                'session_id' => (string) $row->session_id,
                'dup_count' => (int) $row->dup_count,
            ])
            ->all();

        return array_merge($byUser, $bySession);
    }

    private function resolveDuplicateGroup(array $group): void
    {
        DB::transaction(function () use ($group) {
            $query = LiveRoomParticipant::query()
                ->where('live_room_id', $group['live_room_id'])
                ->whereNull('left_at')
                ->lockForUpdate();

            if (($group['type'] ?? '') === 'user') {
                $query->where('user_id', $group['user_id']);
            } else {
                $query->whereNull('user_id')->where('session_id', $group['session_id']);
            }

            $participants = $query->orderByDesc('id')->get();
            $keeper = $participants->shift();
            if (!$keeper) {
                return;
            }

            $now = now();
            foreach ($participants as $participant) {
                $joinedAt = $participant->joined_at ?? $now;
                $participant->update([
                    'left_at' => $now,
                    'duration_seconds' => max(0, $joinedAt->diffInSeconds($now)),
                ]);
            }
        });
    }
}
