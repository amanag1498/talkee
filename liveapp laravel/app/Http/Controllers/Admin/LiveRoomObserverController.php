<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LiveRoom;
use App\Models\LiveRoomAdminAudit;
use App\Services\LivekitToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LiveRoomObserverController extends Controller
{
    public function show(LiveRoom $live_room)
    {
        $live_room->load(['host.user']);

        return view('admin.live_rooms.watch', [
            'live_room' => $live_room,
        ]);
    }

    public function token(Request $request, LiveRoom $live_room): JsonResponse
    {
        if ($live_room->status !== 'live' || $live_room->ended_at) {
            return response()->json([
                'ok' => false,
                'message' => 'This room is no longer live.',
            ], 409);
        }

        $apiKey = trim((string) config('services.livekit.api_key', ''));
        $apiSecret = trim((string) config('services.livekit.api_secret', ''));
        $wsUrl = trim((string) (config('services.livekit.browser_ws_url') ?: config('services.livekit.ws_url', '')));
        $wsScheme = strtolower((string) parse_url($wsUrl, PHP_URL_SCHEME));
        $roomType = (string) ($live_room->room_type ?? 'video');
        $invalidConfiguration = $apiKey === ''
            || $apiSecret === ''
            || $wsUrl === ''
            || ! in_array($wsScheme, ['ws', 'wss'], true)
            || trim((string) $live_room->room_id) === '';
        $appScheme = strtolower((string) parse_url((string) config('app.url', ''), PHP_URL_SCHEME));
        $secureAdminPortal = $request->isSecure() || $appScheme === 'https';
        $blockedMixedContent = $secureAdminPortal && $wsScheme !== 'wss';

        if ($invalidConfiguration || $blockedMixedContent) {
            return response()->json([
                'ok' => false,
                'message' => $blockedMixedContent
                    ? 'Silent watch requires LIVEKIT_BROWSER_WS_URL to use wss:// on this HTTPS admin portal.'
                    : 'Silent watch is not configured on this server.',
            ], 503);
        }

        $admin = $request->user();
        $identity = 'admin-observer:'.$admin?->id.':'.Str::uuid()->toString();
        $ttl = 15 * 60;

        $token = LivekitToken::issue(
            roomId: (string) $live_room->room_id,
            identity: $identity,
            name: 'Admin Observer',
            role: 'admin_observer',
            roomType: $roomType,
            ttlSec: $ttl,
            metadata: [
                'role' => 'admin_observer',
                'hidden' => true,
                'admin_id' => $admin?->id,
                'room_type' => $roomType,
            ],
            publishSources: null,
            canPublishData: false,
            canUpdateOwnMetadata: false,
            hidden: true,
        );

        LiveRoomAdminAudit::query()->create([
            'live_room_id' => $live_room->id,
            'admin_id' => $admin?->id,
            'target_user_id' => optional($live_room->host)->user_id,
            'action' => 'admin_observer_token_issued',
            'before_status' => $live_room->status,
            'after_status' => $live_room->status,
            'reason' => 'silent_watch',
            'meta' => [
                'identity' => $identity,
                'room_type' => $roomType,
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'ttl_seconds' => $ttl,
            ],
        ]);

        return response()->json([
            'ok' => true,
            'room' => $live_room->room_id,
            'room_type' => $roomType,
            'identity' => $identity,
            'ws_url' => $wsUrl,
            'token' => $token,
            'expires_in' => $ttl,
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
