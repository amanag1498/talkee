<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\AppSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAppSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    protected function tearDown(): void
    {
        putenv('APP_PREMIUM_THEME_VARIANT');
        unset($_ENV['APP_PREMIUM_THEME_VARIANT'], $_SERVER['APP_PREMIUM_THEME_VARIANT']);

        parent::tearDown();
    }

    public function test_admin_can_view_app_settings_page(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.settings.app.edit'))
            ->assertOk()
            ->assertSee('App Settings')
            ->assertSee('Maintenance Mode')
            ->assertSee('Premium Theme Variant')
            ->assertSee('Android Feature Flags');
    }

    public function test_admin_can_update_app_settings(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $payload = [
            'app_features' => [
                'enable_premium_theme_variants' => 1,
                'premium_theme_variant' => 'aurora',
                'maintenance_mode_enabled' => 0,
                'force_app_upgrade_enabled' => 1,
                'platform' => [
                    'android' => [
                        'audio_rooms_enabled' => 1,
                        'video_rooms_enabled' => 1,
                        'pk_battles_enabled' => 0,
                        'gifts_enabled' => 1,
                        'subscriptions_enabled' => 1,
                        'entry_effects_enabled' => 1,
                        'wallet_recharge_enabled' => 1,
                        'host_calling_enabled' => 0,
                    ],
                ],
            ],
        ];

        $this->actingAs($admin)
            ->put(route('admin.settings.app.update'), $payload)
            ->assertRedirect(route('admin.settings.app.edit'));

        $this->assertDatabaseHas('app_settings', [
            'key' => 'app_features.enable_premium_theme_variants',
            'value' => '1',
        ]);
        $this->assertDatabaseHas('app_settings', [
            'key' => 'app_features.premium_theme_variant',
            'value' => 'aurora',
        ]);
        $this->assertDatabaseHas('app_settings', [
            'key' => 'app_features.force_app_upgrade_enabled',
            'value' => '1',
        ]);
        $this->assertDatabaseHas('app_settings', [
            'key' => 'app_features.platform.android.pk_battles_enabled',
            'value' => '0',
        ]);
    }

    public function test_app_settings_are_loaded_into_config_from_database(): void
    {
        AppSetting::query()->create([
            'key' => 'app_features.maintenance_mode_enabled',
            'value' => '1',
        ]);
        AppSetting::query()->create([
            'key' => 'app_features.platform.android.audio_rooms_enabled',
            'value' => '0',
        ]);
        AppSetting::query()->create([
            'key' => 'app_features.premium_theme_variant',
            'value' => 'gold',
        ]);

        app(AppSettingsService::class)->loadAppSettingsIntoConfig();

        $this->assertTrue(config('app_features.maintenance_mode_enabled'));
        $this->assertFalse(config('app_features.platform.android.audio_rooms_enabled'));
        $this->assertSame('gold', config('app_features.premium_theme_variant'));
    }

    public function test_public_app_settings_endpoint_returns_flags(): void
    {
        AppSetting::query()->create([
            'key' => 'app_features.force_app_upgrade_enabled',
            'value' => '1',
        ]);
        AppSetting::query()->create([
            'key' => 'app_features.premium_theme_variant',
            'value' => 'midnight',
        ]);

        app(AppSettingsService::class)->loadAppSettingsIntoConfig();

        $this->getJson('/api/app-config')
            ->assertOk()
            ->assertJsonPath('data.force_app_upgrade_enabled', true)
            ->assertJsonPath('data.premium_theme_variant', 'midnight')
            ->assertJsonStructure([
                'ok',
                'data' => [
                    'enable_premium_theme_variants',
                    'premium_theme_variant',
                    'maintenance_mode_enabled',
                    'force_app_upgrade_enabled',
                    'android_min_version_code',
                    'android_min_version_name',
                    'android_update_message',
                    'features' => [
                        'audio_rooms_enabled',
                        'video_rooms_enabled',
                        'pk_battles_enabled',
                        'gifts_enabled',
                        'subscriptions_enabled',
                        'entry_effects_enabled',
                        'wallet_recharge_enabled',
                        'host_calling_enabled',
                    ],
                ],
            ]);
    }

    public function test_premium_theme_variant_uses_env_fallback_when_db_value_is_missing(): void
    {
        app()->useEnvironmentPath(base_path());
        putenv('APP_PREMIUM_THEME_VARIANT=aurora');
        $_ENV['APP_PREMIUM_THEME_VARIANT'] = 'aurora';
        $_SERVER['APP_PREMIUM_THEME_VARIANT'] = 'aurora';

        app(AppSettingsService::class)->loadAppSettingsIntoConfig();

        $this->getJson('/api/app-config')
            ->assertOk()
            ->assertJsonPath('data.premium_theme_variant', 'aurora');
    }

    public function test_premium_theme_variant_falls_back_to_midnight_when_env_is_invalid(): void
    {
        app()->useEnvironmentPath(base_path());
        putenv('APP_PREMIUM_THEME_VARIANT=neon');
        $_ENV['APP_PREMIUM_THEME_VARIANT'] = 'neon';
        $_SERVER['APP_PREMIUM_THEME_VARIANT'] = 'neon';

        app(AppSettingsService::class)->loadAppSettingsIntoConfig();

        $this->getJson('/api/app-config')
            ->assertOk()
            ->assertJsonPath('data.premium_theme_variant', 'midnight');
    }

    public function test_invalid_premium_theme_variant_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $payload = [
            'app_features' => [
                'enable_premium_theme_variants' => 1,
                'premium_theme_variant' => 'neon',
                'maintenance_mode_enabled' => 0,
                'force_app_upgrade_enabled' => 0,
                'platform' => [
                    'android' => [
                        'audio_rooms_enabled' => 1,
                        'video_rooms_enabled' => 1,
                        'pk_battles_enabled' => 1,
                        'gifts_enabled' => 1,
                        'subscriptions_enabled' => 1,
                        'entry_effects_enabled' => 1,
                        'wallet_recharge_enabled' => 1,
                        'host_calling_enabled' => 1,
                    ],
                ],
            ],
        ];

        $this->actingAs($admin)
            ->from(route('admin.settings.app.edit'))
            ->put(route('admin.settings.app.update'), $payload)
            ->assertRedirect(route('admin.settings.app.edit'))
            ->assertSessionHasErrors('app_features.premium_theme_variant');
    }
}
