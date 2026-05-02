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
        return (string) env('ANDROID_UPDATE_MESSAGE', 'Please update Talkee to continue using the app.');
    }

    private function castValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            'float' => (float) $value,
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
}
