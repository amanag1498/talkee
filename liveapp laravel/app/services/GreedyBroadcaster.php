<?php

namespace App\Services;

use App\Models\GreedyRound;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class GreedyBroadcaster
{
    public const CHANNEL = 'games:greedy:events';

    public static function broadcast(string $event, array $payload = []): void
    {
        try {
            Redis::publish(self::CHANNEL, json_encode([
                'event' => $event,
                'at' => now()->toIso8601String(),
                ...$payload,
            ]));
        } catch (Throwable $e) {
            Log::warning('Greedy broadcast failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function roundSnapshot(GreedyRound $round, array $snapshot): void
    {
        self::broadcast('greedy:round_snapshot', [
            'round_id' => $round->id,
            'round_key' => $round->round_key,
            'snapshot' => $snapshot,
        ]);
    }
}
