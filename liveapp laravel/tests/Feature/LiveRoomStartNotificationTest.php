<?php

namespace Tests\Feature;

use App\Models\Host;
use App\Models\HostFollower;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LiveRoomStartNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        config([
            'services.livekit.api_key' => 'test-key',
            'services.livekit.api_secret' => 'test-secret',
            'app_features.platform.android.audio_rooms_enabled' => true,
            'app_features.platform.android.video_rooms_enabled' => true,
        ]);
    }

    public function test_immediate_video_room_start_notifies_followers(): void
    {
        [$hostUser, $host, $viewer] = $this->hostWithFollower();
        Sanctum::actingAs($hostUser);

        $this->postJson('/api/live/rooms', [
            'title' => 'Friday Power Live',
            'room_type' => 'video',
            'start_now' => true,
        ])->assertCreated();

        $notification = UserNotification::query()
            ->where('user_id', $viewer->id)
            ->where('type', 'host_live_started')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('Stage Live started a video room.', $notification->body);
        $this->assertSame('room', data_get($notification->meta, 'screen'));
        $this->assertSame('video', data_get($notification->meta, 'room_type'));
    }

    public function test_immediate_audio_room_start_notifies_followers(): void
    {
        [$hostUser, , $viewer] = $this->hostWithFollower();
        Sanctum::actingAs($hostUser);

        $this->postJson('/api/live/audio-rooms', [
            'title' => 'Late Night Audio',
            'start_now' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $viewer->id,
            'type' => 'host_live_started',
        ]);

        $this->assertSame('audio', data_get(UserNotification::query()->first()?->meta, 'room_type'));
        $this->assertSame('Stage Live started an audio room.', UserNotification::query()->first()?->body);
    }

    public function test_live_room_start_respects_follower_online_notification_preference(): void
    {
        [$hostUser, $host, $viewer] = $this->hostWithFollower(notifyWhenOnline: false);
        Sanctum::actingAs($hostUser);

        $this->postJson('/api/live/rooms', [
            'title' => 'Quiet Start',
            'room_type' => 'video',
            'start_now' => true,
        ])->assertCreated();

        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $viewer->id,
            'type' => 'host_live_started',
        ]);
    }

    private function hostWithFollower(bool $notifyWhenOnline = true): array
    {
        $hostUser = User::factory()->create(['name' => 'Live Host']);
        $hostUser->assignRole('host');

        $host = Host::query()->create([
            'user_id' => $hostUser->id,
            'stage_name' => 'Stage Live',
        ]);

        $viewer = User::factory()->create();
        $viewer->assignRole('user');

        HostFollower::query()->create([
            'host_id' => $host->id,
            'user_id' => $viewer->id,
            'notify_when_online' => $notifyWhenOnline,
        ]);

        return [$hostUser, $host, $viewer];
    }
}
