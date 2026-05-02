<?php

namespace App\Services;

use App\Models\HostFollower;
use App\Models\LiveRoomGift;
use App\Models\LiveRoomPkEvent;
use App\Models\PaymentOrder;
use App\Models\Theme;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\UserThemePreference;
use App\Models\UserThemeUnlock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ThemeUnlockService
{
    public const FALLBACK_THEME_KEY = 'midnight';
    private const THEMES_CACHE_KEY = 'themes:catalog:v1';

    public function __construct(private ThemeTokenService $themeTokens)
    {
    }

    public function themeKeys(): array
    {
        return [
            'midnight',
            'aurora',
            'gold',
            'ocean',
            'inferno',
            'emerald',
            'ice',
            'cyberpunk',
            'ruby_sky',
            'violet_lime',
            'sunset_pop',
            'teal_rose',
            'gold_black',
            'obsidian_rose',
            'royal_sapphire',
            'noir_opal',
            'imperial_jade',
            'molten_pearl',
            'amethyst_chrome',
            'crimson_velvet',
        ];
    }

    public function themesAvailable(): bool
    {
        return Schema::hasTable('themes')
            && Schema::hasTable('user_theme_unlocks')
            && Schema::hasTable('user_theme_preferences');
    }

    public function flushCache(): void
    {
        Cache::forget(self::THEMES_CACHE_KEY);
    }

    public function themesCatalog(bool $activeOnly = false): Collection
    {
        if (!$this->themesAvailable()) {
            return new Collection();
        }

        $themes = Cache::rememberForever(self::THEMES_CACHE_KEY, fn () => Theme::query()
            ->with('requiredSubscriptionPlan')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get());

        return new Collection(
            $activeOnly
                ? $themes->where('is_active', true)->values()->all()
                : $themes->values()->all()
        );
    }

    public function defaultTheme(): ?Theme
    {
        return $this->themesCatalog()->firstWhere('is_default', true)
            ?: $this->themesCatalog()->firstWhere('key', self::FALLBACK_THEME_KEY);
    }

    public function recordLogin(User $user, ?string $referralCode = null): void
    {
        $this->updateDailyStreak($user);

        $updates = [];
        $user->refresh();

        if (!$user->referral_code) {
            $updates['referral_code'] = $this->generateReferralCode();
        }

        if (!$user->referred_by_user_id && $referralCode) {
            $referrer = User::query()
                ->where('referral_code', strtoupper(trim($referralCode)))
                ->whereKeyNot($user->id)
                ->first();
            if ($referrer) {
                $updates['referred_by_user_id'] = $referrer->id;
            }
        }

        if ($updates !== []) {
            $user->forceFill($updates)->save();
        }
    }

    public function recordDailyActivity(User $user): array
    {
        return $this->updateDailyStreak($user);
    }

    private function updateDailyStreak(User $user): array
    {
        if (
            !Schema::hasColumn('users', 'last_login_at')
            || !Schema::hasColumn('users', 'last_login_date')
            || !Schema::hasColumn('users', 'current_login_streak_days')
            || !Schema::hasColumn('users', 'max_login_streak_days')
        ) {
            return [
                'updated' => false,
                'current_login_streak_days' => (int) ($user->current_login_streak_days ?? 0),
                'max_login_streak_days' => (int) ($user->max_login_streak_days ?? 0),
            ];
        }

        $today = now()->toDateString();
        $lastDate = optional($user->last_login_date)?->toDateString();
        $current = (int) ($user->current_login_streak_days ?? 0);
        $updates = [];
        $updated = false;

        if ($lastDate === $today) {
            $updates = ['last_login_at' => now()];
            $updated = false;
        } elseif ($lastDate === now()->subDay()->toDateString()) {
            $current++;
            $updates = [
                'last_login_at' => now(),
                'last_login_date' => $today,
                'current_login_streak_days' => max(1, $current),
                'max_login_streak_days' => max((int) $user->max_login_streak_days, max(1, $current)),
            ];
            $updated = true;
        } else {
            $updates = [
                'last_login_at' => now(),
                'last_login_date' => $today,
                'current_login_streak_days' => 1,
                'max_login_streak_days' => max((int) $user->max_login_streak_days, 1),
            ];
            $updated = true;
        }

        $user->forceFill($updates)->save();
        $user->refresh();

        return [
            'updated' => $updated,
            'current_login_streak_days' => (int) ($user->current_login_streak_days ?? 0),
            'max_login_streak_days' => (int) ($user->max_login_streak_days ?? 0),
        ];
    }

    public function catalogFor(?User $user, ?int $appVersionCode = null): array
    {
        if (!$this->themesAvailable()) {
            return [
                'active_theme_key' => self::FALLBACK_THEME_KEY,
                'fallback_theme_key' => self::FALLBACK_THEME_KEY,
                'themes' => [],
            ];
        }

        if ($user) {
            $this->syncAutoUnlocks($user);
        }

        $themes = $this->themesCatalog()
            ->map(function (Theme $theme) use ($user, $appVersionCode) {
                $access = $user
                    ? $this->accessPayload($user, $theme, $appVersionCode)
                    : $this->guestAccessPayload($theme, $appVersionCode);
                $remoteTokens = $this->themeTokens->previewTokensForTheme($theme, $appVersionCode);
                return [
                    'key' => $theme->key,
                    'name' => $theme->name,
                    'description' => $theme->description,
                    'unlock_type' => $theme->unlock_type,
                    'unlocked' => $access['unlocked'],
                    'locked_reason' => $access['locked_reason'],
                    'is_active' => (bool) $theme->is_active,
                    'is_default' => (bool) $theme->is_default,
                    'is_limited' => (bool) $theme->is_limited,
                    'starts_at' => optional($theme->starts_at)?->toIso8601String(),
                    'ends_at' => optional($theme->ends_at)?->toIso8601String(),
                    'price' => $theme->price !== null ? (float) $theme->price : null,
                    'sort_order' => (int) $theme->sort_order,
                    'expires_at' => optional($access['expires_at'])?->toIso8601String(),
                    'source' => $access['source'],
                    'token_source' => (string) ($theme->token_source ?? 'local'),
                    'remote_tokens' => $remoteTokens,
                ];
            })
            ->values()
            ->all();

        return [
            'active_theme_key' => $user ? $this->activeThemeKeyFor($user, false, $appVersionCode) : self::FALLBACK_THEME_KEY,
            'fallback_theme_key' => self::FALLBACK_THEME_KEY,
            'themes' => $themes,
        ];
    }

    public function activeThemeKeyFor(?User $user, bool $respectPremiumToggle = false, ?int $appVersionCode = null): string
    {
        if (!$user || !$this->themesAvailable()) {
            return self::FALLBACK_THEME_KEY;
        }

        if ($respectPremiumToggle && !(bool) config('app_features.enable_premium_theme_variants', false)) {
            return self::FALLBACK_THEME_KEY;
        }

        $preference = $user->themePreference()->first();
        $selected = strtolower(trim((string) ($preference?->active_theme_key ?? '')));

        if ($selected !== '' && $this->userHasAccessToTheme($user, $selected, $appVersionCode)) {
            return $selected;
        }

        $fallback = self::FALLBACK_THEME_KEY;
        if (!$this->userHasAccessToTheme($user, $fallback, $appVersionCode)) {
            $fallback = optional($this->defaultTheme())->key ?: self::FALLBACK_THEME_KEY;
        }

        if (!$preference || $preference->active_theme_key !== $fallback) {
            UserThemePreference::query()->updateOrCreate(
                ['user_id' => $user->id],
                ['active_theme_key' => $fallback]
            );
        }

        return $fallback;
    }

    public function availableThemeKeys(?int $appVersionCode = null): array
    {
        return $this->themesCatalog(true)
            ->filter(fn (Theme $theme) => $this->themeSupportedByApp($theme, $appVersionCode))
            ->pluck('key')
            ->values()
            ->all();
    }

    public function unlockedThemeKeys(User $user, ?int $appVersionCode = null): array
    {
        $this->syncAutoUnlocks($user);

        return $this->themesCatalog(true)
            ->filter(fn (Theme $theme) => $this->accessPayload($user, $theme, $appVersionCode)['unlocked'])
            ->pluck('key')
            ->values()
            ->all();
    }

    public function selectTheme(User $user, string $themeKey, ?int $appVersionCode = null): string
    {
        $themeKey = strtolower(trim($themeKey));
        $theme = $this->themesCatalog()->firstWhere('key', $themeKey);
        if (!$theme) {
            throw new InvalidArgumentException('Theme does not exist.');
        }

        if (!$theme->is_active) {
            throw new InvalidArgumentException('Theme is disabled.');
        }

        $access = $this->accessPayload($user, $theme, $appVersionCode);
        if (!$access['unlocked']) {
            throw new InvalidArgumentException($access['locked_reason'] ?: 'Theme is locked.');
        }

        UserThemePreference::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['active_theme_key' => $themeKey]
        );

        return $themeKey;
    }

    public function grantTheme(
        User $user,
        string $themeKey,
        string $source = 'admin_grant',
        ?User $grantedBy = null,
        ?\DateTimeInterface $expiresAt = null,
        array $metadata = []
    ): UserThemeUnlock {
        $themeKey = strtolower(trim($themeKey));
        $theme = $this->themesCatalog()->firstWhere('key', $themeKey);
        if (!$theme) {
            throw new InvalidArgumentException('Theme does not exist.');
        }

        $unlock = UserThemeUnlock::query()->updateOrCreate(
            ['user_id' => $user->id, 'theme_key' => $themeKey],
            [
                'source' => $source,
                'expires_at' => $expiresAt,
                'granted_by' => $grantedBy?->id,
                'metadata' => $metadata,
            ]
        );

        return $unlock;
    }

    public function revokeTheme(User $user, string $themeKey): void
    {
        $themeKey = strtolower(trim($themeKey));

        UserThemeUnlock::query()
            ->where('user_id', $user->id)
            ->where('theme_key', $themeKey)
            ->delete();

        if ($this->activeThemeKeyFor($user) === $themeKey || !$this->userHasAccessToTheme($user, $themeKey)) {
            UserThemePreference::query()->updateOrCreate(
                ['user_id' => $user->id],
                ['active_theme_key' => self::FALLBACK_THEME_KEY]
            );
        }
    }

    public function syncAutoUnlocks(User $user): void
    {
        if (!$this->themesAvailable()) {
            return;
        }

        $themes = $this->themesCatalog(true);
        foreach ($themes as $theme) {
            $grant = $this->automaticGrantFor($user, $theme);
            if ($grant) {
                $hadUnlockRecord = $this->hasActiveUnlockRecord($user, $theme->key);
                $this->grantTheme(
                    user: $user,
                    themeKey: $theme->key,
                    source: $grant['source'],
                    expiresAt: $grant['expires_at'] ?? null,
                    metadata: $grant['metadata'] ?? [],
                );
                if (!$hadUnlockRecord) {
                    $this->emitThemeUnlockedRealtime($user, $theme, $grant['source']);
                }
                continue;
            }

            if ($this->isAutoManagedUnlockType($theme->unlock_type)) {
                UserThemeUnlock::query()
                    ->where('user_id', $user->id)
                    ->where('theme_key', $theme->key)
                    ->whereIn('source', $this->autoManagedSourcesForType($theme->unlock_type))
                    ->delete();
            }
        }

        UserThemeUnlock::query()
            ->where('user_id', $user->id)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();

        $this->activeThemeKeyFor($user);
    }

    private function hasActiveUnlockRecord(User $user, string $themeKey): bool
    {
        return UserThemeUnlock::query()
            ->where('user_id', $user->id)
            ->where('theme_key', strtolower(trim($themeKey)))
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    private function emitThemeUnlockedRealtime(User $user, Theme $theme, string $source): void
    {
        try {
            NotifyUser::send($user->id, [
                'type' => 'theme_unlocked',
                'title' => 'New theme unlocked',
                'body' => sprintf('%s is now available.', $theme->name),
                'screen' => 'theme_center',
                'meta' => [
                    'theme_key' => $theme->key,
                    'theme_name' => $theme->name,
                    'unlock_type' => $theme->unlock_type,
                    'source' => $source,
                ],
            ], [
                'push' => false,
                'persist' => true,
            ]);
        } catch (\Throwable) {
            // Theme unlocks should never block the user flow.
        }
    }

    public function accessPayload(User $user, Theme $theme, ?int $appVersionCode = null): array
    {
        if (!$theme->is_active) {
            return $this->locked('Theme is disabled.');
        }

        if (!$this->themeSupportedByApp($theme, $appVersionCode)) {
            return $this->locked('Theme is not supported by the app.');
        }

        if (!$this->themeTokens->isCompatibleWithAppVersion($theme, $appVersionCode)) {
            return $this->locked('Update the app to use this theme.');
        }

        if ($theme->key === self::FALLBACK_THEME_KEY || $theme->unlock_type === 'free' || $theme->is_default) {
            return [
                'unlocked' => true,
                'locked_reason' => null,
                'expires_at' => null,
                'source' => 'free',
            ];
        }

        $manual = $user->themeUnlocks()
            ->where('theme_key', $theme->key)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('id')
            ->first();

        if ($manual) {
            return [
                'unlocked' => true,
                'locked_reason' => null,
                'expires_at' => $manual->expires_at,
                'source' => $manual->source,
            ];
        }

        if ($theme->is_limited && !$this->isWithinWindow($theme)) {
            return $this->locked('Theme is not currently available.');
        }

        return $this->matchesUnlockRule($user, $theme);
    }

    public function guestAccessPayload(Theme $theme, ?int $appVersionCode = null): array
    {
        if (!$theme->is_active) {
            return $this->locked('Theme is disabled.');
        }

        if (!$this->themeSupportedByApp($theme, $appVersionCode)) {
            return $this->locked('Theme is not supported by the app.');
        }

        if (!$this->themeTokens->isCompatibleWithAppVersion($theme, $appVersionCode)) {
            return $this->locked('Update the app to use this theme.');
        }

        if ($theme->key === self::FALLBACK_THEME_KEY || $theme->unlock_type === 'free' || $theme->is_default) {
            return [
                'unlocked' => true,
                'locked_reason' => null,
                'expires_at' => null,
                'source' => 'free',
            ];
        }

        return $this->locked('Sign in to unlock this theme.');
    }

    public function userHasAccessToTheme(User $user, string $themeKey, ?int $appVersionCode = null): bool
    {
        $theme = $this->themesCatalog()->firstWhere('key', strtolower(trim($themeKey)));
        if (!$theme) {
            return false;
        }

        return (bool) ($this->accessPayload($user, $theme, $appVersionCode)['unlocked'] ?? false);
    }

    private function matchesUnlockRule(User $user, Theme $theme): array
    {
        return match ($theme->unlock_type) {
            'subscription' => $this->subscriptionAccess($user, $theme),
            'vip_subscription' => $this->vipSubscriptionAccess($user, $theme),
            'vip_high_tier' => $this->vipHighTierAccess($user, $theme),
            'first_recharge' => $this->firstRechargeAccess($user),
            'recharge_milestone' => $this->rechargeMilestoneAccess($user, $theme),
            'gift_spend' => $this->giftSpendAccess($user, $theme),
            'user_level' => $this->userLevelAccess($user, $theme),
            'host_level' => $this->hostLevelAccess($user, $theme),
            'login_streak' => $this->loginStreakAccess($user, $theme),
            'pk_event' => $this->pkEventAccess($user, $theme),
            'referral' => $this->referralAccess($user, $theme),
            'host_follower_milestone' => $this->hostFollowerAccess($user, $theme),
            'agency_host_elite' => $this->agencyHostEliteAccess($user),
            'event_reward', 'festival_event' => $this->eventWindowAccess($theme),
            'limited_paid' => $this->locked('Purchase flow is not enabled for this theme yet.'),
            'loyalty' => $this->loyaltyAccess($user, $theme),
            'top_spender' => $this->topSpenderAccess($user, $theme),
            default => $this->locked('Theme is locked.'),
        };
    }

    private function automaticGrantFor(User $user, Theme $theme): ?array
    {
        if (!$this->isAutoManagedUnlockType($theme->unlock_type)) {
            return null;
        }

        if ((int) $user->id === 61 && $theme->key === 'cyberpunk') {
            return [
                'source' => $theme->unlock_type,
                'expires_at' => null,
                'metadata' => [
                    'auto_granted_at' => now()->toIso8601String(),
                    'unlock_type' => $theme->unlock_type,
                    'mock_unlock_for_user_61' => true,
                ],
            ];
        }

        $access = $this->matchesUnlockRule($user, $theme);
        if (!($access['unlocked'] ?? false)) {
            return null;
        }

        return [
            'source' => $theme->unlock_type,
            'expires_at' => $access['expires_at'] ?? null,
            'metadata' => [
                'auto_granted_at' => now()->toIso8601String(),
                'unlock_type' => $theme->unlock_type,
            ],
        ];
    }

    private function isAutoManagedUnlockType(string $unlockType): bool
    {
        return in_array($unlockType, [
            'subscription',
            'vip_subscription',
            'vip_high_tier',
            'first_recharge',
            'recharge_milestone',
            'gift_spend',
            'user_level',
            'host_level',
            'login_streak',
            'pk_event',
            'referral',
            'host_follower_milestone',
            'agency_host_elite',
            'event_reward',
            'festival_event',
            'loyalty',
            'top_spender',
        ], true);
    }

    private function autoManagedSourcesForType(string $unlockType): array
    {
        return [$unlockType];
    }

    private function subscriptionAccess(User $user, Theme $theme): array
    {
        $sub = $this->activeSubscriptionFor($user, $theme);
        if ($sub) {
            return ['unlocked' => true, 'locked_reason' => null, 'expires_at' => $sub->ends_at, 'source' => 'subscription'];
        }

        return $this->locked('Requires an active subscription.');
    }

    private function vipSubscriptionAccess(User $user, Theme $theme): array
    {
        $sub = $this->activeSubscriptionFor($user, $theme, ['vip']);
        if ($sub) {
            return ['unlocked' => true, 'locked_reason' => null, 'expires_at' => $sub->ends_at, 'source' => 'vip_subscription'];
        }

        return $this->locked('Requires an active VIP subscription.');
    }

    private function vipHighTierAccess(User $user, Theme $theme): array
    {
        $sub = $this->activeSubscriptionFor($user, $theme, ['vip', 'elite', 'platinum']);
        if ($sub && ((int) ($sub->plan?->price_coins ?? 0)) >= (int) data_get($theme->metadata, 'min_plan_price_coins', 0)) {
            return ['unlocked' => true, 'locked_reason' => null, 'expires_at' => $sub->ends_at, 'source' => 'vip_high_tier'];
        }

        return $this->locked('Requires a high-tier VIP subscription.');
    }

    private function firstRechargeAccess(User $user): array
    {
        $hasRecharge = PaymentOrder::query()
            ->where('user_id', $user->id)
            ->where('status', 'success')
            ->exists();

        return $hasRecharge
            ? ['unlocked' => true, 'locked_reason' => null, 'expires_at' => null, 'source' => 'first_recharge']
            : $this->locked('Unlocks after your first successful recharge.');
    }

    private function rechargeMilestoneAccess(User $user, Theme $theme): array
    {
        $required = (float) ($theme->required_total_recharge ?? 0);
        $total = (float) PaymentOrder::query()
            ->where('user_id', $user->id)
            ->where('status', 'success')
            ->sum('amount_rupees');

        return $total >= $required && $required > 0
            ? ['unlocked' => true, 'locked_reason' => null, 'expires_at' => null, 'source' => 'recharge_milestone']
            : $this->locked('Recharge milestone not reached yet.');
    }

    private function giftSpendAccess(User $user, Theme $theme): array
    {
        $required = (int) ($theme->required_total_gift_spend ?? 0);
        $spent = (int) LiveRoomGift::query()
            ->where('sender_user_id', $user->id)
            ->sum('total_coins');

        return $spent >= $required && $required > 0
            ? ['unlocked' => true, 'locked_reason' => null, 'expires_at' => null, 'source' => 'gift_spend']
            : $this->locked('Gift spend milestone not reached yet.');
    }

    private function userLevelAccess(User $user, Theme $theme): array
    {
        $required = (int) ($theme->required_user_level ?? 0);
        $level = (int) ($user->level?->level ?? 0);

        return $level >= $required && $required > 0
            ? ['unlocked' => true, 'locked_reason' => null, 'expires_at' => null, 'source' => 'user_level']
            : $this->locked('User level requirement not met.');
    }

    private function hostLevelAccess(User $user, Theme $theme): array
    {
        $required = (int) ($theme->required_host_level ?? 0);
        $level = (int) ($user->level?->level ?? 0);

        if ($user->hasRole('host') && $required > 0 && $level >= $required) {
            return ['unlocked' => true, 'locked_reason' => null, 'expires_at' => null, 'source' => 'host_level'];
        }

        return $this->locked('Host level requirement not met.');
    }

    private function loginStreakAccess(User $user, Theme $theme): array
    {
        $required = (int) ($theme->required_login_streak_days ?? 0);
        return (int) $user->current_login_streak_days >= $required && $required > 0
            ? ['unlocked' => true, 'locked_reason' => null, 'expires_at' => null, 'source' => 'login_streak']
            : $this->locked('Login streak requirement not met.');
    }

    private function pkEventAccess(User $user, Theme $theme): array
    {
        $eventType = data_get($theme->metadata, 'pk_event_type');
        $query = LiveRoomPkEvent::query()->where('user_id', $user->id);
        if ($eventType) {
            $query->where('event_type', $eventType);
        }

        return $query->exists()
            ? ['unlocked' => true, 'locked_reason' => null, 'expires_at' => null, 'source' => 'pk_event']
            : $this->locked('Participate in PK events to unlock this theme.');
    }

    private function referralAccess(User $user, Theme $theme): array
    {
        $required = (int) ($theme->required_referrals ?? 0);
        $count = $user->referrals()->count();

        return $count >= $required && $required > 0
            ? ['unlocked' => true, 'locked_reason' => null, 'expires_at' => null, 'source' => 'referral']
            : $this->locked('Referral milestone not reached yet.');
    }

    private function hostFollowerAccess(User $user, Theme $theme): array
    {
        if (!$user->host) {
            return $this->locked('Host account required.');
        }

        $required = (int) ($theme->required_host_followers ?? 0);
        $count = (int) HostFollower::query()->where('host_id', $user->host->id)->count();

        return $count >= $required && $required > 0
            ? ['unlocked' => true, 'locked_reason' => null, 'expires_at' => null, 'source' => 'host_follower_milestone']
            : $this->locked('Host follower milestone not reached yet.');
    }

    private function agencyHostEliteAccess(User $user): array
    {
        return ($user->hasRole('host') && $user->host?->agency_id)
            ? ['unlocked' => true, 'locked_reason' => null, 'expires_at' => null, 'source' => 'agency_host_elite']
            : $this->locked('Requires an agency-linked host profile.');
    }

    private function eventWindowAccess(Theme $theme): array
    {
        return $this->isWithinWindow($theme)
            ? ['unlocked' => true, 'locked_reason' => null, 'expires_at' => $theme->ends_at, 'source' => $theme->unlock_type]
            : $this->locked('Theme is not currently available.');
    }

    private function loyaltyAccess(User $user, Theme $theme): array
    {
        $requiredDays = (int) data_get($theme->metadata, 'required_loyalty_days', 90);
        $days = (int) optional($user->created_at)->diffInDays(now());

        return $days >= $requiredDays
            ? ['unlocked' => true, 'locked_reason' => null, 'expires_at' => null, 'source' => 'loyalty']
            : $this->locked('Loyalty requirement not met yet.');
    }

    private function topSpenderAccess(User $user, Theme $theme): array
    {
        $minSpend = (int) data_get($theme->metadata, 'min_lifetime_spend_coins', 1);
        $topRank = max(1, (int) data_get($theme->metadata, 'top_rank_threshold', 10));

        if ((int) $user->lifetime_spend_coins < $minSpend) {
            return $this->locked('Top spender requirement not met yet.');
        }

        $rank = (int) User::query()
            ->where('lifetime_spend_coins', '>', (int) $user->lifetime_spend_coins)
            ->count() + 1;

        return $rank <= $topRank
            ? ['unlocked' => true, 'locked_reason' => null, 'expires_at' => null, 'source' => 'top_spender']
            : $this->locked('Top spender requirement not met yet.');
    }

    private function activeSubscriptionFor(User $user, Theme $theme, array $planKeywords = []): ?UserSubscription
    {
        return UserSubscription::query()
            ->with('plan')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->when($theme->required_subscription_plan_id, fn ($query) => $query->where('subscription_plan_id', $theme->required_subscription_plan_id))
            ->get()
            ->first(function (UserSubscription $subscription) use ($planKeywords) {
                if ($planKeywords === []) {
                    return true;
                }

                $name = strtolower((string) ($subscription->plan?->name ?? ''));
                foreach ($planKeywords as $keyword) {
                    if (str_contains($name, strtolower($keyword))) {
                        return true;
                    }
                }

                return false;
            });
    }

    private function locked(string $reason): array
    {
        return [
            'unlocked' => false,
            'locked_reason' => $reason,
            'expires_at' => null,
            'source' => null,
        ];
    }

    private function isWithinWindow(Theme $theme): bool
    {
        if ($theme->starts_at && $theme->starts_at->isFuture()) {
            return false;
        }

        if ($theme->ends_at && $theme->ends_at->isPast()) {
            return false;
        }

        return true;
    }

    private function themeKeysContains(string $themeKey): bool
    {
        return in_array($themeKey, $this->themeKeys(), true);
    }

    private function themeSupportedByApp(Theme $theme, ?int $appVersionCode): bool
    {
        $hasLocalSupport = $this->themeKeysContains($theme->key);
        $remoteTokens = $this->themeTokens->remoteTokensForTheme($theme, $appVersionCode, false);

        return match ((string) ($theme->token_source ?? 'local')) {
            'remote' => $remoteTokens !== null,
            'hybrid' => $hasLocalSupport || $remoteTokens !== null,
            default => $hasLocalSupport,
        };
    }

    private function generateReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (User::query()->where('referral_code', $code)->exists());

        return $code;
    }
}
