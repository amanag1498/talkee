<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\{PlanController, SubscriptionController, LiveRoomController, LiveRoomSeatRequestController, LiveRoomGiftController, ProfileController, ApplicationApiController, WalletApiController, RechargePlanController, RechargeOrderController, NotificationApiController, PushTokenController, LiveRoomIngestController, OpsController, BannerController, BannerTrackingController, LiveUsersController, CallController, CallReportApiController, LevelController, HostFollowController, EntryPackController, LiveRoomPkController, ThemeController, HostModerationController, UserReportController, UnblockRequestController, AdminModerationController, WsModerationController, DashboardLeaderboardController};
use App\Http\Controllers\Auth\FirebaseAuthApiController;
use App\Models\UserSubscription;
use App\Services\AppSettingsService;
use App\Services\ThemeUnlockService;
use Illuminate\Http\Request;

Route::get('/ping', fn() => response()->json(['ok' => true, 'ts' => now()]));
Route::get('/app-config', fn(Request $request, AppSettingsService $settings, ThemeUnlockService $themes) => response()->json([
    'ok' => true,
    'data' => $settings->publicAppPayload(
        $request->user('sanctum'),
        $themes,
        (($code = (int) $request->header('X-App-Version-Code', 0)) > 0 ? $code : null),
    ),
]));
Route::get('/app/settings', fn(Request $request, AppSettingsService $settings, ThemeUnlockService $themes) => response()->json([
    'ok' => true,
    'data' => $settings->publicAppPayload(
        $request->user('sanctum'),
        $themes,
        (($code = (int) $request->header('X-App-Version-Code', 0)) > 0 ? $code : null),
    ),
]));
Route::get('/banners', [BannerController::class, 'index']);
Route::get('/health/live', [OpsController::class, 'live']);
Route::get('/health/ready', [OpsController::class, 'ready']);
Route::get('/metrics', [OpsController::class, 'metrics']);
Route::get('/recharge/plans', [RechargePlanController::class, 'index'])->middleware('feature_enabled:wallet_recharge_enabled');
Route::get('/levels', [LevelController::class, 'index']);

// host/admin only
Route::middleware(['auth:sanctum','throttle:240,1'])->group(function () {
    Route::get('/live/rooms',                           [LiveRoomController::class, 'index'])->middleware('live_room_feature_enabled');
    Route::get('/live/audio-rooms',                     [LiveRoomController::class, 'audioIndex'])->middleware('feature_enabled:audio_rooms_enabled');
    Route::post('/live/rooms',                          [LiveRoomController::class, 'createOrStart'])->middleware('live_room_feature_enabled');
    Route::post('/live/audio-rooms',                    [LiveRoomController::class, 'createAudio'])->middleware('feature_enabled:audio_rooms_enabled');
    Route::post('/live/rooms/{live_room:room_id}/heartbeat', [LiveRoomController::class, 'heartbeat'])->middleware('live_room_feature_enabled');
    Route::post('/live/rooms/{live_room:room_id}/end',  [LiveRoomController::class, 'end'])->middleware('live_room_feature_enabled');
    Route::get('/live/rooms/{room_id}/seat-requests', [LiveRoomSeatRequestController::class, 'index'])->middleware('live_room_feature_enabled');
    Route::post('/live/rooms/{room_id}/seat-requests', [LiveRoomSeatRequestController::class, 'store'])->middleware('live_room_feature_enabled');
    Route::post('/live/rooms/{room_id}/seat-requests/{id}/accept', [LiveRoomSeatRequestController::class, 'accept'])->middleware('live_room_feature_enabled');
    Route::post('/live/rooms/{room_id}/seat-requests/{id}/reject', [LiveRoomSeatRequestController::class, 'reject'])->middleware('live_room_feature_enabled');
    Route::post('/live/rooms/{room_id}/seat-requests/{id}/cancel', [LiveRoomSeatRequestController::class, 'cancel'])->middleware('live_room_feature_enabled');
    Route::post('/live/rooms/{room_id}/speakers/{user_id}/remove', [LiveRoomSeatRequestController::class, 'removeSpeaker'])->middleware('live_room_feature_enabled');
    Route::post('/live/rooms/{room_id}/speakers/{user_id}/mute', [LiveRoomSeatRequestController::class, 'muteSpeaker'])->middleware('live_room_feature_enabled');
    Route::get('/live/rooms/{room_id}/speakers', [LiveRoomSeatRequestController::class, 'speakers'])->middleware('live_room_feature_enabled');
    Route::get('/gifts', [LiveRoomGiftController::class, 'index'])->middleware('feature_enabled:gifts_enabled');
    Route::post('/live/rooms/{room_id}/gifts', [LiveRoomGiftController::class, 'store'])->middleware(['feature_enabled:gifts_enabled', 'live_room_feature_enabled']);
    Route::get('/notifications', [NotificationApiController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationApiController::class, 'unreadCount']); // NEW
    Route::post('/notifications/read', [NotificationApiController::class, 'markManyRead']);        // NEW
    Route::post('/notifications/{id}/read', [NotificationApiController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationApiController::class, 'markAllRead']);
    Route::post('/push/register', [PushTokenController::class, 'register']);
    Route::post('/push/unregister', [PushTokenController::class, 'unregister']);


    Route::get('/plans', [PlanController::class,'index'])->middleware('feature_enabled:subscriptions_enabled');
    Route::post('/subscriptions', [SubscriptionController::class,'purchase'])->middleware('feature_enabled:subscriptions_enabled');
    Route::get('/subscriptions/me', [SubscriptionController::class,'mine'])->middleware('feature_enabled:subscriptions_enabled');
    Route::post('/subscriptions/{id}/cancel', [SubscriptionController::class,'cancel'])->middleware('feature_enabled:subscriptions_enabled');
    Route::get('/subscriptions/welcome-tip', [\App\Http\Controllers\Api\SubscriptionController::class,'welcomeTip'])->middleware('feature_enabled:subscriptions_enabled');
    Route::post('/subscriptions/welcome-tip/ack', [\App\Http\Controllers\Api\SubscriptionController::class,'ackWelcomeTip'])->middleware('feature_enabled:subscriptions_enabled');
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::get('/profile/users/{user}', [ProfileController::class, 'publicShow']);
    Route::get('/profile/host-earnings-report', [ProfileController::class, 'hostEarningsReport']);
    Route::get('/dashboard/leaderboards', [DashboardLeaderboardController::class, 'index'])->withoutMiddleware('throttle:60,1')->middleware('throttle:240,1');
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/avatar', [ProfileController::class, 'avatar']);
    Route::post('/app/activity', function (Request $request, ThemeUnlockService $themes) {
        $result = $themes->recordDailyActivity($request->user());
        return response()->json([
            'ok' => true,
            'data' => [
                'streak_updated' => (bool) ($result['updated'] ?? false),
                'current_login_streak_days' => (int) ($result['current_login_streak_days'] ?? 0),
                'max_login_streak_days' => (int) ($result['max_login_streak_days'] ?? 0),
            ],
        ]);
    });
    Route::get('/themes', [ThemeController::class, 'index']);
    Route::post('/themes/select', [ThemeController::class, 'select']);
    Route::get('/me/following', [HostFollowController::class, 'following']);
    Route::get('/me/followers', [HostFollowController::class, 'followers']);
    Route::get('/hosts/{host}/follow-state', [HostFollowController::class, 'state']);
    Route::get('/hosts/by-user/{user}/follow-state', [HostFollowController::class, 'stateByUser']);
    Route::post('/hosts/{host}/follow', [HostFollowController::class, 'follow']);
    Route::delete('/hosts/{host}/follow', [HostFollowController::class, 'unfollow']);
    Route::get('/me/applications', [ApplicationApiController::class, 'index']);
    Route::post('/agency/apply', [ApplicationApiController::class, 'applyAgency']);
    Route::post('/host/apply', [ApplicationApiController::class, 'applyHost']);
    Route::post('/host/enroll', [ApplicationApiController::class, 'enrollHost']);
    Route::get('/wallet/summary', [WalletApiController::class, 'summary']);
    Route::get('/wallet/transactions', [WalletApiController::class, 'transactions']);
    Route::get('/entry-packs', [EntryPackController::class, 'index'])->middleware('feature_enabled:entry_effects_enabled');
    Route::post('/entry-packs/{entryPack}/purchase', [EntryPackController::class, 'purchase'])->middleware('feature_enabled:entry_effects_enabled');
    Route::get('/me/entry-pack', [EntryPackController::class, 'mine'])->middleware('feature_enabled:entry_effects_enabled');
    Route::post('/me/entry-pack/{entryPack}/activate', [EntryPackController::class, 'activate'])->middleware('feature_enabled:entry_effects_enabled');
    Route::post('/live/rooms/{room_id}/pk/invite', [LiveRoomPkController::class, 'invite'])->middleware(['feature_enabled:pk_battles_enabled', 'live_room_feature_enabled']);
    Route::post('/live/rooms/{room_id}/pk/{battle_id}/accept', [LiveRoomPkController::class, 'accept'])->middleware(['feature_enabled:pk_battles_enabled', 'live_room_feature_enabled']);
    Route::post('/live/rooms/{room_id}/pk/{battle_id}/reject', [LiveRoomPkController::class, 'reject'])->middleware(['feature_enabled:pk_battles_enabled', 'live_room_feature_enabled']);
    Route::post('/live/rooms/{room_id}/pk/{battle_id}/cancel', [LiveRoomPkController::class, 'cancel'])->middleware(['feature_enabled:pk_battles_enabled', 'live_room_feature_enabled']);
    Route::post('/live/rooms/{room_id}/pk/{battle_id}/end', [LiveRoomPkController::class, 'end'])->middleware(['feature_enabled:pk_battles_enabled', 'live_room_feature_enabled']);
    Route::get('/live/rooms/{room_id}/pk/active', [LiveRoomPkController::class, 'active'])->middleware(['feature_enabled:pk_battles_enabled', 'live_room_feature_enabled']);
    Route::get('/live/rooms/{room_id}/pk/history', [LiveRoomPkController::class, 'history'])->middleware(['feature_enabled:pk_battles_enabled', 'live_room_feature_enabled']);
    Route::get('/live/rooms/{room_id}/pk/{battle_id}/media-token', [LiveRoomPkController::class, 'mediaToken'])->middleware(['feature_enabled:pk_battles_enabled', 'live_room_feature_enabled']);
    Route::post('/live/rooms/{live_room:room_id}/reminder', [LiveRoomController::class, 'setReminder'])->middleware('live_room_feature_enabled');
    Route::delete('/live/rooms/{live_room:room_id}/reminder', [LiveRoomController::class, 'clearReminder'])->middleware('live_room_feature_enabled');
    Route::get('/host/blocked-users', [HostModerationController::class, 'blockedUsers']);
    Route::post('/host/block-user', [HostModerationController::class, 'blockUser']);
    Route::post('/host/unblock-user', [HostModerationController::class, 'unblockUser']);
    Route::post('/host/kick-user', [HostModerationController::class, 'kickUser']);
    Route::get('/host/moderation-history', [HostModerationController::class, 'history']);
    Route::get('/host/unblock-requests', [HostModerationController::class, 'unblockRequests']);
    Route::post('/host/unblock-requests/{id}/approve', [HostModerationController::class, 'approveUnblockRequest']);
    Route::post('/host/unblock-requests/{id}/reject', [HostModerationController::class, 'rejectUnblockRequest']);
    Route::post('/reports', [UserReportController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/reports/my', [UserReportController::class, 'mine']);
    Route::post('/unblock-requests', [UnblockRequestController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/unblock-requests/my', [UnblockRequestController::class, 'mine']);
    Route::get('/recharge/orders', [RechargeOrderController::class, 'index'])->middleware('feature_enabled:wallet_recharge_enabled');
    Route::post('/recharge/orders', [RechargeOrderController::class, 'store'])->middleware('feature_enabled:wallet_recharge_enabled');
    Route::post('/recharge/orders/{orderId}/verify', [RechargeOrderController::class, 'verify'])->middleware('feature_enabled:wallet_recharge_enabled');

    Route::post('/calls/request', [CallController::class, 'request'])->middleware('feature_enabled:host_calling_enabled');
    Route::post('/calls/{call}/accept', [CallController::class, 'accept'])->middleware('feature_enabled:host_calling_enabled');
    Route::post('/calls/{call}/reject', [CallController::class, 'reject'])->middleware('feature_enabled:host_calling_enabled');
    Route::post('/calls/{call}/end', [CallController::class, 'end'])->middleware('feature_enabled:host_calling_enabled');
    Route::get('/calls/history', [CallController::class, 'history'])->middleware('feature_enabled:host_calling_enabled');
    Route::get('/calls/{call}/token', [CallController::class, 'token'])->middleware('feature_enabled:host_calling_enabled');

    Route::get('/admin/calls', [CallReportApiController::class, 'adminCalls']);
    Route::get('/admin/calls/summary', [CallReportApiController::class, 'adminSummary']);
    Route::get('/admin/calls/export', [CallReportApiController::class, 'adminExport']);
    Route::get('/agency/calls', [CallReportApiController::class, 'agencyCalls']);
    Route::get('/agency/calls/summary', [CallReportApiController::class, 'agencySummary']);
    Route::get('/agency/calls/export', [CallReportApiController::class, 'agencyExport']);
    Route::get('/host/calls', [CallReportApiController::class, 'hostCalls']);
    Route::get('/host/calls/summary', [CallReportApiController::class, 'hostSummary']);
    Route::get('/admin/blocked-users', [AdminModerationController::class, 'blockedUsers']);
    Route::post('/admin/unblock-user', [AdminModerationController::class, 'unblockUser']);
    Route::get('/admin/reports', [AdminModerationController::class, 'reports']);
    Route::post('/admin/reports/{id}/review', [AdminModerationController::class, 'reviewReport']);
    Route::get('/admin/moderation-history', [AdminModerationController::class, 'history']);
    Route::get('/admin/moderation-rules', [AdminModerationController::class, 'rules']);
    Route::post('/admin/moderation-rules', [AdminModerationController::class, 'storeRule']);
    Route::put('/admin/moderation-rules/{id}', [AdminModerationController::class, 'updateRule']);
    Route::delete('/admin/moderation-rules/{id}', [AdminModerationController::class, 'deleteRule']);
    Route::get('/admin/moderation-analytics', [AdminModerationController::class, 'analytics']);

});

Route::middleware(['auth:sanctum', 'throttle:240,1', 'feature_enabled:host_calling_enabled'])->group(function () {
    Route::get('/live-users', [LiveUsersController::class, 'index']);
    Route::post('/host/status/toggle', [LiveUsersController::class, 'toggleHostStatus']);
    Route::get('/host/status', [LiveUsersController::class, 'hostStatus']);
    Route::post('/ws/presence', [LiveUsersController::class, 'socketStatus']);
});

Route::middleware('throttle:120,1')->group(function () {
    Route::post('/live/rooms/{room_id}/join',  [LiveRoomIngestController::class,'join'])->middleware('live_room_feature_enabled');
    Route::post('/live/rooms/{room_id}/leave', [LiveRoomIngestController::class,'leave'])->middleware('live_room_feature_enabled');
});

Route::middleware('throttle:240,1')->group(function () {
    Route::post('/banners/{banner}/impression', [BannerTrackingController::class, 'impression']);
    Route::post('/banners/{banner}/click', [BannerTrackingController::class, 'click']);
});


Route::post('/auth/firebase/login', [FirebaseAuthApiController::class, 'login']);
Route::middleware('auth:sanctum')->post('/auth/logout', [FirebaseAuthApiController::class, 'logout']);

// Example of an authenticated API route:
Route::middleware('auth:sanctum')->get('/me', fn(\Illuminate\Http\Request $r) => $r->user());
Route::middleware('auth:sanctum')->get('/ws/verify', function (\Illuminate\Http\Request $r) {
    $u = $r->user();
    $u->loadMissing('level');
    $roleNames = method_exists($u, 'getRoleNames')
        ? $u->getRoleNames()->map(fn ($role) => strtolower((string) $role))->values()->all()
        : [];
    $activeSub = UserSubscription::query()
        ->with('plan:id,name')
        ->where('user_id', $u->id)
        ->where('status', 'active')
        ->where(function ($query) {
            $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()->copy()->addSeconds(5));
        })
        ->where(function ($query) {
            $query->whereNull('ends_at')->orWhere('ends_at', '>', now()->copy()->subSeconds(5));
        })
        ->latest('ends_at')
        ->latest('id')
        ->first();
    $planName = strtolower((string) ($activeSub?->plan?->name ?? ''));
    $isVip = in_array('vip', $roleNames, true)
        || in_array('premium', $roleNames, true)
        || str_contains($planName, 'vip')
        || str_contains($planName, 'premium')
        || str_contains($planName, 'elite')
        || str_contains($planName, 'platinum')
        || str_contains($planName, 'gold');
    return [
        'id' => $u->id,
        'name' => $u->name,
        'blocked' => (bool) $u->is_blocked,
        'avatar_url' => $u->avatar_url,
        'level' => $u->level?->level !== null ? (int) $u->level->level : null,
        'is_vip' => $isVip,
        'active_theme_key' => app(ThemeUnlockService::class)->activeThemeKeyFor(
            $u,
            true,
            (($code = (int) $r->header('X-App-Version-Code', 0)) > 0 ? $code : null),
        ),
        'roles' => method_exists($u, 'getRoleNames') ? $u->getRoleNames()->values()->all() : [],
    ];
});
Route::middleware('auth:sanctum')->post('/ws/rooms/join-check', [WsModerationController::class, 'joinCheck']);
Route::middleware('auth:sanctum')->post('/ws/rooms/chat-check', [WsModerationController::class, 'chatCheck']);
Route::middleware('throttle:240,1')->get('/ws/moderation/snapshot', [WsModerationController::class, 'snapshot']);
Route::middleware('throttle:240,1')->post('/ws/moderation/persist-chat-action', [WsModerationController::class, 'persistChatAction']);
