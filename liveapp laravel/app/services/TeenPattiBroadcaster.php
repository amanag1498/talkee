<?php

namespace App\Services;

use App\Models\TeenPattiRound;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Throwable;

class TeenPattiBroadcaster
{
    public const CHANNEL = 'games:teen_patti:events';

    public static function broadcast(string $event, array $payload = []): void
    {
        try {
            Redis::publish(self::CHANNEL, json_encode([
                'event' => $event,
                'at' => now()->toIso8601String(),
                ...$payload,
            ]));
        } catch (Throwable $e) {
            Log::warning('Teen Patti broadcast failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function roundSnapshot(TeenPattiRound $round, array $snapshot): void
    {
        self::broadcast('teen_patti:round_snapshot', [
            'round_id' => $round->id,
            'round_key' => $round->round_key,
            'snapshot' => $snapshot,
        ]);
    }
}
