<?php

namespace App\Services;

use App\Models\LiveRoom;
use App\Models\LiveRoomParticipant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

class LiveRoomStateService
{
    public function __construct(private LiveRoomPkService $pk)
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
        return $this->liveRoomsQuery()
            ->get()
            ->map(fn (LiveRoom $room) => $this->payload($room));
    }

    public function payload(LiveRoom $room): array
    {
        $room->loadMissing(['host.user']);

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

        return [
            'id' => (string) $room->room_id,
            'room_id' => (string) $room->room_id,
            'title' => (string) ($room->title ?? ''),
            'room_type' => (string) ($room->room_type ?? 'video'),
            'status' => (string) $room->status,
            'host_id' => $hostUser ? (int) $hostUser->id : null,
            'host_name' => optional($host)->stage_name ?: optional($hostUser)->name,
            'thumbnail' => optional($hostUser)->avatar_url,
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
            'peak_viewers' => (int) ($room->peak_viewers ?? 0),
            'peak_listeners' => (int) ($room->peak_viewers ?? 0),
            'started_at' => optional($room->started_at)?->toIso8601String(),
            'ended_at' => optional($room->ended_at)?->toIso8601String(),
            'end_reason' => $room->end_reason,
            'last_activity_at' => optional($room->last_activity_at)?->toIso8601String(),
            'updated_at' => optional($room->updated_at)?->toIso8601String(),
            'host_active' => $openHostCount > 0,
            'pk_active' => $this->pk->payload($this->pk->activeForRoom($room)),
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
