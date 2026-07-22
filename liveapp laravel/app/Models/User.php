<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use App\Models\HostAvailability;
use App\Models\LiveRoomSeatRequest;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    // Ensure Spatie uses the same guard as your roles table
    protected $guard_name = 'web';

    protected $fillable = [
        'name',
        'email',
        'password',
        'firebase_uid',
        'avatar_url',
        'level_id',
        'legacy_lifetime_spend_coins',
        'lifetime_spend_coins',
        'provider',
        'email_verified_at',
        'is_blocked',
        'device_id',
        'last_login_at',
        'last_login_date',
        'current_login_streak_days',
        'max_login_streak_days',
        'referral_code',
        'referred_by_user_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_blocked'        => 'boolean',
        'legacy_lifetime_spend_coins' => 'integer',
        'lifetime_spend_coins' => 'integer',
        'last_login_at' => 'datetime',
        'last_login_date' => 'date',
        'current_login_streak_days' => 'integer',
        'max_login_streak_days' => 'integer',
    ];

    public function getAvatarUrlAttribute($value): ?string
    {
        if (!$value) {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            return $value;
        }

        if (Str::startsWith($value, '/storage/avatars/')) {
            return route('media.avatar', ['path' => ltrim(Str::after($value, '/storage/'), '/')]);
        }

        if (Str::startsWith($value, 'avatars/')) {
            return route('media.avatar', ['path' => $value]);
        }

        return $value;
    }
    
    public function host(): HasOne
    {
        return $this->hasOne(\App\Models\Host::class);
    }

    public function hostAvailability(): HasOne
    {
        return $this->hasOne(HostAvailability::class);
    }

    public function hostRequests(): HasMany
    {
        return $this->hasMany(\App\Models\HostRequest::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(\App\Models\Wallet::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(UserLevel::class, 'level_id');
    }

    public function levelHistories(): HasMany
    {
        return $this->hasMany(UserLevelHistory::class)->latest('id');
    }

    public function outgoingCallSessions(): HasMany
    {
        return $this->hasMany(CallSession::class, 'caller_id');
    }

    public function incomingCallSessions(): HasMany
    {
        return $this->hasMany(CallSession::class, 'receiver_id');
    }

    public function seatRequests(): HasMany
    {
        return $this->hasMany(LiveRoomSeatRequest::class);
    }

    public function hostFollows(): HasMany
    {
        return $this->hasMany(HostFollower::class);
    }

    public function followedHosts(): BelongsToMany
    {
        return $this->belongsToMany(Host::class, 'host_followers')
            ->withPivot([
                'notify_when_online',
                'notify_when_available',
                'last_online_notified_at',
                'last_available_notified_at',
            ])
            ->withTimestamps();
    }

    public function entryPacks(): HasMany
    {
        return $this->hasMany(UserEntryPack::class);
    }

    public function ownedEntryPacks(): HasManyThrough
    {
        return $this->hasManyThrough(
            EntryPack::class,
            UserEntryPack::class,
            'user_id',
            'id',
            'id',
            'entry_pack_id'
        );
    }

    public function pkEvents(): HasMany
    {
        return $this->hasMany(LiveRoomPkEvent::class);
    }

    public function adminActionAuditsReceived(): HasMany
    {
        return $this->hasMany(AdminActionAudit::class, 'target_user_id')->latest('id');
    }

    public function adminActionAuditsAuthored(): HasMany
    {
        return $this->hasMany(AdminActionAudit::class, 'admin_user_id')->latest('id');
    }

    public function themeUnlocks(): HasMany
    {
        return $this->hasMany(UserThemeUnlock::class);
    }

    public function gameAccesses(): HasMany
    {
        return $this->hasMany(UserGameAccess::class);
    }

    public function themePreference(): HasOne
    {
        return $this->hasOne(UserThemePreference::class);
    }

    public function profileFrameOwnerships(): HasMany
    {
        return $this->hasMany(UserProfileFrame::class)->latest('id');
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by_user_id');
    }

    public function hostUserBlocks(): HasMany
    {
        return $this->hasMany(HostUserBlock::class, 'host_user_id')->latest('id');
    }

    public function blocksByHost(): HasMany
    {
        return $this->hasMany(HostUserBlock::class, 'blocked_user_id')->latest('id');
    }

    public function moderationActionsTargeted(): HasMany
    {
        return $this->hasMany(ModerationAction::class, 'target_user_id')->latest('id');
    }

    public function moderationActionsAuthored(): HasMany
    {
        return $this->hasMany(ModerationAction::class, 'actor_user_id')->latest('id');
    }

    public function reportsFiled(): HasMany
    {
        return $this->hasMany(UserReport::class, 'reporter_user_id')->latest('id');
    }

    public function reportsAgainst(): HasMany
    {
        return $this->hasMany(UserReport::class, 'reported_user_id')->latest('id');
    }

    public function unblockRequestsAsHost(): HasMany
    {
        return $this->hasMany(UnblockRequest::class, 'host_user_id')->latest('id');
    }

    public function unblockRequestsAsBlockedUser(): HasMany
    {
        return $this->hasMany(UnblockRequest::class, 'blocked_user_id')->latest('id');
    }
    
    protected static function booted(): void
{
    static::created(function (User $user) {
        // 1) Default "user" role
        try {
            $guard = $user->getDefaultGuardName(); // usually 'web'
            $role  = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => $guard]);
            if (!$user->hasRole($role)) $user->assignRole($role);
        } catch (\Throwable $e) {
            Log::warning('Default role assignment failed for user '.$user->id.': '.$e->getMessage());
        }

        // 2) Ensure wallet exists
        try {
            \App\Services\WalletService::getOrCreate($user);
        } catch (\Throwable $e) {
            Log::warning('Wallet creation failed for user '.$user->id.': '.$e->getMessage());
        }

        try {
            app(\App\Services\UserLevelService::class)->initializeFor($user);
        } catch (\Throwable $e) {
            Log::warning('User level initialization failed for user '.$user->id.': '.$e->getMessage());
        }

        // 3) Signup gift guarded by device_id (ONLY at signup)
        $planId = (int) config('subscriptions.default_signup_plan_id');
        if (!$planId) return;

        $plan = \App\Models\SubscriptionPlan::where('is_active', true)->find($planId);
        if (!$plan) return;

        $request  = request();
        $deviceId = $request?->header('X-Device-Id') ?? $request?->input('device_id');

        if ($deviceId) {
            try { $user->forceFill(['device_id' => (string)$deviceId])->saveQuietly(); } catch (\Throwable $e) {}
        } else {
            // If you want to require device id for free gift, skip when missing:
            Log::info('SIGNUP_GIFT_SKIPPED_NO_DEVICE', ['user_id' => $user->id]);
            return;
        }

        try {
            // Reserve device (throws ValidationException if already used)
            $entitlement = app(\App\Services\DeviceEntitlementService::class)
                ->claimSignupGiftOrFail((string) $deviceId, $user->id, [
                    'source'     => 'signup_gift',
                    'ip'         => $request?->ip(),
                    'user_agent' => $request?->userAgent(),
                ]);

            // Grant the subscription (use your existing grant logic)
            $sub = app(\App\Services\SubscriptionService::class)->grant(
                $user,
                $plan,
                'signup_gift',
                null,
                [
                    'source'     => 'signup_gift',
                    'charged'    => false,
                    'plan_name'  => $plan->name ?? null,
                    'granted_at' => now()->toIso8601String(),
                    'device_id'  => (string) $deviceId,
                ]
            );

            if ($sub && isset($sub->id)) {
                app(\App\Services\DeviceEntitlementService::class)
                    ->attachSubscription($entitlement->id, $sub->id);
            }
        } catch (\Illuminate\Validation\ValidationException $ve) {
            Log::info('SIGNUP_GIFT_ALREADY_CLAIMED_DEVICE', [
                'user_id'   => $user->id,
                'device_id' => $deviceId,
                'errors'    => $ve->errors(),
            ]);
            // silently skip
        } catch (\Throwable $e) {
            Log::error('SIGNUP_GIFT_FAIL', [
                'user_id'   => $user->id,
                'plan_id'   => $planId,
                'device_id' => $deviceId,
                'ex'        => $e->getMessage(),
            ]);
        }
    });
}
}
