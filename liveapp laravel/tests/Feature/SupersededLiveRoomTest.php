<?php

namespace Tests\Feature;

use App\Models\Host;
use App\Models\LiveRoom;
use App\Models\LiveRoomParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupersededLiveRoomTest extends TestCase
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

        Redis::shouldReceive('set')->zeroOrMoreTimes()->andReturn(true);
        Redis::shouldReceive('sadd')->zeroOrMoreTimes()->andReturn(1);
        Redis::shouldReceive('srem')->zeroOrMoreTimes()->andReturn(1);
        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturn(1);
    }

    public function test_starting_new_live_room_ends_previous_live_room_and_closes_participants(): void
    {
        [$hostUser, $host, $oldRoom] = $this->hostWithLiveRoom();
        $viewer = User::factory()->create();
        $viewer->assignRole('user');
        LiveRoomParticipant::query()->create([
            'live_room_id' => $oldRoom->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'joined_at' => now()->subMinutes(3),
        ]);

        Sanctum::actingAs($hostUser);

        $response = $this->postJson('/api/live/rooms', [
            'title' => 'Replacement Room',
            'room_type' => 'video',
            'start_now' => true,
        ])->assertCreated();

        $replacementId = $response->json('room.room_id');
        $this->assertNotSame($oldRoom->room_id, $replacementId);
        $this->assertDatabaseHas('live_rooms', [
            'id' => $oldRoom->id,
            'status' => 'ended',
            'end_reason' => 'host_restarted_room',
        ]);
        $this->assertDatabaseHas('live_rooms', [
            'host_id' => $host->id,
            'room_id' => $replacementId,
            'status' => 'live',
        ]);
        $this->assertSame(
            0,
            LiveRoomParticipant::query()
                ->where('live_room_id', $oldRoom->id)
                ->whereNull('left_at')
                ->count(),
        );
    }

    public function test_starting_existing_room_ends_other_live_room_for_same_host(): void
    {
        [$hostUser, $host, $oldRoom] = $this->hostWithLiveRoom();
        $scheduledRoom = LiveRoom::query()->create([
            'host_id' => $host->id,
            'room_id' => 'scheduled-room',
            'title' => 'Scheduled Room',
            'room_type' => 'video',
            'status' => 'scheduled',
            'scheduled_at' => now()->addHour(),
            'last_activity_at' => now(),
        ]);

        Sanctum::actingAs($hostUser);

        $this->postJson('/api/live/rooms', [
            'room_id' => $scheduledRoom->room_id,
        ])->assertOk()
            ->assertJsonPath('room.status', 'live');

        $this->assertDatabaseHas('live_rooms', [
            'id' => $oldRoom->id,
            'status' => 'ended',
            'end_reason' => 'host_restarted_room',
        ]);
        $this->assertDatabaseHas('live_rooms', [
            'id' => $scheduledRoom->id,
            'status' => 'live',
            'ended_at' => null,
        ]);
    }

    public function test_creating_scheduled_room_does_not_end_current_live_room(): void
    {
        [$hostUser, , $oldRoom] = $this->hostWithLiveRoom();
        Sanctum::actingAs($hostUser);

        $this->postJson('/api/live/rooms', [
            'title' => 'Tomorrow Room',
            'room_type' => 'video',
            'start_now' => false,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('room.status', 'scheduled');

        $this->assertDatabaseHas('live_rooms', [
            'id' => $oldRoom->id,
            'status' => 'live',
            'ended_at' => null,
        ]);
    }

    private function hostWithLiveRoom(): array
    {
        $hostUser = User::factory()->create();
        $hostUser->assignRole('host');
        $host = Host::query()->create([
            'user_id' => $hostUser->id,
            'stage_name' => 'Restarting Host',
        ]);
        $room = LiveRoom::query()->create([
            'host_id' => $host->id,
            'room_id' => 'old-live-room',
            'title' => 'Old Live Room',
            'room_type' => 'video',
            'status' => 'live',
            'started_at' => now()->subMinutes(10),
            'last_activity_at' => now()->subMinutes(5),
        ]);
        LiveRoomParticipant::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $hostUser->id,
            'role' => 'host',
            'joined_at' => now()->subMinutes(10),
        ]);

        return [$hostUser, $host, $room];
    }
}
