<?php

use App\Http\Controllers\Admin\AdminUserNotificationController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\FirebaseAuthController;
use App\Http\Controllers\AgencyRequestController;
use App\Http\Controllers\HostRequestController;
use App\Http\Controllers\HostEnrollRequestController;
use App\Http\Controllers\Admin\AgencyRequestController as AdminAgencyRequestController;
use App\Http\Controllers\Admin\HostRequestController as AdminHostRequestController;
use App\Http\Controllers\Admin\HostEnrollRequestController as AdminHostEnrollRequestController;
use App\Http\Controllers\Me\ApplicationsController;
use App\Http\Controllers\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Admin\WalletAdminController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UserAdminController;
use App\Http\Controllers\Admin\HostAdminController;
use App\Http\Controllers\Admin\AgencyAdminController;
use App\Http\Controllers\Admin\GiftAdminController;
use App\Http\Controllers\Admin\LiveRoomAdminController;
use App\Http\Controllers\Admin\PresenceController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Admin\LeaderboardReportController as AdminLeaderboardReportController;
use App\Http\Controllers\Admin\SubscriptionPlanController;
use App\Http\Controllers\Admin\UserSubscriptionController;
use App\Http\Controllers\Admin\RechargePlanAdminController;
use App\Http\Controllers\Admin\BannerAdminController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\ThemeAdminController;
use App\Http\Controllers\Admin\CallReportController as AdminCallReportController;
use App\Http\Controllers\Admin\HostFollowerReportController as AdminHostFollowerReportController;
use App\Http\Controllers\Admin\UserLevelAdminController;
use App\Http\Controllers\Admin\EntryPackAdminController;
use App\Http\Controllers\Admin\ProfileFrameAdminController;
use App\Http\Controllers\Admin\LiveRoomPkBattleAdminController;
use App\Http\Controllers\Admin\ModerationController as AdminModerationController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\Admin\AgencyReportController as AdminAgencyReportController;
use App\Http\Controllers\Agency\CallReportController as AgencyCallReportController;
use App\Http\Controllers\Agency\HostController as AgencyHostController;
use App\Http\Controllers\Agency\LiveRoomController as AgencyLiveRoomController;
use App\Http\Controllers\Agency\PkBattleController as AgencyPkBattleController;
use App\Http\Controllers\Host\CallReportController as HostCallReportController;
use App\Http\Controllers\Admin\AgencyPayoutReportController as AdminAgencyPayoutReportController;
use App\Http\Controllers\Agency\PayoutReportController as AgencyPayoutReportController;
use App\Http\Controllers\Agency\ProfileController as AgencyProfileController;



Route::view('/', 'welcome')->name('home');
Route::view('/privacy-policy', 'privacy-policy')->name('privacy-policy');
Route::view('/terms-of-service', 'terms-of-service')->name('terms-of-service');
Route::get('/media/avatar/{path}', [MediaController::class, 'avatar'])
    ->where('path', '.*')
    ->name('media.avatar');
Route::get('/media/gift/{path}', [MediaController::class, 'gift'])
    ->where('path', '.*')
    ->name('media.gift');
Route::get('/media/entry-pack/{path}', [MediaController::class, 'entryPack'])
    ->where('path', '.*')
    ->name('media.entry-pack');
Route::get('/media/profile-frame/{path}', [MediaController::class, 'profileFrame'])
    ->where('path', '.*')
    ->name('media.profile-frame');
Route::post('/auth/firebase/login', [FirebaseAuthController::class, 'login'])->name('auth.firebase.login');
Route::post('/logout', [FirebaseAuthController::class, 'logout'])->middleware('auth')->name('logout');




Route::middleware('auth','not_blocked')->group(function () {
  Route::get('/agency/apply', [AgencyRequestController::class, 'create'])->name('agency.apply');
  Route::post('/agency/apply', [AgencyRequestController::class, 'store'])->name('agency.apply.store');

  Route::get('/host/apply', [HostRequestController::class, 'create'])->name('host.apply');
  Route::post('/host/apply', [HostRequestController::class, 'store'])->name('host.apply.store');
  Route::get('/me/applications', [ApplicationsController::class,'index'])->name('me.applications');

});

// Host area (host role required)
Route::middleware(['auth','not_blocked','role:host'])->prefix('host')->name('host.')->group(function () {
  Route::view('/', 'host.dashboard')->name('dashboard');
  Route::get('/enroll', [HostEnrollRequestController::class, 'create'])->name('enroll.create');
  Route::post('/enroll', [HostEnrollRequestController::class, 'store'])->name('enroll.store');
  Route::get('/calls', [HostCallReportController::class, 'index'])->name('calls.index');
});
Route::middleware(['auth','not_blocked','role:agency'])->prefix('agency')->name('agency.')->group(function () {
  Route::get('/', [\App\Http\Controllers\Agency\DashboardController::class, 'index'])->name('dashboard');
  Route::get('/hosts', [AgencyHostController::class, 'index'])->name('hosts.index');
  Route::get('/hosts/{host}', [AgencyHostController::class, 'show'])->name('hosts.show');
  Route::get('/calls', [AgencyCallReportController::class, 'index'])->name('calls.index');
  Route::get('/calls/export', [AgencyCallReportController::class, 'export'])->name('calls.export');
  Route::get('/video-rooms', [AgencyLiveRoomController::class, 'index'])->defaults('roomType', 'video')->name('video-rooms.index');
  Route::get('/video-rooms/{live_room}', [AgencyLiveRoomController::class, 'show'])->defaults('roomType', 'video')->name('video-rooms.show');
  Route::get('/audio-rooms', [AgencyLiveRoomController::class, 'index'])->defaults('roomType', 'audio')->name('audio-rooms.index');
  Route::get('/audio-rooms/{live_room}', [AgencyLiveRoomController::class, 'show'])->defaults('roomType', 'audio')->name('audio-rooms.show');
  Route::get('/pk-battles', [AgencyPkBattleController::class, 'index'])->name('pk-battles.index');
  Route::get('/pk-battles/{pk_battle}', [AgencyPkBattleController::class, 'show'])->name('pk-battles.show');
  Route::get('/payout-reports', [AgencyPayoutReportController::class, 'index'])->name('payout-reports.index');
  Route::get('/payout-reports/{agency_payout_report}', [AgencyPayoutReportController::class, 'show'])->name('payout-reports.show');
  Route::get('/payout-reports/{agency_payout_report}/export', [AgencyPayoutReportController::class, 'export'])->name('payout-reports.export');
  Route::get('/profile', [AgencyProfileController::class, 'show'])->name('profile.show');
});

Route::middleware(['auth','not_blocked','role:admin'])->prefix('admin')->name('admin.')->group(function () {
  Route::get('/', DashboardController::class)->name('dashboard');
  Route::get('/dashboard', DashboardController::class);
   Route::get('presence', [PresenceController::class, 'index'])->name('presence.index');
    Route::get('presence/stats', [PresenceController::class, 'stats'])->name('presence.stats');
  // Users
        Route::get('users', [UserAdminController::class, 'index'])->name('users.index');
        Route::get('users/{user}', [UserAdminController::class, 'show'])->name('users.show');
        Route::post('users/{user}/block', [UserAdminController::class, 'block'])->name('users.block');
        Route::post('users/{user}/unblock', [UserAdminController::class, 'unblock'])->name('users.unblock');
        Route::post('/users/{user}/device-block',   [UserAdminController::class, 'deviceBlock'])->name('users.device.block');
        Route::post('/users/{user}/device-unblock', [UserAdminController::class, 'deviceUnblock'])->name('users.device.unblock');
        Route::post('/users/{user}/subscriptions', [UserAdminController::class, 'grantSubscription'])->name('users.subscriptions.store');
        Route::post('/users/{user}/subscriptions/{user_subscription}/cancel', [UserAdminController::class, 'cancelSubscription'])->name('users.subscriptions.cancel');
        Route::post('/users/{user}/entry-packs', [UserAdminController::class, 'assignEntryPack'])->name('users.entry-packs.store');
        Route::post('/users/{user}/profile-frames', [UserAdminController::class, 'assignProfileFrame'])->name('users.profile-frames.store');
        Route::delete('/users/{user}/profile-frames/{userProfileFrame}', [UserAdminController::class, 'revokeProfileFrame'])->name('users.profile-frames.destroy');
        Route::post('/users/{user}/level', [UserAdminController::class, 'setLevel'])->name('users.level.set');
        Route::get('/notifications', [AdminUserNotificationController::class,'index'])->name('notifications.index');   // Recent list
        Route::get('/notifications/compose', [AdminUserNotificationController::class,'compose'])->name('notifications.compose');
        Route::post('/notifications/send', [AdminUserNotificationController::class,'send'])->name('notifications.send');

  // Per-user view
        Route::get('/users/{user}/notifications', [AdminUserNotificationController::class,'userNotifications'])->name('users.notifications');
        // Hosts

    Route::get('/hosts',               [\App\Http\Controllers\Admin\HostAdminController::class, 'index'])->name('hosts.index');
    Route::get('/hosts/{host}/edit',   [\App\Http\Controllers\Admin\HostAdminController::class, 'edit'])->name('hosts.edit');
    Route::put('/hosts/{host}',        [\App\Http\Controllers\Admin\HostAdminController::class, 'update'])->name('hosts.update');
    Route::post('/hosts/{host}/block', [\App\Http\Controllers\Admin\HostAdminController::class, 'block'])->name('hosts.block');
    Route::post('/hosts/{host}/unblock', [\App\Http\Controllers\Admin\HostAdminController::class, 'unblock'])->name('hosts.unblock');

    // Agencies
    Route::get('/agencies',                 [\App\Http\Controllers\Admin\AgencyAdminController::class, 'index'])->name('agencies.index');
    Route::get('/agencies/{agency}/dashboard', [\App\Http\Controllers\Admin\AgencyAdminController::class, 'dashboard'])->name('agencies.dashboard');
    Route::get('/agencies/{agency}/hosts', [\App\Http\Controllers\Admin\AgencyAdminController::class, 'hosts'])->name('agencies.hosts.index');
    Route::get('/agencies/{agency}/hosts/{host}', [\App\Http\Controllers\Admin\AgencyAdminController::class, 'hostShow'])->name('agencies.hosts.show');
    Route::get('/agencies/{agency}/calls', [\App\Http\Controllers\Admin\AgencyAdminController::class, 'calls'])->name('agencies.calls.index');
    Route::get('/agencies/{agency}/calls/export', [\App\Http\Controllers\Admin\AgencyAdminController::class, 'exportCalls'])->name('agencies.calls.export');
    Route::get('/agencies/{agency}/video-rooms', [\App\Http\Controllers\Admin\AgencyAdminController::class, 'videoRooms'])->name('agencies.video-rooms.index');
    Route::get('/agencies/{agency}/video-rooms/{live_room}', [\App\Http\Controllers\Admin\AgencyAdminController::class, 'videoRoomShow'])->name('agencies.video-rooms.show');
    Route::get('/agencies/{agency}/audio-rooms', [\App\Http\Controllers\Admin\AgencyAdminController::class, 'audioRooms'])->name('agencies.audio-rooms.index');
    Route::get('/agencies/{agency}/audio-rooms/{live_room}', [\App\Http\Controllers\Admin\AgencyAdminController::class, 'audioRoomShow'])->name('agencies.audio-rooms.show');
    Route::get('/agencies/{agency}/pk-battles', [\App\Http\Controllers\Admin\AgencyAdminController::class, 'pkBattles'])->name('agencies.pk-battles.index');
    Route::get('/agencies/{agency}/pk-battles/{pk_battle}', [\App\Http\Controllers\Admin\AgencyAdminController::class, 'pkBattleShow'])->name('agencies.pk-battles.show');
    Route::get('/agencies/{agency}/profile', [\App\Http\Controllers\Admin\AgencyAdminController::class, 'profile'])->name('agencies.profile.show');
    Route::get('/agencies/{agency}/edit',   [\App\Http\Controllers\Admin\AgencyAdminController::class, 'edit'])->name('agencies.edit');
    Route::put('/agencies/{agency}',        [\App\Http\Controllers\Admin\AgencyAdminController::class, 'update'])->name('agencies.update');
    Route::post('/agencies/{agency}/block', [\App\Http\Controllers\Admin\AgencyAdminController::class, 'block'])->name('agencies.block');
    Route::post('/agencies/{agency}/unblock', [\App\Http\Controllers\Admin\AgencyAdminController::class, 'unblock'])->name('agencies.unblock');




  Route::resource('agency-requests', AdminAgencyRequestController::class)->only(['index','show','update']);
  Route::resource('host-requests',   AdminHostRequestController::class)->only(['index','show','update']);
  Route::resource('enroll-requests', AdminHostEnrollRequestController::class)->only(['index','show','update']);
  Route::post('notifications/read-all', [AdminNotificationController::class, 'readAll'])->name('notifications.read-all');
  Route::post('notifications/{id}/read', [AdminNotificationController::class, 'readOne'])->name('notifications.read-one');
    
  Route::get('wallets', [WalletAdminController::class,'index'])->name('wallets.index');
  Route::get('wallets/{user}', [WalletAdminController::class,'show'])->name('wallets.show');
  Route::post('wallets/{user}/purchase', [WalletAdminController::class,'purchase'])->name('wallets.purchase');
  Route::post('wallets/{user}/spend',    [WalletAdminController::class,'spend'])->name('wallets.spend'); // simulate spend
  Route::post('wallets/{user}/credit', [WalletAdminController::class,'credit'])->name('wallets.credit');
  Route::post('wallets/{user}/debit', [WalletAdminController::class,'debit'])->name('wallets.debit');

  Route::get('reports/hosts', [ReportsController::class,'hosts'])->name('reports.hosts');
  Route::get('reports/hosts/{host}', [ReportsController::class,'hostShow'])->name('reports.hosts.show');
  Route::get('reports/hosts.csv', [ReportsController::class,'hostsCsv'])->name('reports.hosts.csv');
  Route::get('reports/levels', [ReportsController::class,'levels'])->name('reports.levels');
  Route::get('reports/leaderboards', [AdminLeaderboardReportController::class, 'index'])->name('reports.leaderboards');
  Route::get('reports/leaderboards/export', [AdminLeaderboardReportController::class, 'export'])->name('reports.leaderboards.export');
  Route::resource('levels', UserLevelAdminController::class)->except(['show']);
  Route::post('profile-frames/awards/run', [ProfileFrameAdminController::class, 'runAwards'])->name('profile-frames.awards.run');
  Route::resource('profile-frames', ProfileFrameAdminController::class)->except(['show']);
  Route::get('reports/agencies', [AdminAgencyReportController::class, 'index'])->name('reports.agencies');
  Route::get('reports/agencies/{agency}', [AdminAgencyReportController::class, 'show'])->name('reports.agencies.show');
  Route::get('agency-payout-reports', [AdminAgencyPayoutReportController::class, 'index'])->name('agency-payout-reports.index');
  Route::post('agency-payout-reports/generate', [AdminAgencyPayoutReportController::class, 'generate'])->name('agency-payout-reports.generate');
  Route::get('agency-payout-reports/{agency_payout_report}', [AdminAgencyPayoutReportController::class, 'show'])->name('agency-payout-reports.show');
  Route::post('agency-payout-reports/{agency_payout_report}/review', [AdminAgencyPayoutReportController::class, 'review'])->name('agency-payout-reports.review');
  Route::post('agency-payout-reports/{agency_payout_report}/approve', [AdminAgencyPayoutReportController::class, 'approve'])->name('agency-payout-reports.approve');
  Route::post('agency-payout-reports/{agency_payout_report}/reject', [AdminAgencyPayoutReportController::class, 'reject'])->name('agency-payout-reports.reject');
  Route::post('agency-payout-reports/{agency_payout_report}/mark-paid', [AdminAgencyPayoutReportController::class, 'markPaid'])->name('agency-payout-reports.mark-paid');
  Route::get('agency-payout-reports/{agency_payout_report}/export', [AdminAgencyPayoutReportController::class, 'export'])->name('agency-payout-reports.export');
  Route::get('reports/host-followers', [AdminHostFollowerReportController::class, 'index'])->name('reports.host-followers');
  Route::delete('reports/host-followers/{hostFollower}', [AdminHostFollowerReportController::class, 'destroy'])->name('reports.host-followers.destroy');
  Route::get('reports/follow-notifications', [AdminHostFollowerReportController::class, 'notifications'])->name('reports.follow-notifications');
  Route::get('calls', [AdminCallReportController::class, 'index'])->name('calls.index');
  Route::get('calls/export', [AdminCallReportController::class, 'export'])->name('calls.export');
  Route::get('settings/calls', [AdminSettingsController::class, 'editCalls'])->name('settings.calls.edit');
  Route::put('settings/calls', [AdminSettingsController::class, 'updateCalls'])->name('settings.calls.update');
  Route::get('settings/app', [AdminSettingsController::class, 'editApp'])->name('settings.app.edit');
  Route::put('settings/app', [AdminSettingsController::class, 'updateApp'])->name('settings.app.update');
  Route::get('settings/live-rooms', [AdminSettingsController::class, 'editLiveRooms'])->name('settings.live-rooms.edit');
  Route::put('settings/live-rooms', [AdminSettingsController::class, 'updateLiveRooms'])->name('settings.live-rooms.update');
  Route::get('moderation/blocked-users', [AdminModerationController::class, 'blockedUsers'])->name('moderation.blocked-users');
  Route::post('moderation/blocked-users/unblock', [AdminModerationController::class, 'adminUnblock'])->name('moderation.blocked-users.unblock');
  Route::get('moderation/reports', [AdminModerationController::class, 'reports'])->name('moderation.reports');
  Route::post('moderation/reports/{report}/review', [AdminModerationController::class, 'reviewReport'])->name('moderation.reports.review');
  Route::get('moderation/history', [AdminModerationController::class, 'history'])->name('moderation.history');
  Route::get('moderation/rules', [AdminModerationController::class, 'rules'])->name('moderation.rules');
  Route::post('moderation/rules', [AdminModerationController::class, 'storeRule'])->name('moderation.rules.store');
  Route::put('moderation/rules/{moderationRule}', [AdminModerationController::class, 'updateRule'])->name('moderation.rules.update');
  Route::delete('moderation/rules/{moderationRule}', [AdminModerationController::class, 'destroyRule'])->name('moderation.rules.destroy');
  Route::get('moderation/analytics', [AdminModerationController::class, 'analytics'])->name('moderation.analytics');
  Route::get('themes', [ThemeAdminController::class, 'index'])->name('themes.index');
  Route::get('themes/create', [ThemeAdminController::class, 'create'])->name('themes.create');
  Route::post('themes', [ThemeAdminController::class, 'store'])->name('themes.store');
  Route::get('themes/{theme}', [ThemeAdminController::class, 'show'])->name('themes.show');
  Route::get('themes/{theme}/edit', [ThemeAdminController::class, 'edit'])->name('themes.edit');
  Route::put('themes/{theme}', [ThemeAdminController::class, 'update'])->name('themes.update');
  Route::post('themes/{theme}/grant', [ThemeAdminController::class, 'grant'])->name('themes.grant');
  Route::delete('themes/{theme}/users/{user}', [ThemeAdminController::class, 'revoke'])->name('themes.revoke');
   
   
  Route::resource('gifts', GiftAdminController::class)->except(['show']);
  Route::get('pk-battles/export', [LiveRoomPkBattleAdminController::class, 'export'])->name('pk-battles.export');
  Route::resource('pk-battles', LiveRoomPkBattleAdminController::class)->only(['index', 'show']);
  Route::get('entry-packs/reports', [EntryPackAdminController::class, 'reports'])->name('entry-packs.reports');
  Route::get('entry-packs/purchases/{userEntryPack}/edit', [EntryPackAdminController::class, 'editPurchase'])->name('entry-packs.purchases.edit');
  Route::put('entry-packs/purchases/{userEntryPack}', [EntryPackAdminController::class, 'updatePurchase'])->name('entry-packs.purchases.update');
  Route::resource('entry-packs', EntryPackAdminController::class)->except(['show']);
  Route::resource('banners', BannerAdminController::class)->except(['show']);
  Route::resource('live-rooms', LiveRoomAdminController::class)->except(['destroy']);
  Route::post('live-rooms/{live_room}/end', [LiveRoomAdminController::class,'endRoom'])->name('live-rooms.end');
  Route::post('live-rooms/{live_room}/seat-requests/{seat_request}/reject', [LiveRoomAdminController::class, 'rejectSeatRequest'])->name('live-rooms.seat-requests.reject');
  Route::post('live-rooms/{live_room}/speakers/{user}/remove', [LiveRoomAdminController::class, 'removeSpeaker'])->name('live-rooms.speakers.remove');
  Route::get('live-rooms/{live_room}/requests.csv', [LiveRoomAdminController::class, 'exportRequests'])->name('live-rooms.requests.export');

  Route::resource('subscription-plans', SubscriptionPlanController::class)->except(['show']);
  Route::resource('recharge-plans', RechargePlanAdminController::class)->except(['show']);
  Route::resource('user-subscriptions', UserSubscriptionController::class)->except(['show']);
  Route::post('user-subscriptions/{id}/cancel', [UserSubscriptionController::class,'cancel'])->name('user-subscriptions.cancel');
  
  });




    
