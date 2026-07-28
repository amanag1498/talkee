<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\AppSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminLiveRoomSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_admin_can_view_live_room_settings_page_with_pk_duration(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.settings.live-rooms.edit'))
            ->assertOk()
            ->assertSee('Live Room Settings')
            ->assertSee('Automatically Approve Video Speaker Requests')
            ->assertSee('Automatically Approve Audio Speaker Requests')
            ->assertSee('PK Battle Duration Seconds');
    }

    public function test_admin_can_update_live_room_pk_duration_setting(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $payload = [
            'live_rooms' => [
                'video' => [
                    'max_participants' => 12,
                    'max_speakers' => 4,
                ],
                'audio' => [
                    'max_participants' => 50,
                    'max_speakers' => 8,
                ],
                'speaker_requests' => [
                    'video_auto_approve' => true,
                    'audio_auto_approve' => 0,
                ],
                'pk' => [
                    'default_duration_seconds' => 180,
                ],
            ],
        ];

        $this->actingAs($admin)
            ->put(route('admin.settings.live-rooms.update'), $payload)
            ->assertRedirect(route('admin.settings.live-rooms.edit'));

        $this->assertDatabaseHas('app_settings', [
            'key' => 'live_rooms.pk.default_duration_seconds',
            'value' => '180',
        ]);
        $this->assertDatabaseHas('app_settings', [
            'key' => 'live_rooms.speaker_requests.video_auto_approve',
            'value' => '1',
        ]);
        $this->assertDatabaseHas('app_settings', [
            'key' => 'live_rooms.speaker_requests.audio_auto_approve',
            'value' => '0',
        ]);
        $this->assertTrue(config('live_rooms.speaker_requests.video_auto_approve'));
        $this->assertFalse(config('live_rooms.speaker_requests.audio_auto_approve'));
    }

    public function test_live_room_settings_are_loaded_into_config_from_database(): void
    {
        AppSetting::query()->create([
            'key' => 'live_rooms.pk.default_duration_seconds',
            'value' => '240',
        ]);
        AppSetting::query()->create([
            'key' => 'live_rooms.speaker_requests.video_auto_approve',
            'value' => '1',
        ]);
        AppSetting::query()->create([
            'key' => 'live_rooms.speaker_requests.audio_auto_approve',
            'value' => '0',
        ]);

        app(AppSettingsService::class)->loadLiveRoomSettingsIntoConfig();

        $this->assertSame(240, config('live_rooms.pk.default_duration_seconds'));
        $this->assertTrue(config('live_rooms.speaker_requests.video_auto_approve'));
        $this->assertFalse(config('live_rooms.speaker_requests.audio_auto_approve'));
    }
}
