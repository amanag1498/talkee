<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\AppSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCallSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_admin_can_view_call_settings_page(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.settings.calls.edit'))
            ->assertOk()
            ->assertSee('Call Settings')
            ->assertSee('Audio Call Rate / min');
    }

    public function test_admin_can_update_call_settings(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $payload = [
            'calls' => [
                'audio_coin_rate_per_minute' => 21,
                'video_coin_rate_per_minute' => 55,
                'minimum_balance_to_start_call' => 30,
                'minimum_billable_minutes' => 2,
                'ringing_timeout_seconds' => 45,
                'host_share_percent' => 60,
                'agency_share_percent' => 10,
                'platform_share_percent' => 30,
            ],
        ];

        $this->actingAs($admin)
            ->put(route('admin.settings.calls.update'), $payload)
            ->assertRedirect(route('admin.settings.calls.edit'));

        $this->assertDatabaseHas('app_settings', [
            'key' => 'calls.audio_coin_rate_per_minute',
            'value' => '21',
        ]);
        $this->assertDatabaseHas('app_settings', [
            'key' => 'calls.video_coin_rate_per_minute',
            'value' => '55',
        ]);
    }

    public function test_call_settings_are_loaded_into_config_from_database(): void
    {
        AppSetting::query()->create([
            'key' => 'calls.audio_coin_rate_per_minute',
            'value' => '44',
        ]);
        AppSetting::query()->create([
            'key' => 'calls.video_coin_rate_per_minute',
            'value' => '88',
        ]);

        app(AppSettingsService::class)->loadCallSettingsIntoConfig();

        $this->assertSame(44, config('calls.audio_coin_rate_per_minute'));
        $this->assertSame(88, config('calls.video_coin_rate_per_minute'));
    }
}
