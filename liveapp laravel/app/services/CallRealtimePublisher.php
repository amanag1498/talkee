<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

            $eventUrl = trim((string) config('services.websocket.event_url', ''));
            if ($eventUrl === '') {
                return;
            }

            try {
                $headers = [];
                $internalKey = trim((string) config('services.websocket.internal_key', ''));
                if ($internalKey !== '') {
                    $headers['X-WS-Internal-Key'] = $internalKey;
                }

                $response = Http::withHeaders($headers)
                    ->timeout(2)
                    ->acceptJson()
                    ->post($eventUrl, [
                        'channel' => $channel,
                        'payload' => $payload,
                    ]);

                if (! $response->successful()) {
                    Log::warning('CALL_REALTIME_HTTP_PUBLISH_FAIL', [
                        'channel' => $channel,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('CALL_REALTIME_HTTP_PUBLISH_FAIL', [
                    'channel' => $channel,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
