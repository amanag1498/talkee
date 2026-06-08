<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActionAudit;
use App\Models\CallSession;
use App\Models\EntryPack;
use App\Models\HostFollower;
use App\Models\LiveRoom;
use App\Models\LiveRoomGift;
use App\Models\LiveRoomParticipant;
use App\Models\LiveRoomPkBattle;
use App\Models\PaymentOrder;
use App\Models\ProfileFrame;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserEntryPack;
use App\Models\UserLevel;
use App\Models\UserLevelHistory;
use App\Models\UserProfileFrame;
use App\Models\UserSubscription;
use App\Models\WalletTransaction;
use App\Services\AdminAuditService;
use App\Services\GameAccessService;
use App\Services\ProfileFrameService;
use App\Services\UserLevelService;
use App\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\DeviceBlock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class UserAdminController extends Controller
{
    public function __construct(
        private UserLevelService $levels,
        private ProfileFrameService $profileFrames,
        private AdminAuditService $audits,
        private GameAccessService $gameAccess,
    ) {
    }

    public function index(Request $request)
    {
        $q = User::query()
            ->with(['roles', 'host.agency', 'wallet', 'level']);

        if ($s = trim((string) $request->get('s'))) {
            if (ctype_digit($s)) {
                $q->where('id', (int) $s);
            } else {
                $q->where(function ($qq) use ($s) {
                    $qq->where('name', 'like', "%{$s}%")
                       ->orWhere('email', 'like', "%{$s}%");
                });
            }
        }

        $users = $q->latest()->paginate(20);

        // Prefetch device-blocks to avoid N+1
        $deviceIds = $users->pluck('device_id')->filter()->unique()->values();
        $blockedDevices = DeviceBlock::whereIn('device_id', $deviceIds)->get()->keyBy('device_id');

        return view('admin.users.index', compact('users', 'blockedDevices'));
    }

    public function show(User $user)
    {
        $user->load([
            'roles',
            'wallet',
            'level',
            'host.agency',
            'levelHistories.oldLevel',
            'levelHistories.newLevel',
            'entryPacks.entryPack',
            'hostFollows.host',
            'profileFrameOwnerships.profileFrame',
            'gameAccesses',
        ]);

        $walletTransactions = WalletTransaction::query()
            ->whereHas('wallet', fn ($query) => $query->where('user_id', $user->id))
            ->latest('id')
            ->limit(12)
            ->get();

        $activeSubscription = UserSubscription::query()
            ->with('plan')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get()
            ->first(fn (UserSubscription $subscription) => $subscription->is_active_now);

        $subscriptions = UserSubscription::query()
            ->with('plan')
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(12)
            ->get();

        $activeEntryPack = $user->entryPacks
            ->filter(fn (UserEntryPack $pack) => $pack->is_currently_usable)
            ->sortByDesc(fn (UserEntryPack $pack) => (int) ($pack->entryPack?->priority ?? 0))
            ->first();

        $entryHistory = $user->entryPacks
            ->sortByDesc('id')
            ->take(12)
            ->values();

        $recentLiveParticipations = LiveRoomParticipant::query()
            ->with(['user', 'room.host.user'])
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(10)
            ->get();

        $recentHostedRooms = $user->host
            ? LiveRoom::query()
                ->with('host.user')
                ->where('host_id', $user->host->id)
                ->latest('id')
                ->limit(10)
                ->get()
            : collect();

        $recentCalls = CallSession::query()
            ->with(['caller', 'receiver', 'host.user', 'agency'])
            ->where(fn ($query) => $query->where('caller_id', $user->id)->orWhere('receiver_id', $user->id))
            ->latest('id')
            ->limit(10)
            ->get();

        $recentGifts = LiveRoomGift::query()
            ->with(['room.host.user', 'gift'])
            ->where('sender_user_id', $user->id)
            ->latest('id')
            ->limit(10)
            ->get();

        $pkBattles = $user->host
            ? LiveRoomPkBattle::query()
                ->with(['roomA.host.user', 'roomB.host.user', 'winnerRoom'])
                ->where(fn ($query) => $query->where('host_a_id', $user->host->id)->orWhere('host_b_id', $user->host->id))
                ->latest('id')
                ->limit(10)
                ->get()
            : collect();

        $followersCount = $user->host ? HostFollower::query()->where('host_id', $user->host->id)->count() : 0;
        $followingCount = HostFollower::query()->where('user_id', $user->id)->count();

        $walletSummary = [
            'balance' => (int) ($user->wallet?->balance ?? 0),
            'credits' => (int) $walletTransactions->where('type', 'credit')->sum('coins'),
            'debits' => (int) $walletTransactions->where('type', 'debit')->sum('coins'),
            'recharges' => PaymentOrder::query()->where('user_id', $user->id)->where('status', 'success')->count(),
        ];

        $levelProgress = $this->levels->profileProgress($user);

        $availablePlans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('price_coins')
            ->get();
        $availableEntryPacks = EntryPack::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderByDesc('priority')
            ->get();
        $availableLevels = UserLevel::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('level')
            ->get();
        $availableProfileFrames = ProfileFrame::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $profileFrameHistory = $user->profileFrameOwnerships
            ->sortByDesc('id')
            ->take(20)
            ->values();
        $equippedProfileFrame = $profileFrameHistory
            ->first(fn (UserProfileFrame $ownership) => $ownership->is_equipped && $ownership->profileFrame?->is_active);

        $auditTrail = AdminActionAudit::query()
            ->with(['admin', 'targetUser'])
            ->where('target_user_id', $user->id)
            ->latest('id')
            ->limit(30)
            ->get();

        $overviewStats = [
            'live_rooms_joined' => LiveRoomParticipant::query()->where('user_id', $user->id)->distinct('live_room_id')->count('live_room_id'),
            'hosted_rooms' => $user->host ? LiveRoom::query()->where('host_id', $user->host->id)->count() : 0,
            'calls_total' => CallSession::query()->where(fn ($query) => $query->where('caller_id', $user->id)->orWhere('receiver_id', $user->id))->count(),
            'gifts_sent' => (int) LiveRoomGift::query()->where('sender_user_id', $user->id)->sum('total_coins'),
            'pk_participation' => $user->host ? LiveRoomPkBattle::query()->where(fn ($query) => $query->where('host_a_id', $user->host->id)->orWhere('host_b_id', $user->host->id))->count() : 0,
        ];
        $gameAccessMap = $this->gameAccess->userAccessMap($user);

        return view('admin.users.show', compact(
            'user',
            'walletSummary',
            'walletTransactions',
            'activeSubscription',
            'subscriptions',
            'activeEntryPack',
            'entryHistory',
            'levelProgress',
            'recentLiveParticipations',
            'recentHostedRooms',
            'recentCalls',
            'recentGifts',
            'pkBattles',
            'followersCount',
            'followingCount',
            'availablePlans',
            'availableEntryPacks',
            'availableLevels',
            'availableProfileFrames',
            'profileFrameHistory',
            'equippedProfileFrame',
            'auditTrail',
            'overviewStats',
            'gameAccessMap',
        ));
    }

    public function block(User $user)
    {
        $before = $user->only(['id', 'is_blocked']);
        $user->update(['is_blocked'=>true]);
        if (method_exists($user, 'tokens')) {
        $user->tokens()->delete();
    }

    // Notify presence WS (Redis pubsub)
    try {
        Redis::publish('users:block', json_encode(['user_id' => $user->id]));
    } catch (\Throwable $e) {
    }
        $this->audits->log(
            area: 'users',
            action: 'user_blocked',
            admin: request()->user(),
            targetUser: $user,
            entity: $user,
            before: $before,
            after: $user->fresh()->only(['id', 'is_blocked']),
            reason: request('reason'),
        );
        return back()->with('ok', 'User blocked.');
    }

    public function unblock(User $user)
    {
        $before = $user->only(['id', 'is_blocked']);
        $user->update(['is_blocked' => false]);
        $this->audits->log(
            area: 'users',
            action: 'user_unblocked',
            admin: request()->user(),
            targetUser: $user,
            entity: $user,
            before: $before,
            after: $user->fresh()->only(['id', 'is_blocked']),
            reason: request('reason'),
        );
        return back()->with('ok', 'User unblocked.');
    }

    public function deviceBlock(Request $request, User $user)
{
    $deviceId = $user->device_id;
    if (!$deviceId) {
        return back()->with('error', 'User has no device_id on file.');
    }

    // 1) Upsert device-level block (permanent unless expires_at provided)
    $existingBlock = DeviceBlock::where('device_id', $deviceId)->first();
    DeviceBlock::updateOrCreate(
        ['device_id' => $deviceId],
        [
            'reason'     => $request->input('reason'),
            'expires_at' => $request->filled('expires_at') ? now()->parse($request->input('expires_at')) : null,
            'created_by' => $request->user()->id ?? null,
        ]
    );

    // 2) Find all users with this device_id
    $ids = \App\Models\User::where('device_id', $deviceId)->pluck('id')->all();

    if (!empty($ids)) {
        // 3) Bulk mark blocked
        \App\Models\User::whereIn('id', $ids)->update(['is_blocked' => true]);

        // 4) Revoke tokens + kick via Redis for each user (chunk to be safe)
        \App\Models\User::whereIn('id', $ids)->chunkById(100, function ($users) {
            foreach ($users as $u) {
                try { $u->tokens()->delete(); } catch (\Throwable $e) {}
                try { \Illuminate\Support\Facades\Redis::publish('users:block', json_encode(['user_id' => $u->id])); } catch (\Throwable $e) {}
            }
        });
    }

    $this->audits->log(
        area: 'users',
        action: 'device_blocked',
        admin: $request->user(),
        targetUser: $user,
        entity: $user,
        before: $existingBlock?->toArray(),
        after: DeviceBlock::where('device_id', $deviceId)->first()?->toArray(),
        reason: $request->input('reason'),
        meta: ['device_id' => $deviceId, 'affected_user_ids' => $ids],
    );

    return back()->with('ok', "Device blocked: $deviceId (affected ".count($ids)." account(s))");
}

public function deviceUnblock(User $user)
{
    $deviceId = $user->device_id;
    if (!$deviceId) {
        return back()->with('error', 'User has no device_id on file.');
    }

    // 1) Remove device-level block
    DeviceBlock::where('device_id', $deviceId)->delete();

    // 2) Unblock ALL users sharing this device_id (symmetry)
    $ids = \App\Models\User::where('device_id', $deviceId)->pluck('id')->all();
    if (!empty($ids)) {
        \App\Models\User::whereIn('id', $ids)->update(['is_blocked' => false]);
    }

    $this->audits->log(
        area: 'users',
        action: 'device_unblocked',
        admin: request()->user(),
        targetUser: $user,
        entity: $user,
        before: ['device_id' => $deviceId, 'affected_user_ids' => $ids],
        after: null,
        meta: ['device_id' => $deviceId, 'affected_user_ids' => $ids],
    );

    return back()->with('ok', "Device unblocked: $deviceId (affected ".count($ids)." account(s))");
}

    public function updateGameAccess(Request $request, User $user)
    {
        $data = $request->validate([
            'teen_patti' => 'nullable|boolean',
            'greedy' => 'nullable|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        $before = $this->gameAccess->userAccessMap($user);
        $after = $this->gameAccess->syncUserAccess($user, [
            GameAccessService::GAME_TEEN_PATTI => (bool) ($data['teen_patti'] ?? false),
            GameAccessService::GAME_GREEDY => (bool) ($data['greedy'] ?? false),
        ], $request->user());

        $this->audits->log(
            area: 'games',
            action: 'user_game_access_updated',
            admin: $request->user(),
            targetUser: $user,
            entity: $user,
            before: $before,
            after: $after,
            reason: $data['reason'] ?? null,
            meta: [
                'managed_games' => array_keys($after),
            ],
        );

        return back()->with('ok', 'Game access updated.');
    }

    public function grantSubscription(Request $request, User $user)
    {
        $data = $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
            'status' => 'required|in:active,cancelled,expired',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'reason' => 'nullable|string|max:500',
        ]);

        $plan = SubscriptionPlan::query()->findOrFail($data['plan_id']);
        $startsAt = !empty($data['starts_at']) ? Carbon::parse($data['starts_at']) : now();
        $endsAt = !empty($data['ends_at']) ? Carbon::parse($data['ends_at']) : $startsAt->copy()->addDays((int) $plan->duration_days);

        $subscription = UserSubscription::query()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => $data['status'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'last_purchased_at' => now(),
            'meta' => [
                'source' => 'admin_grant',
                'charged' => false,
                'plan_name' => $plan->name,
                'reason' => $data['reason'] ?? null,
                'created_via' => 'admin_user_360',
            ],
        ]);

        $this->audits->log(
            area: 'subscriptions',
            action: 'user_subscription_granted',
            admin: $request->user(),
            targetUser: $user,
            entity: $subscription,
            before: null,
            after: $subscription->fresh(['plan'])->toArray(),
            reason: $data['reason'] ?? null,
        );

        return back()->with('ok', 'Subscription granted.');
    }

    public function cancelSubscription(Request $request, User $user, UserSubscription $user_subscription)
    {
        abort_unless((int) $user_subscription->user_id === (int) $user->id, 404);

        $before = $user_subscription->toArray();
        $meta = (array) ($user_subscription->meta ?? []);
        $meta['last_action'] = 'ADMIN_USER_360_CANCEL';
        $meta['cancel_reason'] = $request->input('reason');
        $meta['last_updated_at'] = now()->toIso8601String();

        $user_subscription->update([
            'status' => 'cancelled',
            'ends_at' => now(),
            'meta' => $meta,
        ]);

        $this->audits->log(
            area: 'subscriptions',
            action: 'user_subscription_cancelled',
            admin: $request->user(),
            targetUser: $user,
            entity: $user_subscription,
            before: $before,
            after: $user_subscription->fresh(['plan'])->toArray(),
            reason: $request->input('reason'),
        );

        return back()->with('ok', 'Subscription cancelled.');
    }

    public function assignEntryPack(Request $request, User $user)
    {
        $data = $request->validate([
            'entry_pack_id' => 'required|exists:entry_packs,id',
            'is_active' => 'nullable|boolean',
            'purchased_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:purchased_at',
            'charge_coins' => 'nullable|boolean',
            'source_type' => 'nullable|in:admin_grant,gift,promotional_gift,signup_gift',
            'reason' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($request, $user, $data) {
            $pack = EntryPack::query()->findOrFail($data['entry_pack_id']);
            $activate = $request->boolean('is_active');
            $charge = $request->boolean('charge_coins') && (int) $pack->price_coins > 0;
            $reference = 'ENTRY_PACK_ADMIN:'.$user->id.':'.$pack->id.':'.uniqid();
            $walletTx = null;

            if ($charge && (int) $pack->price_coins > 0) {
                $walletTx = WalletService::spend(
                    user: $user,
                    coins: (int) $pack->price_coins,
                    category: 'other',
                    counterparty: null,
                    reference: $reference,
                    meta: [
                        'event' => 'ENTRY_PACK_ADMIN_ASSIGN_CHARGED',
                        'entry_pack_id' => $pack->id,
                        'entry_pack_name' => $pack->name,
                    ],
                );
            }

            $entry = UserEntryPack::query()->create([
                'user_id' => $user->id,
                'entry_pack_id' => $pack->id,
                'is_active' => $activate,
                'purchased_at' => !empty($data['purchased_at']) ? Carbon::parse($data['purchased_at']) : now(),
                'expires_at' => !empty($data['expires_at']) ? Carbon::parse($data['expires_at']) : now()->addDays((int) ($pack->duration_days ?? 30)),
                'purchase_key' => 'admin-user-360-'.uniqid(),
                'source' => $charge ? 'admin_charged' : ($data['source_type'] ?? 'admin_grant'),
                'charged' => $charge,
                'price_coins' => $charge ? (int) $pack->price_coins : 0,
                'wallet_transaction_id' => $walletTx?->id,
                'granted_by_admin_id' => $request->user()?->id,
                'purchase_reference' => $reference,
                'admin_note' => $data['reason'] ?? null,
            ]);

            if ($activate) {
                UserEntryPack::query()
                    ->where('user_id', $user->id)
                    ->whereKeyNot($entry->id)
                    ->update(['is_active' => false]);
            }

            $this->audits->log(
                area: 'entry_packs',
                action: 'user_entry_pack_assigned',
                admin: $request->user(),
                targetUser: $user,
                entity: $entry,
                before: null,
                after: $entry->fresh('entryPack')->toArray(),
                reason: $data['reason'] ?? null,
            );

            return back()->with('ok', 'Entry pack assigned.');
        });
    }

    public function setLevel(Request $request, User $user)
    {
        $data = $request->validate([
            'level_id' => 'required|exists:user_levels,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $level = UserLevel::query()->findOrFail($data['level_id']);
        $before = $user->only(['id', 'level_id', 'lifetime_spend_coins']);
        $oldLevelId = $user->level_id;

        $user->forceFill(['level_id' => $level->id])->save();

        UserLevelHistory::query()->create([
            'user_id' => $user->id,
            'old_level_id' => $oldLevelId,
            'new_level_id' => $level->id,
            'lifetime_spend_coins' => (int) $user->lifetime_spend_coins,
            'triggered_by_transaction_id' => null,
        ]);

        $this->audits->log(
            area: 'levels',
            action: 'user_level_set',
            admin: $request->user(),
            targetUser: $user,
            entity: $user,
            before: $before,
            after: $user->fresh('level')->toArray(),
            reason: $data['reason'] ?? null,
            meta: ['old_level_id' => $oldLevelId, 'new_level_id' => $level->id],
        );

        return back()->with('ok', 'User level updated.');
    }

    public function assignProfileFrame(Request $request, User $user)
    {
        $data = $request->validate([
            'profile_frame_id' => 'required|exists:profile_frames,id',
            'expires_at' => 'nullable|date|after:now',
            'auto_equip' => 'nullable|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        $frame = ProfileFrame::query()->findOrFail($data['profile_frame_id']);
        $expiresAt = !empty($data['expires_at']) ? Carbon::parse($data['expires_at']) : null;
        $autoEquip = $request->boolean('auto_equip');

        $ownership = $this->profileFrames->grant(
            $user,
            $frame,
            'admin_grant',
            $expiresAt,
            $autoEquip,
        );

        $this->audits->log(
            area: 'profile_frames',
            action: 'user_profile_frame_granted',
            admin: $request->user(),
            targetUser: $user,
            entity: $ownership,
            before: null,
            after: $ownership->fresh(['profileFrame'])->toArray(),
            reason: $data['reason'] ?? null,
            meta: [
                'profile_frame_id' => $frame->id,
                'auto_equip' => $autoEquip,
                'expires_at' => optional($expiresAt)->toIso8601String(),
            ],
        );

        $this->profileFrames->notifyUnlocked(
            $user,
            $ownership,
            'Profile frame granted',
            $autoEquip
                ? $frame->name.' was granted by admin and equipped on your profile.'
                : $frame->name.' was granted by admin and added to your profile frame inventory.',
        );

        return back()->with('ok', 'Profile frame assigned.');
    }

    public function revokeProfileFrame(Request $request, User $user, UserProfileFrame $userProfileFrame)
    {
        abort_unless((int) $userProfileFrame->user_id === (int) $user->id, 404);

        $before = $userProfileFrame->fresh(['profileFrame'])->toArray();
        $frameName = $userProfileFrame->profileFrame?->name ?? 'Profile frame';
        $userProfileFrame->delete();

        $this->audits->log(
            area: 'profile_frames',
            action: 'user_profile_frame_revoked',
            admin: $request->user(),
            targetUser: $user,
            entity: $user,
            before: $before,
            after: null,
            reason: $request->input('reason'),
            meta: [
                'profile_frame_id' => data_get($before, 'profile_frame.id') ?: data_get($before, 'profile_frame_id'),
            ],
        );

        return back()->with('ok', $frameName.' revoked.');
    }
}
