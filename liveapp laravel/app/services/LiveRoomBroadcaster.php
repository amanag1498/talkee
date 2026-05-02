<?php

namespace App\Services;

use App\Models\LiveRoom;
use Illuminate\Support\Facades\Redis;

class LiveRoomBroadcaster
{
    /**
     * Publish a room event to Redis so Node can fan out via Socket.IO.
     * $type: 'created' | 'live' | 'updated' | 'ended'
     */
    public static function broadcast(LiveRoom $room, string $type): void
    {
        $payload = app(LiveRoomStateService::class)->payload($room);
        $roomId = (string) ($payload['id'] ?? $payload['room_id'] ?? $room->room_id);

        Redis::set("rooms:room:{$roomId}", json_encode($payload));

        if ($type === 'ended' || (($payload['status'] ?? null) !== 'live')) {
            Redis::srem('rooms:live', $roomId);
        } else {
            Redis::sadd('rooms:live', $roomId);
        }

        Redis::publish('rooms:events', json_encode([
            'type' => $type,
            'room' => $payload,
            'at'   => now()->toIso8601String(),
        ]));
    }
}
