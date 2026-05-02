<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class LivekitToken
{
    public static function serverToken(?int $ttlSec = null, ?string $roomId = null): string
    {
        $apiKey = (string) env('LK_API_KEY');
        $apiSecret = (string) env('LK_API_SECRET');
        $ttl = $ttlSec ?? 300;
        $now = time();

        $videoGrant = [
            'roomAdmin' => true,
            'roomList' => true,
        ];
        if ($roomId !== null && trim($roomId) !== '') {
            $videoGrant['room'] = trim($roomId);
        }

        return JWT::encode([
            'jti' => (string) Str::uuid(),
            'iss' => $apiKey,
            'sub' => 'server',
            'nbf' => $now - 10,
            'iat' => $now,
            'exp' => $now + $ttl,
            'video' => $videoGrant,
        ], $apiSecret, 'HS256');
    }

    public static function issue(
        string $roomId,
        string $identity,
        ?string $name = null,
        string $role = 'viewer',   // viewer|host|moderator|admin
        string $roomType = 'video',
        ?int $ttlSec = null,
        ?array $metadata = null,
        ?array $publishSources = null,
        bool $canPublishData = true,
        bool $canUpdateOwnMetadata = true,
    ): string {
        $apiKey    = (string) env('LK_API_KEY');
        $apiSecret = (string) env('LK_API_SECRET');
        $ttl       = $ttlSec ?? (int) env('LK_TTL', 3600);
        Log::info('LIVEKIT_TOKEN_ISSUE_BEGIN', [
            'room_id' => $roomId,
            'identity' => $identity,
            'role' => $role,
            'ttl' => $ttl,
            'has_api_key' => $apiKey !== '',
            'has_api_secret' => $apiSecret !== '',
        ]);

        $now = time();

        $isPublisher = in_array($role, ['host', 'speaker', 'moderator', 'admin'], true);
        $isAdmin     = in_array($role, ['moderator', 'admin'], true);
        $resolvedMetadata = array_merge([
            'role' => $role,
            'room_type' => $roomType,
            'audio_only' => $roomType === 'audio',
        ], $metadata ?? []);

        // ✅ TOP-LEVEL video grant (not under "grants")
        $videoGrant = [
            'room'                 => $roomId,
            'roomJoin'             => true,
            'canPublish'           => $isPublisher,
            'canSubscribe'         => true,
            'canPublishData'       => $canPublishData,
            'canUpdateOwnMetadata' => $canUpdateOwnMetadata,
        ];
        if ($isPublisher && is_array($publishSources) && !empty($publishSources)) {
            $videoGrant['canPublishSources'] = array_values($publishSources);
        }
        if ($isPublisher) {
            $videoGrant['roomCreate'] = true;
        }
        if ($isAdmin) {
            $videoGrant['roomAdmin'] = true;
        }

        $payload = [
            'jti'      => (string) Str::uuid(),
            'iss'      => $apiKey,
            'sub'      => $identity,
            'name'     => $name,
            'nbf'      => $now - 10,
            'iat'      => $now,
            'exp'      => $now + $ttl,
            'metadata' => json_encode($resolvedMetadata),
            'video'    => $videoGrant, // 👈 this is the key change
        ];

        $jwt = JWT::encode($payload, $apiSecret, 'HS256');
        Log::info('LIVEKIT_TOKEN_ISSUE_SUCCESS', [
            'room_id' => $roomId,
            'identity' => $identity,
            'role' => $role,
            'expires_at' => $payload['exp'],
        ]);

        return $jwt;
    }
}
