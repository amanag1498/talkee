<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LiveKitRoomAdminService
{
    public function setParticipantCanPublish(string $roomId, string $identity, bool $canPublish, ?array $publishSources = null): void
    {
        $baseUrl = $this->httpBaseUrl();
        $token = LivekitToken::serverToken(roomId: $roomId);

        if ($baseUrl === '' || $token === '') {
            throw new \RuntimeException('LiveKit admin credentials are not configured.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(8)
            ->post(rtrim($baseUrl, '/').'/twirp/livekit.RoomService/UpdateParticipant', [
                'room' => $roomId,
                'identity' => $identity,
                'permission' => [
                    'canPublish' => $canPublish,
                    'canSubscribe' => true,
                    'canPublishData' => true,
                    'canPublishSources' => $canPublish ? array_values($publishSources ?? []) : [],
                ],
            ]);

        if ($response->failed()) {
            $message = sprintf(
                'LiveKit UpdateParticipant failed (%d): %s',
                $response->status(),
                trim($response->body())
            );
            Log::error('LIVEKIT_UPDATE_PARTICIPANT_FAILED', [
                'room_id' => $roomId,
                'identity' => $identity,
                'can_publish' => $canPublish,
                'publish_sources' => $publishSources,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException($message);
        }
    }

    private function httpBaseUrl(): string
    {
        $url = (string) config('services.livekit.http_url', '');
        if ($url !== '') {
            return $url;
        }

        $wsUrl = (string) config('services.livekit.ws_url', '');
        if ($wsUrl === '') {
            return '';
        }

        return preg_replace('/^ws/i', 'http', $wsUrl) ?? '';
    }
}
