<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AppFeatureFlagEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $member;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->member = User::factory()->create();
        $this->member->assignRole('host');

        config([
            'app_features.maintenance_mode_enabled' => false,
            'app_features.force_app_upgrade_enabled' => false,
            'app_features.platform.android.audio_rooms_enabled' => true,
            'app_features.platform.android.video_rooms_enabled' => true,
            'app_features.platform.android.pk_battles_enabled' => true,
            'app_features.platform.android.gifts_enabled' => true,
            'app_features.platform.android.subscriptions_enabled' => true,
            'app_features.platform.android.entry_effects_enabled' => true,
            'app_features.platform.android.wallet_recharge_enabled' => true,
            'app_features.platform.android.host_calling_enabled' => true,
        ]);
    }

    public function test_non_admin_cannot_access_app_settings_dashboard(): void
    {
        $this->actingAs($this->member)
            ->get(route('admin.settings.app.edit'))
            ->assertForbidden();
    }

    public function test_maintenance_mode_blocks_member_apis_but_keeps_admin_web_and_app_config_available(): void
    {
        config(['app_features.maintenance_mode_enabled' => true]);

        Sanctum::actingAs($this->member);
        $this->withHeaders($this->androidHeaders())
            ->getJson('/api/profile')
            ->assertStatus(503)
            ->assertJsonPath('error', 'MAINTENANCE_MODE');

        $this->getJson('/api/app-config')
            ->assertOk()
            ->assertJsonPath('data.maintenance_mode_enabled', true);

        $this->actingAs($this->admin)
            ->get(route('admin.settings.app.edit'))
            ->assertOk();
    }

    public function test_force_upgrade_rejects_old_android_client_headers(): void
    {
        config(['app_features.force_app_upgrade_enabled' => true]);
        putenv('ANDROID_MIN_VERSION_CODE=5');

        Sanctum::actingAs($this->member);

        $this->withHeaders($this->androidHeaders(versionCode: 4))
            ->getJson('/api/profile')
            ->assertStatus(426)
            ->assertJsonPath('error', 'APP_UPGRADE_REQUIRED');

        $this->withHeaders($this->androidHeaders(versionCode: 5))
            ->getJson('/api/profile')
            ->assertOk();
    }

    public function test_disabled_feature_apis_are_rejected(): void
    {
        Sanctum::actingAs($this->member);

        config(['app_features.platform.android.audio_rooms_enabled' => false]);
        $this->withHeaders($this->androidHeaders())
            ->postJson('/api/live/audio-rooms', ['title' => 'Audio'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FEATURE_DISABLED');

        config(['app_features.platform.android.video_rooms_enabled' => false]);
        $this->withHeaders($this->androidHeaders())
            ->postJson('/api/live/rooms', ['title' => 'Video'])
            ->assertStatus(403)
            ->assertJsonPath('feature', 'video_rooms_enabled');

        config(['app_features.platform.android.gifts_enabled' => false]);
        $this->withHeaders($this->androidHeaders())
            ->getJson('/api/gifts')
            ->assertStatus(403)
            ->assertJsonPath('feature', 'gifts_enabled');

        config(['app_features.platform.android.subscriptions_enabled' => false]);
        $this->withHeaders($this->androidHeaders())
            ->getJson('/api/plans')
            ->assertStatus(403)
            ->assertJsonPath('feature', 'subscriptions_enabled');

        config(['app_features.platform.android.entry_effects_enabled' => false]);
        $this->withHeaders($this->androidHeaders())
            ->getJson('/api/entry-packs')
            ->assertStatus(403)
            ->assertJsonPath('feature', 'entry_effects_enabled');

        config(['app_features.platform.android.wallet_recharge_enabled' => false]);
        $this->withHeaders($this->androidHeaders())
            ->getJson('/api/recharge/plans')
            ->assertStatus(403)
            ->assertJsonPath('feature', 'wallet_recharge_enabled');

        config(['app_features.platform.android.host_calling_enabled' => false]);
        $this->withHeaders($this->androidHeaders())
            ->getJson('/api/live-users')
            ->assertStatus(403)
            ->assertJsonPath('feature', 'host_calling_enabled');

        config(['app_features.platform.android.pk_battles_enabled' => false]);
        $this->withHeaders($this->androidHeaders())
            ->getJson('/api/live/rooms/example-room/pk/active')
            ->assertStatus(403)
            ->assertJsonPath('feature', 'pk_battles_enabled');
    }

    public function test_invalid_app_settings_values_are_rejected(): void
    {
        $payload = [
            'app_features' => [
                'enable_premium_theme_variants' => 'not-a-bool',
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

        $this->actingAs($this->admin)
            ->put(route('admin.settings.app.update'), $payload)
            ->assertSessionHasErrors('app_features.enable_premium_theme_variants');
    }

    private function androidHeaders(int $versionCode = 10): array
    {
        return [
            'X-Client-Platform' => 'android',
            'X-App-Version' => '1.0.0',
            'X-App-Version-Code' => (string) $versionCode,
        ];
    }
}
