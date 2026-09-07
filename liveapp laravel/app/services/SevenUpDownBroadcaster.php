<?php

namespace App\Services;

use App\Models\SevenUpDownRound;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class SevenUpDownBroadcaster
{
    public const CHANNEL = 'games:seven_up_down:events';

    public static function broadcast(string $event, array $payload = []): void
    {
        try {
            Redis::publish(self::CHANNEL, json_encode([
                'event' => $event,
                'at' => now()->toIso8601String(),
                ...$payload,
            ]));
        } catch (Throwable $e) {
            Log::warning('Lucky 7 broadcast failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function roundSnapshot(SevenUpDownRound $round, array $snapshot): void
    {
        self::broadcast('seven_up_down:round_snapshot', [
            'round_id' => $round->id,
            'round_key' => $round->round_key,
            'snapshot' => $snapshot,
        ]);
    }
}
