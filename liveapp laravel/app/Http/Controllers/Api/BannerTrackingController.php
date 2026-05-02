<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\BannerEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class BannerTrackingController extends Controller
{
    public function impression(Request $request, Banner $banner): JsonResponse
    {
        return $this->track($request, $banner, 'impression');
    }

    public function click(Request $request, Banner $banner): JsonResponse
    {
        return $this->track($request, $banner, 'click');
    }

    private function track(Request $request, Banner $banner, string $eventType): JsonResponse
    {
        $data = $request->validate([
            'placement' => 'nullable|string|max:40',
            'platform' => 'nullable|in:android,ios,web',
            'role' => 'nullable|in:guest,user,host,agency,admin',
            'session_id' => 'nullable|string|max:120',
            'context' => 'nullable|array',
        ]);

        $userId = $this->resolveUserId($request);

        // De-duplicate impressions for the same banner + user.
        if ($eventType === 'impression' && $userId !== null) {
            $alreadyTracked = BannerEvent::query()
                ->where('banner_id', $banner->id)
                ->where('event_type', 'impression')
                ->where('user_id', $userId)
                ->exists();

            if ($alreadyTracked) {
                return response()->json(['ok' => true, 'deduped' => true, 'recorded' => false]);
            }
        }

        BannerEvent::create([
            'banner_id' => $banner->id,
            'user_id' => $userId,
            'event_type' => $eventType,
            'placement' => $data['placement'] ?? $banner->placement,
            'platform' => $data['platform'] ?? null,
            'role' => $data['role'] ?? null,
            'session_id' => $data['session_id'] ?? null,
            'context' => $data['context'] ?? null,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
            'occurred_at' => now(),
        ]);

        return response()->json(['ok' => true, 'deduped' => false, 'recorded' => true]);
    }

    private function resolveUserId(Request $request): ?int
    {
        if ($request->user()) {
            return (int) $request->user()->id;
        }

        $token = $request->bearerToken();
        if (!$token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken || !$accessToken->tokenable_id) {
            return null;
        }

        return (int) $accessToken->tokenable_id;
    }
}
