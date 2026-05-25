<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AppSettingsService
{
    private const SETTINGS_CACHE_KEY = 'app_settings:all:v1';
    private const PUBLIC_APP_CONFIG_CACHE_KEY = 'app_config:public:v2';

    public const APP_DEFINITIONS = [
        'app_features.enable_premium_theme_variants' => [
            'label' => 'Enable Premium Theme Variants',
            'type' => 'boolean',
            'group' => 'general',
            'default' => false,
            'hint' => 'Exposes premium theme variants to the app UI when the client supports them.',
        ],
        'app_features.enable_theme_environment_effects' => [
            'label' => 'Enable Theme Environment Effects',
            'type' => 'boolean',
            'group' => 'general',
            'default' => true,
            'hint' => 'Enables subtle global animated environment effects based on the active theme.',
        ],
        'app_features.maintenance_mode_enabled' => [
            'label' => 'Maintenance Mode',
            'type' => 'boolean',
            'group' => 'general',
            'default' => false,
            'hint' => 'Blocks non-admin web and API traffic with a maintenance response.',
        ],
        'app_features.force_app_upgrade_enabled' => [
            'label' => 'Force App Upgrade',
            'type' => 'boolean',
            'group' => 'general',
            'default' => false,
            'hint' => 'Signals clients that a mandatory upgrade flow should be enforced.',
        ],
        'app_features.host_goals.followers' => [
            'label' => 'Follower Goal Milestones',
            'type' => 'csv_integer_list',
            'group' => 'host_goals',
            'default' => '25,50,100,250,500,1000,2500',
            'hint' => 'Comma-separated follower milestones shown on the host profile goals card.',
        ],
        'app_features.host_goals.weekly_live_minutes' => [
            'label' => 'Weekly Live Minute Milestones',
            'type' => 'csv_integer_list',
            'group' => 'host_goals',
            'default' => '60,180,300,600,900,1200',
            'hint' => 'Comma-separated weekly live-minute milestones for host progress.',
        ],
        'app_features.host_goals.weekly_gifted_coins' => [
            'label' => 'Weekly Gifted Coin Milestones',
            'type' => 'csv_integer_list',
            'group' => 'host_goals',
            'default' => '500,1000,2500,5000,10000,25000',
            'hint' => 'Comma-separated weekly gifted-coin milestones for host progress.',
        ],
        'app_features.platform.android.audio_rooms_enabled' => [
            'label' => 'Audio Rooms',
            'type' => 'boolean',
            'group' => 'android',
            'default' => true,
        ],
        'app_features.platform.android.video_rooms_enabled' => [
            'label' => 'Video Rooms',
            'type' => 'boolean',
            'group' => 'android',
            'default' => true,
        ],
        'app_features.platform.android.pk_battles_enabled' => [
            'label' => 'PK Battles',
            'type' => 'boolean',
            'group' => 'android',
            'default' => true,
        ],
        'app_features.platform.android.gifts_enabled' => [
            'label' => 'Gifts',
            'type' => 'boolean',
            'group' => 'android',
            'default' => true,
        ],
        'app_features.platform.android.subscriptions_enabled' => [
            'label' => 'Subscriptions',
            'type' => 'boolean',
            'group' => 'android',
            'default' => true,
        ],
        'app_features.platform.android.entry_effects_enabled' => [
            'label' => 'Entry Effects',
            'type' => 'boolean',
            'group' => 'android',
            'default' => true,
        ],
        'app_features.platform.android.wallet_recharge_enabled' => [
            'label' => 'Wallet Recharge',
            'type' => 'boolean',
            'group' => 'android',
            'default' => true,
        ],
        'app_features.platform.android.host_calling_enabled' => [
            'label' => 'Host Calling',
            'type' => 'boolean',
            'group' => 'android',
            'default' => true,
        ],
        'app_features.platform.android.teen_patti_enabled' => [
            'label' => 'Teen Patti',
            'type' => 'boolean',
            'group' => 'android',
            'default' => false,
        ],
        'app_features.platform.android.greedy_enabled' => [
            'label' => 'Greedy',
            'type' => 'boolean',
            'group' => 'android',
            'default' => false,
        ],
        'app_features.platform.android.video_room_games_enabled' => [
            'label' => 'Video Room Games Strip',
            'type' => 'boolean',
            'group' => 'android',
            'default' => false,
        ],
    ];

    public const CALL_DEFINITIONS = [
        'calls.audio_coin_rate_per_minute' => [
            'label' => 'Audio Call Rate / min',
            'type' => 'integer',
            'min' => 1,
            'hint' => 'Global default used when a host-specific audio rate is empty.',
        ],
        'calls.video_coin_rate_per_minute' => [
            'label' => 'Video Call Rate / min',
            'type' => 'integer',
            'min' => 1,
            'hint' => 'Global default used when a host-specific video rate is empty.',
        ],
        'calls.minimum_balance_to_start_call' => [
            'label' => 'Minimum Balance To Start Call',
            'type' => 'integer',
            'min' => 0,
            'hint' => 'Effective minimum is max(minimum balance, selected call rate).',
        ],
        'calls.minimum_billable_minutes' => [
            'label' => 'Minimum Billable Minutes',
            'type' => 'integer',
            'min' => 1,
            'hint' => 'Billing rounds up duration, then applies this minimum.',
        ],
        'calls.ringing_timeout_seconds' => [
            'label' => 'Ringing Timeout Seconds',
            'type' => 'integer',
            'min' => 5,
            'hint' => 'Pending/ringing calls beyond this limit are marked missed.',
        ],
        'calls.host_share_percent' => [
            'label' => 'Host Share %',
            'type' => 'float',
            'min' => 0,
            'max' => 100,
            'step' => '0.01',
            'hint' => 'Revenue split share credited to the host ledger.',
        ],
        'calls.agency_share_percent' => [
            'label' => 'Agency Share %',
            'type' => 'float',
            'min' => 0,
            'max' => 100,
            'step' => '0.01',
            'hint' => 'Revenue split share credited to the agency ledger.',
        ],
        'calls.platform_share_percent' => [
            'label' => 'Platform Share %',
            'type' => 'float',
            'min' => 0,
            'max' => 100,
            'step' => '0.01',
            'hint' => 'Revenue retained by the platform.',
        ],
    ];

    public const LIVE_ROOM_DEFINITIONS = [
        'live_rooms.video.max_participants' => [
            'label' => 'Video Max Participants',
            'type' => 'integer',
            'min' => 2,
            'max' => 500,
            'hint' => 'Default participant cap applied when a video room is created without an explicit override.',
        ],
        'live_rooms.video.max_speakers' => [
            'label' => 'Video Max Speakers',
            'type' => 'integer',
            'min' => 1,
            'max' => 100,
            'hint' => 'Default speaker cap for video rooms. Must stay lower than video max participants.',
        ],
        'live_rooms.audio.max_participants' => [
            'label' => 'Audio Max Participants',
            'type' => 'integer',
            'min' => 2,
            'max' => 500,
            'hint' => 'Default participant cap applied when an audio room is created without an explicit override.',
        ],
        'live_rooms.audio.max_speakers' => [
            'label' => 'Audio Max Speakers',
            'type' => 'integer',
            'min' => 1,
            'max' => 100,
            'hint' => 'Default speaker cap for audio rooms. Must stay lower than audio max participants.',
        ],
    ];

    public const GAME_DEFINITIONS = [
        'games.teen_patti.enabled' => [
            'label' => 'Enable Teen Patti Engine',
            'type' => 'boolean',
            'group' => 'availability',
            'default' => false,
            'hint' => 'Server-side master switch. Disables rounds, betting, and settlement when off.',
        ],
        'games.teen_patti.visible_in_video_room_strip' => [
            'label' => 'Show In Video Room Strip',
            'type' => 'boolean',
            'group' => 'availability',
            'default' => true,
            'hint' => 'Controls whether the Games entry appears in the video room action strip.',
        ],
        'games.teen_patti.fake_bets_enabled' => [
            'label' => 'Enable Fake Bets Display',
            'type' => 'boolean',
            'group' => 'availability',
            'default' => false,
            'hint' => 'Adds virtual pot volume for player-facing game screens only. These bets are not stored or settled.',
        ],
        'games.teen_patti.min_bet' => [
            'label' => 'Minimum Bet',
            'type' => 'integer',
            'group' => 'limits',
            'default' => 10,
            'min' => 1,
            'hint' => 'Lowest allowed coin amount per bet.',
        ],
        'games.teen_patti.max_bet' => [
            'label' => 'Maximum Bet',
            'type' => 'integer',
            'group' => 'limits',
            'default' => 5000,
            'min' => 1,
            'hint' => 'Highest allowed coin amount per bet.',
        ],
        'games.teen_patti.round_duration_seconds' => [
            'label' => 'Round Duration Seconds',
            'type' => 'integer',
            'group' => 'timing',
            'default' => 30,
            'min' => 10,
            'hint' => 'Base round duration used by the realtime game loop.',
        ],
        'games.teen_patti.betting_lock_seconds' => [
            'label' => 'Bet Lock Seconds',
            'type' => 'integer',
            'group' => 'timing',
            'default' => 5,
            'min' => 2,
            'hint' => 'How many seconds before reveal betting is locked.',
        ],
        'games.teen_patti.result_display_seconds' => [
            'label' => 'Result Display Seconds',
            'type' => 'integer',
            'group' => 'timing',
            'default' => 6,
            'min' => 3,
            'hint' => 'How long result state remains before the next round begins.',
        ],
        'games.teen_patti.payout_multiplier' => [
            'label' => 'Payout Multiplier',
            'type' => 'integer',
            'group' => 'economy',
            'default' => 3,
            'min' => 2,
            'hint' => 'Winning bets are credited with bet amount multiplied by this value.',
        ],
        'games.teen_patti.winning_strategy_mode' => [
            'label' => 'Winning Strategy',
            'type' => 'string',
            'group' => 'economy',
            'default' => 'probability',
            'options' => ['random', 'minimum_bet', 'highest_bet', 'probability'],
            'hint' => 'Server-side winner selection strategy for round settlement.',
        ],
        'games.greedy.enabled' => [
            'label' => 'Enable Greedy Engine',
            'type' => 'boolean',
            'group' => 'availability',
            'default' => false,
            'hint' => 'Server-side master switch. Disables Greedy rounds, betting, and settlement when off.',
        ],
        'games.greedy.visible_in_video_room_strip' => [
            'label' => 'Show Greedy In Video Room Strip',
            'type' => 'boolean',
            'group' => 'availability',
            'default' => true,
            'hint' => 'Controls whether Greedy appears in the video room games sheet.',
        ],
        'games.greedy.fake_bets_enabled' => [
            'label' => 'Enable Greedy Fake Bets Display',
            'type' => 'boolean',
            'group' => 'availability',
            'default' => false,
            'hint' => 'Adds virtual Greedy pot volume for player-facing screens only. These bets are not stored or settled.',
        ],
        'games.greedy.min_bet' => [
            'label' => 'Greedy Minimum Bet',
            'type' => 'integer',
            'group' => 'limits',
            'default' => 10,
            'min' => 1,
            'hint' => 'Lowest allowed coin amount per Greedy bet.',
        ],
        'games.greedy.max_bet' => [
            'label' => 'Greedy Maximum Bet',
            'type' => 'integer',
            'group' => 'limits',
            'default' => 5000,
            'min' => 1,
            'hint' => 'Highest allowed coin amount per Greedy bet.',
        ],
        'games.greedy.round_duration_seconds' => [
            'label' => 'Greedy Round Duration Seconds',
            'type' => 'integer',
            'group' => 'timing',
            'default' => 30,
            'min' => 10,
            'hint' => 'Base Greedy round duration used by the realtime game loop.',
        ],
        'games.greedy.betting_lock_seconds' => [
            'label' => 'Greedy Bet Lock Seconds',
            'type' => 'integer',
            'group' => 'timing',
            'default' => 5,
            'min' => 2,
            'hint' => 'How many seconds before the wheel result betting is locked.',
        ],
        'games.greedy.result_display_seconds' => [
            'label' => 'Greedy Result Display Seconds',
            'type' => 'integer',
            'group' => 'timing',
            'default' => 6,
            'min' => 3,
            'hint' => 'How long Greedy result state remains before the next round begins.',
        ],
        'games.greedy.winning_strategy_mode' => [
            'label' => 'Greedy Winning Strategy',
            'type' => 'string',
            'group' => 'economy',
            'default' => 'probability',
            'options' => ['random', 'minimum_liability', 'highest_liability', 'probability', 'exposure_guard'],
            'hint' => 'Server-side winner selection strategy for Greedy round settlement.',
        ],
        'games.greedy.multiplier_a' => [
            'label' => 'Pot A Multiplier',
            'type' => 'integer',
            'group' => 'economy',
            'default' => 2,
            'min' => 2,
            'hint' => 'Payout multiplier for pot A.',
        ],
        'games.greedy.multiplier_b' => [
            'label' => 'Pot B Multiplier',
            'type' => 'integer',
            'group' => 'economy',
            'default' => 3,
            'min' => 2,
            'hint' => 'Payout multiplier for pot B.',
        ],
        'games.greedy.multiplier_c' => [
            'label' => 'Pot C Multiplier',
            'type' => 'integer',
            'group' => 'economy',
            'default' => 5,
            'min' => 2,
            'hint' => 'Payout multiplier for pot C.',
        ],
        'games.greedy.multiplier_d' => [
            'label' => 'Pot D Multiplier',
            'type' => 'integer',
            'group' => 'economy',
            'default' => 10,
            'min' => 2,
            'hint' => 'Payout multiplier for pot D.',
        ],
        'games.greedy.sectors_a' => [
            'label' => 'Pot A Sectors',
            'type' => 'integer',
            'group' => 'economy',
            'default' => 22,
            'min' => 1,
            'hint' => 'How many weighted wheel sectors map to pot A.',
        ],
        'games.greedy.sectors_b' => [
            'label' => 'Pot B Sectors',
            'type' => 'integer',
            'group' => 'economy',
            'default' => 14,
            'min' => 1,
            'hint' => 'How many weighted wheel sectors map to pot B.',
        ],
        'games.greedy.sectors_c' => [
            'label' => 'Pot C Sectors',
            'type' => 'integer',
            'group' => 'economy',
            'default' => 8,
            'min' => 1,
            'hint' => 'How many weighted wheel sectors map to pot C.',
        ],
        'games.greedy.sectors_d' => [
            'label' => 'Pot D Sectors',
            'type' => 'integer',
            'group' => 'economy',
            'default' => 4,
            'min' => 1,
            'hint' => 'How many weighted wheel sectors map to pot D.',
        ],
    ];

    public function loadCallSettingsIntoConfig(): void
    {
        $this->loadDefinitionsIntoConfig(self::CALL_DEFINITIONS);
    }

    public function loadAppSettingsIntoConfig(): void
    {
        $this->loadDefinitionsIntoConfig(self::APP_DEFINITIONS);
    }

    public function loadLiveRoomSettingsIntoConfig(): void
    {
        $this->loadDefinitionsIntoConfig(self::LIVE_ROOM_DEFINITIONS);
    }

    public function loadGameSettingsIntoConfig(): void
    {
        $this->loadDefinitionsIntoConfig(self::GAME_DEFINITIONS);
    }

    public function callSettings(): array
    {
        $values = [];
        foreach (self::CALL_DEFINITIONS as $key => $definition) {
            $values[$key] = config($key);
        }

        return $values;
    }

    public function updateCallSettings(array $validated): void
    {
        $this->updateSettings($validated, self::CALL_DEFINITIONS, 'calls');
    }

    public function liveRoomSettings(): array
    {
        $values = [];
        foreach (self::LIVE_ROOM_DEFINITIONS as $key => $definition) {
            $values[$key] = config($key);
        }

        return $values;
    }

    public function updateLiveRoomSettings(array $validated): void
    {
        $this->updateSettings($validated, self::LIVE_ROOM_DEFINITIONS, 'live_rooms');
    }

    public function gameSettings(): array
    {
        $values = [];
        foreach (self::GAME_DEFINITIONS as $key => $definition) {
            $values[$key] = config($key, $definition['default'] ?? null);
        }

        return $values;
    }

    public function updateGameSettings(array $validated): void
    {
        $this->updateSettings($validated, self::GAME_DEFINITIONS, 'games');
    }

    public function appSettings(): array
    {
        $values = [];
        foreach (self::APP_DEFINITIONS as $key => $definition) {
            $values[$key] = config($key, $definition['default'] ?? null);
        }

        return $values;
    }

    public function updateAppSettings(array $validated): void
    {
        $this->updateSettings($validated, self::APP_DEFINITIONS, 'app_features');
    }

    public function publicAppPayload(?User $user = null, ?ThemeUnlockService $themeUnlocks = null, ?int $appVersionCode = null): array
    {
        $base = Cache::rememberForever(self::PUBLIC_APP_CONFIG_CACHE_KEY, function (): array {
            return [
                'enable_premium_theme_variants' => (bool) config('app_features.enable_premium_theme_variants', false),
                'enable_theme_environment_effects' => (bool) config('app_features.enable_theme_environment_effects', true),
                'maintenance_mode_enabled' => (bool) config('app_features.maintenance_mode_enabled', false),
                'force_app_upgrade_enabled' => (bool) config('app_features.force_app_upgrade_enabled', false),
                'android_min_version_code' => $this->minimumAndroidVersionCode(),
                'android_min_version_name' => $this->minimumAndroidVersionName(),
                'android_update_message' => $this->androidUpdateMessage(),
                'host_goals' => $this->publicHostGoalSettings(),
                'features' => $this->androidFeatureFlags(),
            ];
        });

        $themes = $themeUnlocks ?: app(ThemeUnlockService::class);
        $themeTokenService = app(ThemeTokenService::class);
        $activeThemeKey = $user
            ? $themes->activeThemeKeyFor($user, true, $appVersionCode)
            : 'midnight';
        $activeTheme = $activeThemeKey !== 'midnight'
            ? $themes->themesCatalog()->firstWhere('key', $activeThemeKey)
            : null;
        $activeThemeTokens = null;
        $activeThemeTokenSource = 'local';

        if (!(bool) ($base['enable_premium_theme_variants'] ?? false)) {
            $activeThemeKey = 'midnight';
        } elseif ($activeTheme) {
            $activeThemeTokenSource = (string) ($activeTheme->token_source ?? 'local');
            if (in_array($activeThemeTokenSource, ['remote', 'hybrid'], true)) {
                $activeThemeTokens = $themeTokenService->remoteTokensForTheme($activeTheme, $appVersionCode);
                if ($activeThemeTokens === null && $activeThemeTokenSource === 'remote') {
                    Log::warning('theme.active_remote_tokens_invalid', [
                        'theme_key' => $activeThemeKey,
                        'user_id' => $user?->id,
                        'app_version_code' => $appVersionCode,
                    ]);
                    $activeThemeKey = 'midnight';
                    $activeThemeTokenSource = 'local';
                }
            }
        }

        return array_merge($base, [
            'active_theme_key' => $activeThemeKey,
            'premium_theme_variant' => $activeThemeKey,
            'fallback_theme_key' => 'midnight',
            'active_theme_token_source' => $activeThemeTokenSource,
            'active_theme_tokens' => $activeThemeTokens,
            'unlocked_theme_keys' => $user && (bool) ($base['enable_premium_theme_variants'] ?? false)
                ? $themes->unlockedThemeKeys($user, $appVersionCode)
                : ['midnight'],
            'available_theme_keys' => (bool) ($base['enable_premium_theme_variants'] ?? false)
                ? ($themes->availableThemeKeys($appVersionCode) ?: ['midnight'])
                : ['midnight'],
        ]);
    }

    public function androidFeatureFlags(): array
    {
        return [
            'audio_rooms_enabled' => (bool) config('app_features.platform.android.audio_rooms_enabled', true),
            'video_rooms_enabled' => (bool) config('app_features.platform.android.video_rooms_enabled', true),
            'pk_battles_enabled' => (bool) config('app_features.platform.android.pk_battles_enabled', true),
            'gifts_enabled' => (bool) config('app_features.platform.android.gifts_enabled', true),
            'subscriptions_enabled' => (bool) config('app_features.platform.android.subscriptions_enabled', true),
            'entry_effects_enabled' => (bool) config('app_features.platform.android.entry_effects_enabled', true),
            'wallet_recharge_enabled' => (bool) config('app_features.platform.android.wallet_recharge_enabled', true),
            'host_calling_enabled' => (bool) config('app_features.platform.android.host_calling_enabled', true),
            'teen_patti_enabled' => (bool) config('app_features.platform.android.teen_patti_enabled', false),
            'greedy_enabled' => (bool) config('app_features.platform.android.greedy_enabled', false),
            'video_room_games_enabled' => (bool) config('app_features.platform.android.video_room_games_enabled', false),
        ];
    }

    public function minimumAndroidVersionCode(): int
    {
        return max(1, (int) env('ANDROID_MIN_VERSION_CODE', 1));
    }

    public function minimumAndroidVersionName(): string
    {
        return (string) env('ANDROID_MIN_VERSION_NAME', '1.0.0');
    }

    public function androidUpdateMessage(): string
    {
        return (string) env('ANDROID_UPDATE_MESSAGE', 'Please update Talkieo to continue using the app.');
    }

    public function publicHostGoalSettings(): array
    {
        return [
            'followers' => $this->parseIntegerListConfig(
                config('app_features.host_goals.followers', '25,50,100,250,500,1000,2500'),
                [25, 50, 100, 250, 500, 1000, 2500],
            ),
            'weekly_live_minutes' => $this->parseIntegerListConfig(
                config('app_features.host_goals.weekly_live_minutes', '60,180,300,600,900,1200'),
                [60, 180, 300, 600, 900, 1200],
            ),
            'weekly_gifted_coins' => $this->parseIntegerListConfig(
                config('app_features.host_goals.weekly_gifted_coins', '500,1000,2500,5000,10000,25000'),
                [500, 1000, 2500, 5000, 10000, 25000],
            ),
        ];
    }

    private function castValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            'float' => (float) $value,
            'csv_integer_list' => $this->normalizeIntegerListString($value),
            'string' => trim((string) $value),
            default => (int) $value,
        };
    }

    private function normalizeSettingsPayload(array $validated, string $prefix): array
    {
        $normalized = [];
        $this->flattenSettingsPayload($validated, $prefix, $normalized);

        return $normalized;
    }

    private function flattenSettingsPayload(array $values, string $path, array &$normalized): void
    {
        foreach ($values as $key => $value) {
            $fullPath = str_starts_with($key, "{$path}.") ? $key : "{$path}.{$key}";
            if (is_array($value)) {
                $this->flattenSettingsPayload($value, $fullPath, $normalized);
                continue;
            }

            $normalized[$fullPath] = $value;
        }
    }

    private function loadDefinitionsIntoConfig(array $definitions): void
    {
        if (!Schema::hasTable('app_settings')) {
            return;
        }

        $stored = Cache::rememberForever(self::SETTINGS_CACHE_KEY, function () {
            return AppSetting::query()->pluck('value', 'key')->all();
        });

        foreach ($definitions as $key => $definition) {
            if (!array_key_exists($key, $stored)) {
                if (array_key_exists('default', $definition)) {
                    config([$key => $definition['default']]);
                }
                continue;
            }

            config([$key => $this->castValue($stored[$key], $definition['type'])]);
        }
    }

    private function updateSettings(array $validated, array $definitions, string $prefix): void
    {
        $normalized = $this->normalizeSettingsPayload($validated, $prefix);

        foreach ($definitions as $key => $definition) {
            if (!array_key_exists($key, $normalized)) {
                continue;
            }

            $raw = $normalized[$key];
            AppSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => (string) $raw]
            );

            config([$key => $this->castValue($raw, $definition['type'])]);
        }

        Cache::forget(self::SETTINGS_CACHE_KEY);
        Cache::forget(self::PUBLIC_APP_CONFIG_CACHE_KEY);
    }

    private function normalizeIntegerListString(mixed $value): string
    {
        return implode(',', $this->parseIntegerListConfig($value, []));
    }

    private function parseIntegerListConfig(mixed $value, array $fallback): array
    {
        $parts = preg_split('/\s*,\s*/', trim((string) $value)) ?: [];
        $numbers = collect($parts)
            ->map(fn ($part) => (int) $part)
            ->filter(fn (int $number) => $number > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return empty($numbers) ? $fallback : $numbers;
    }
}
