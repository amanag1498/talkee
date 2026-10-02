<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CallSessionService;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LiveKitWebhookController extends Controller
{
    public function __construct(private CallSessionService $calls) {}

    public function __invoke(Request $request)
    {
        $apiKey = (string) config('services.livekit.api_key', '');
        $apiSecret = (string) config('services.livekit.api_secret', '');
        $rawBody = $request->getContent();
        $token = preg_replace('/^Bearer\s+/i', '', trim((string) $request->header('Authorization', '')));

        if ($apiKey === '' || $apiSecret === '' || ! $token || $rawBody === '') {
            return response()->json(['ok' => false], 401);
        }

        try {
            $claims = JWT::decode($token, new Key($apiSecret, 'HS256'));
            $expectedHash = base64_encode(hash('sha256', $rawBody, true));
            if (! hash_equals($apiKey, (string) ($claims->iss ?? ''))
                || ! hash_equals($expectedHash, (string) ($claims->sha256 ?? ''))) {
                return response()->json(['ok' => false], 401);
            }
            $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return response()->json(['ok' => false], 401);
        }

        $eventName = (string) ($event['event'] ?? '');
        if (! in_array($eventName, ['participant_left', 'room_finished'], true)) {
            return response()->json(['ok' => true]);
        }

        $roomName = trim((string) data_get($event, 'room.name', ''));
        $identity = (string) data_get($event, 'participant.identity', '');
        if ($roomName === '' || ($eventName === 'participant_left' && ! ctype_digit($identity))) {
            return response()->json(['ok' => true]);
        }

        $createdAt = $event['createdAt'] ?? null;
        $occurredAt = is_numeric($createdAt)
            ? CarbonImmutable::createFromTimestamp((int) $createdAt, 'UTC')
            : CarbonImmutable::now();

        try {
            $this->calls->endCallForMediaDeparture(
                $roomName,
                $eventName === 'participant_left' ? (int) $identity : null,
                $occurredAt,
            );
        } catch (\Throwable $e) {
            Log::error('CALL_LIVEKIT_WEBHOOK_FAIL', [
                'room_name' => $roomName,
                'event' => $eventName,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['ok' => false], 503);
        }

        return response()->json(['ok' => true]);
    }
}
