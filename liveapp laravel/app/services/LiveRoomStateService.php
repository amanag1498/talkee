<?php

namespace App\Services;

use App\Models\HostFollower;
use App\Models\LiveRoom;
use App\Models\LiveRoomPkBattle;
use App\Models\LiveRoomReminder;
use App\Models\LiveRoomParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

class LiveRoomStateService
{
    public function __construct(
        private LiveRoomPkService $pk,
        private ProfileFrameService $frames,
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

    public function liveRoomsQuery(): Builder
    {
        return LiveRoom::query()
            ->with(['host.user'])
            ->where('status', 'live')
            ->whereNull('ended_at')
            ->withCount([
                'participants as participant_count' => fn ($q) => $q->whereNull('left_at'),
                'participants as viewer_count' => fn ($q) => $q->whereNull('left_at')->where('role', 'viewer'),
                'participants as listener_count' => fn ($q) => $q->whereNull('left_at')->where('role', 'listener'),
                'participants as speaker_count' => fn ($q) => $q->whereNull('left_at')->where('role', 'speaker'),
                'participants as open_host_count' => fn ($q) => $q->whereNull('left_at')->where('role', 'host'),
                'seatRequests as pending_seat_request_count' => fn ($q) => $q->where('status', 'pending'),
            ])
            ->orderByRaw("CASE WHEN room_type = 'audio' THEN 0 ELSE 1 END")
            ->orderByDesc('started_at')
            ->orderByDesc('id');
    }

    public function liveRoomsPayload(): Collection
    {
        return $this->payloads($this->liveRoomsQuery()->get());
    }

    public function payload(LiveRoom $room, ?User $viewer = null): array
    {
        return $this->payloads(new EloquentCollection([$room]), $viewer)->first() ?? [];
    }

    public function payloads(iterable $rooms, ?User $viewer = null): Collection
    {
        $collection = $rooms instanceof EloquentCollection
            ? new EloquentCollection($rooms->all())
            : new EloquentCollection($rooms instanceof Collection ? $rooms->all() : collect($rooms)->all());
        if ($collection->isEmpty()) {
            return collect();
        }

        $collection->loadMissing(['host.user']);
        $hostIds = $collection->pluck('host.id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $scheduledRoomIds = $collection
            ->where('status', 'scheduled')
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();
        $videoRoomIds = $collection
            ->where('room_type', 'video')
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();

        $followerCounts = $hostIds->isEmpty()
            ? collect()
            : HostFollower::query()
                ->selectRaw('host_id, COUNT(*) as aggregate')
                ->whereIn('host_id', $hostIds->all())
                ->groupBy('host_id')
                ->pluck('aggregate', 'host_id');

        $followingHostIds = (!$viewer || $hostIds->isEmpty())
            ? []
            : array_fill_keys(
                HostFollower::query()
                    ->where('user_id', $viewer->id)
                    ->whereIn('host_id', $hostIds->all())
                    ->pluck('host_id')
                    ->map(fn ($id) => (int) $id)
                    ->all(),
                true,
            );

        $reminderRoomIds = (!$viewer || $scheduledRoomIds->isEmpty())
            ? []
            : array_fill_keys(
                LiveRoomReminder::query()
                    ->where('user_id', $viewer->id)
                    ->whereIn('live_room_id', $scheduledRoomIds->all())
                    ->pluck('live_room_id')
                    ->map(fn ($id) => (int) $id)
                    ->all(),
                true,
            );

        $pkPayloadByRoomId = [];
        if ($videoRoomIds->isNotEmpty()) {
            $battles = LiveRoomPkBattle::query()
                ->with(['roomA.host.user', 'roomB.host.user', 'hostA.user', 'hostB.user', 'winnerRoom'])
                ->where('status', 'active')
                ->where(function ($query) use ($videoRoomIds) {
                    $query
                        ->whereIn('room_a_id', $videoRoomIds->all())
                        ->orWhereIn('room_b_id', $videoRoomIds->all());
                })
                ->get();

            foreach ($battles as $battle) {
                $payload = $this->pk->payload($battle);
                if (!$payload) {
                    continue;
                }
                if ($battle->room_a_id) {
                    $pkPayloadByRoomId[(int) $battle->room_a_id] = $payload;
                }
                if ($battle->room_b_id) {
                    $pkPayloadByRoomId[(int) $battle->room_b_id] = $payload;
                }
            }
        }

        return $collection->map(function (LiveRoom $room) use ($viewer, $followerCounts, $followingHostIds, $reminderRoomIds, $pkPayloadByRoomId) {
            return $this->buildPayload(
                $room,
                $viewer,
                $followerCounts,
                $followingHostIds,
                $reminderRoomIds,
                $pkPayloadByRoomId,
            );
        });
    }

    private function buildPayload(
        LiveRoom $room,
        ?User $viewer,
        Collection $followerCounts,
        array $followingHostIds,
        array $reminderRoomIds,
        array $pkPayloadByRoomId,
    ): array {
        $host = $room->host;
        $hostUser = optional($host)->user;

        $participantCount = array_key_exists('participant_count', $room->getAttributes())
            ? (int) $room->getAttribute('participant_count')
            : $this->openParticipantsCount($room);
        $viewerCount = array_key_exists('viewer_count', $room->getAttributes())
            ? (int) $room->getAttribute('viewer_count')
            : $this->openViewersCount($room);
        $listenerCount = array_key_exists('listener_count', $room->getAttributes())
            ? (int) $room->getAttribute('listener_count')
            : $this->openListenersCount($room);
        $openHostCount = array_key_exists('open_host_count', $room->getAttributes())
            ? (int) $room->getAttribute('open_host_count')
            : $this->openHostsCount($room);
        $speakerParticipantCount = array_key_exists('speaker_count', $room->getAttributes())
            ? (int) $room->getAttribute('speaker_count')
            : $this->openSpeakersCount($room);
        $speakerCount = $openHostCount + $speakerParticipantCount;
        $audienceCount = $room->room_type === 'audio' ? $listenerCount : $viewerCount;
        $pendingSeatRequestCount = array_key_exists('pending_seat_request_count', $room->getAttributes())
            ? (int) $room->getAttribute('pending_seat_request_count')
            : (int) $room->seatRequests()->where('status', 'pending')->count();
        $followerCount = $host?->id ? (int) ($followerCounts->get((int) $host->id) ?? 0) : 0;
        $isFollowingHost = $viewer && $host?->id ? isset($followingHostIds[(int) $host->id]) : false;
        $hasReminder = $viewer && $room->status === 'scheduled' ? isset($reminderRoomIds[(int) $room->id]) : false;

        return [
            'id' => (string) $room->room_id,
            'room_id' => (string) $room->room_id,
            'title' => (string) ($room->title ?? ''),
            'room_type' => (string) ($room->room_type ?? 'video'),
            'status' => (string) $room->status,
            'host_id' => $hostUser ? (int) $hostUser->id : null,
            'host_profile_id' => $host ? (int) $host->id : null,
            'host_name' => optional($host)->stage_name ?: optional($hostUser)->name,
            'thumbnail' => optional($hostUser)->avatar_url,
            'host_profile_frame' => $hostUser ? $this->frames->equippedFramePayload($hostUser) : null,
            'capacity' => (int) data_get($room->meta, 'capacity', 0),
            'max_speakers' => max(1, (int) ($room->max_speakers ?? $this->configuredMaxSpeakers($room))),
            'max_participants' => max(1, (int) ($room->max_participants ?? $this->configuredMaxParticipants($room))),
            'is_locked' => (bool) ($room->is_locked ?? false),
            'topic' => $room->topic,
            'language' => $room->language,
            'participant_count' => $participantCount,
            'viewer_count' => $viewerCount,
            'listener_count' => $listenerCount,
            'audience_count' => $audienceCount,
            'speaker_count' => $speakerCount,
            'speaker_participant_count' => $speakerParticipantCount,
            'pending_seat_request_count' => $pendingSeatRequestCount,
            'follower_count' => $followerCount,
            'is_following_host' => $isFollowingHost,
            'has_reminder' => $hasReminder,
            'peak_viewers' => (int) ($room->peak_viewers ?? 0),
            'scheduled_at' => optional($room->scheduled_at)?->toIso8601String(),
            'peak_listeners' => (int) ($room->peak_viewers ?? 0),
            'started_at' => optional($room->started_at)?->toIso8601String(),
            'ended_at' => optional($room->ended_at)?->toIso8601String(),
            'end_reason' => $room->end_reason,
            'last_activity_at' => optional($room->last_activity_at)?->toIso8601String(),
            'updated_at' => optional($room->updated_at)?->toIso8601String(),
            'host_active' => $openHostCount > 0,
            'pk_active' => $pkPayloadByRoomId[(int) $room->id] ?? null,
        ];
    }

    public function syncRedis(): int
    {
        $rooms = $this->liveRoomsPayload()->values();

        Redis::del('rooms:live');

        /** @var array<int, array<string, mixed>> $roomRows */
        $roomRows = $rooms->all();
        foreach ($roomRows as $payload) {
            $roomId = (string) $payload['room_id'];
            Redis::sadd('rooms:live', $roomId);
            Redis::set("rooms:room:{$roomId}", json_encode($payload));
        }

        return count($roomRows);
    }

    public function touchRoom(LiveRoom $room): void
    {
        $room->forceFill(['last_activity_at' => now()])->save();
    }

    private function openParticipantsCount(LiveRoom $room): int
    {
        return LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->whereNull('left_at')
            ->count();
    }

    private function openViewersCount(LiveRoom $room): int
    {
        return LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->whereNull('left_at')
            ->where('role', 'viewer')
            ->count();
    }

    private function openListenersCount(LiveRoom $room): int
    {
        return LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->whereNull('left_at')
            ->where('role', 'listener')
            ->count();
    }

    private function openHostsCount(LiveRoom $room): int
    {
        return LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->whereNull('left_at')
            ->where('role', 'host')
            ->count();
    }

    private function openSpeakersCount(LiveRoom $room): int
    {
        return LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->whereNull('left_at')
            ->where('role', 'speaker')
            ->count();
    }
}
