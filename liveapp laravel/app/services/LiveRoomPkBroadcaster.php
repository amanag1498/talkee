<?php

namespace App\Services;

use App\Models\LiveRoomPkBattle;
use Illuminate\Support\Facades\Redis;

class LiveRoomPkBroadcaster
{
    public static function broadcast(LiveRoomPkBattle $battle, string $event, array $extra = []): void
    {
        $payload = array_merge(
            ['event' => $event],
            app(LiveRoomPkService::class)->payload($battle->fresh([
                'roomA.host.user',
                'roomB.host.user',
                'hostA.user',
                'hostB.user',
                'winnerRoom',
            ])),
            $extra,
            ['updated_at' => now()->toIso8601String()],
        );

        Redis::publish('rooms:pk-events', json_encode($payload));
    }
}
