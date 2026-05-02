<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class CallRealtimePublisher
{
    public static function publish(string $channel, array $payload): void
    {
        DB::afterCommit(function () use ($channel, $payload): void {
            if (app()->runningUnitTests()) {
                return;
            }

            try {
                Redis::publish($channel, json_encode($payload));
            } catch (\Throwable $e) {
                Log::warning('CALL_REALTIME_PUBLISH_FAIL', [
                    'channel' => $channel,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
