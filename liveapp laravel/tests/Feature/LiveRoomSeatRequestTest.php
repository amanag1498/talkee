<?php

namespace Tests\Feature;

use App\Models\Host;
use App\Models\LiveRoom;
use App\Models\LiveRoomParticipant;
use App\Models\LiveRoomSeatRequest;
use App\Models\User;
use App\Services\LiveKitRoomAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LiveRoomSeatRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'super-admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturn(1);
        Redis::shouldReceive('smembers')->zeroOrMoreTimes()->andReturn([]);
        Redis::shouldReceive('get')->zeroOrMoreTimes()->andReturn(null);
        Redis::shouldReceive('set')->zeroOrMoreTimes()->andReturn(true);
        Redis::shouldReceive('sadd')->zeroOrMoreTimes()->andReturn(1);
        Redis::shouldReceive('srem')->zeroOrMoreTimes()->andReturn(1);

        $this->app->instance(LiveKitRoomAdminService::class, new class extends LiveKitRoomAdminService {
            public bool $shouldFail = false;
            public array $calls = [];
            public function __construct() {}
            public function setParticipantCanPublish(string $roomId, string $identity, bool $canPublish, ?array $publishSources = null): void
            {
                $this->calls[] = compact('roomId', 'identity', 'canPublish', 'publishSources');
                if ($this->shouldFail) {
                    throw new \RuntimeException('forced failure');
                }
            }
        });
    }

    public function test_duplicate_request_returns_same_pending_row(): void
    {
        [, $room] = $this->makeLiveRoom();
        $viewer = $this->makeViewerParticipant($room);

        Sanctum::actingAs($viewer);

        $first = $this->postJson("/api/live/rooms/{$room->room_id}/seat-requests")->assertOk();
        $second = $this->postJson("/api/live/rooms/{$room->room_id}/seat-requests")->assertOk();

        $this->assertSame($first->json('request_id'), $second->json('request_id'));
        $this->assertSame(1, LiveRoomSeatRequest::query()->where('live_room_id', $room->id)->where('user_id', $viewer->id)->where('status', 'pending')->count());
    }

    public function test_accept_twice_is_idempotent(): void
    {
        [$hostUser, $room] = $this->makeLiveRoom();
        $viewer = $this->makeViewerParticipant($room);
        $seatRequest = LiveRoomSeatRequest::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        Sanctum::actingAs($hostUser);

        $this->postJson("/api/live/rooms/{$room->room_id}/seat-requests/{$seatRequest->id}/accept")->assertOk();
        $this->postJson("/api/live/rooms/{$room->room_id}/seat-requests/{$seatRequest->id}/accept")->assertOk();

        $this->assertDatabaseHas('live_room_seat_requests', ['id' => $seatRequest->id, 'status' => 'accepted']);
        $this->assertDatabaseHas('live_room_participants', ['live_room_id' => $room->id, 'user_id' => $viewer->id, 'role' => 'speaker']);
    }

    public function test_reject_twice_is_idempotent(): void
    {
        [$hostUser, $room] = $this->makeLiveRoom();
        $viewer = $this->makeViewerParticipant($room);
        $seatRequest = LiveRoomSeatRequest::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        Sanctum::actingAs($hostUser);

        $this->postJson("/api/live/rooms/{$room->room_id}/seat-requests/{$seatRequest->id}/reject")->assertOk();
        $this->postJson("/api/live/rooms/{$room->room_id}/seat-requests/{$seatRequest->id}/reject")->assertOk();

        $this->assertDatabaseHas('live_room_seat_requests', ['id' => $seatRequest->id, 'status' => 'rejected']);
    }

    public function test_accept_then_reject_invalid_transition_is_blocked(): void
    {
        [$hostUser, $room] = $this->makeLiveRoom();
        $viewer = $this->makeViewerParticipant($room);
        $seatRequest = LiveRoomSeatRequest::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        Sanctum::actingAs($hostUser);

        $this->postJson("/api/live/rooms/{$room->room_id}/seat-requests/{$seatRequest->id}/accept")->assertOk();
        $this->postJson("/api/live/rooms/{$room->room_id}/seat-requests/{$seatRequest->id}/reject")->assertStatus(409);
    }

    public function test_remove_speaker_twice_is_idempotent(): void
    {
        [$hostUser, $room] = $this->makeLiveRoom();
        $viewer = $this->makeViewerParticipant($room, role: 'speaker');
        $seatRequest = LiveRoomSeatRequest::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'status' => 'accepted',
            'requested_at' => now()->subMinute(),
            'responded_at' => now()->subSeconds(30),
            'responded_by' => $hostUser->id,
        ]);

        Sanctum::actingAs($hostUser);

        $this->postJson("/api/live/rooms/{$room->room_id}/speakers/{$viewer->id}/remove")->assertOk();
        $this->postJson("/api/live/rooms/{$room->room_id}/speakers/{$viewer->id}/remove")->assertOk();

        $this->assertDatabaseHas('live_room_participants', ['live_room_id' => $room->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $this->assertDatabaseHas('live_room_seat_requests', ['id' => $seatRequest->id, 'status' => 'removed']);
    }

    public function test_viewer_leave_cancels_pending_request(): void
    {
        [, $room] = $this->makeLiveRoom();
        $viewer = $this->makeViewerParticipant($room);
        $seatRequest = LiveRoomSeatRequest::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        Sanctum::actingAs($viewer);

        $this->postJson("/api/live/rooms/{$room->room_id}/leave", ['session_id' => 'sess-a'])->assertOk();

        $this->assertDatabaseHas('live_room_seat_requests', ['id' => $seatRequest->id, 'status' => 'cancelled']);
    }

    public function test_host_end_cancels_pending_requests_and_removes_speakers(): void
    {
        [$hostUser, $room] = $this->makeLiveRoom();
        $pendingViewer = $this->makeViewerParticipant($room);
        $speakerViewer = $this->makeViewerParticipant($room, role: 'speaker', suffix: 'speaker');

        LiveRoomSeatRequest::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $pendingViewer->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        LiveRoomSeatRequest::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $speakerViewer->id,
            'status' => 'accepted',
            'requested_at' => now()->subMinute(),
            'responded_at' => now()->subSeconds(20),
            'responded_by' => $hostUser->id,
        ]);

        Sanctum::actingAs($hostUser);

        $this->postJson("/api/live/rooms/{$room->room_id}/end")->assertOk();

        $this->assertDatabaseHas('live_rooms', ['id' => $room->id, 'status' => 'ended', 'end_reason' => 'host_ended']);
        $this->assertDatabaseHas('live_room_seat_requests', ['live_room_id' => $room->id, 'user_id' => $pendingViewer->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('live_room_seat_requests', ['live_room_id' => $room->id, 'user_id' => $speakerViewer->id, 'status' => 'removed']);
    }

    public function test_livekit_failure_rolls_back_accept_transition(): void
    {
        [$hostUser, $room] = $this->makeLiveRoom();
        $viewer = $this->makeViewerParticipant($room);
        $seatRequest = LiveRoomSeatRequest::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $fake = $this->app->make(LiveKitRoomAdminService::class);
        $fake->shouldFail = true;

        Sanctum::actingAs($hostUser);

        $this->postJson("/api/live/rooms/{$room->room_id}/seat-requests/{$seatRequest->id}/accept")->assertStatus(502);

        $this->assertDatabaseHas('live_room_seat_requests', ['id' => $seatRequest->id, 'status' => 'pending']);
        $this->assertDatabaseHas('live_room_participants', ['live_room_id' => $room->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
    }

    public function test_video_auto_approval_promotes_video_viewer_without_enabling_audio_auto_approval(): void
    {
        config([
            'live_rooms.speaker_requests.video_auto_approve' => true,
            'live_rooms.speaker_requests.audio_auto_approve' => false,
        ]);
        [, $room] = $this->makeLiveRoom(roomType: 'video');
        $viewer = $this->makeViewerParticipant($room);

        Sanctum::actingAs($viewer);

        $response = $this->postJson("/api/live/rooms/{$room->room_id}/seat-requests")
            ->assertOk()
            ->assertJsonPath('snapshot.speaker_request_approval_mode', 'automatic')
            ->assertJsonPath('snapshot.requests.0.status', 'accepted')
            ->assertJsonPath('snapshot.requests.0.responded_by', null);

        $requestId = (int) $response->json('request_id');
        $this->assertDatabaseHas('live_room_seat_requests', [
            'id' => $requestId,
            'status' => 'accepted',
            'responded_by' => null,
        ]);
        $this->assertDatabaseHas('live_room_participants', [
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'role' => 'speaker',
        ]);

        $fake = $this->app->make(LiveKitRoomAdminService::class);
        $this->assertSame(['camera', 'microphone'], $fake->calls[0]['publishSources']);

        [, $audioRoom] = $this->makeLiveRoom(roomType: 'audio');
        $listener = $this->makeViewerParticipant($audioRoom, role: 'listener', suffix: 'audio-listener');
        Sanctum::actingAs($listener);

        $this->postJson("/api/live/rooms/{$audioRoom->room_id}/seat-requests")
            ->assertOk()
            ->assertJsonPath('snapshot.speaker_request_approval_mode', 'host')
            ->assertJsonPath('snapshot.requests.0.status', 'pending');

        $this->assertDatabaseHas('live_room_participants', [
            'live_room_id' => $audioRoom->id,
            'user_id' => $listener->id,
            'role' => 'listener',
        ]);
    }

    public function test_auto_approval_promotes_audio_listener_with_microphone_only(): void
    {
        config([
            'live_rooms.speaker_requests.video_auto_approve' => false,
            'live_rooms.speaker_requests.audio_auto_approve' => true,
        ]);
        [, $room] = $this->makeLiveRoom(roomType: 'audio');
        $listener = $this->makeViewerParticipant($room, role: 'listener');

        Sanctum::actingAs($listener);

        $this->postJson("/api/live/rooms/{$room->room_id}/seat-requests")
            ->assertOk()
            ->assertJsonPath('snapshot.speaker_request_approval_mode', 'automatic')
            ->assertJsonPath('snapshot.requests.0.status', 'accepted');

        $this->assertDatabaseHas('live_room_participants', [
            'live_room_id' => $room->id,
            'user_id' => $listener->id,
            'role' => 'speaker',
        ]);

        $fake = $this->app->make(LiveKitRoomAdminService::class);
        $this->assertSame(['microphone'], $fake->calls[0]['publishSources']);
    }

    public function test_host_approval_remains_the_default_policy(): void
    {
        config([
            'live_rooms.speaker_requests.video_auto_approve' => false,
            'live_rooms.speaker_requests.audio_auto_approve' => false,
        ]);
        [, $room] = $this->makeLiveRoom();
        $viewer = $this->makeViewerParticipant($room);

        Sanctum::actingAs($viewer);

        $this->postJson("/api/live/rooms/{$room->room_id}/seat-requests")
            ->assertOk()
            ->assertJsonPath('snapshot.speaker_request_approval_mode', 'host')
            ->assertJsonPath('snapshot.requests.0.status', 'pending');

        $this->assertDatabaseHas('live_room_participants', [
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
        ]);
    }

    private function makeLiveRoom(string $status = 'live', string $roomType = 'video'): array
    {
        $hostUser = User::factory()->create();
        $hostUser->assignRole('host');

        $host = Host::query()->create([
            'user_id' => $hostUser->id,
            'stage_name' => 'Room Host',
        ]);

        $room = LiveRoom::query()->create([
            'host_id' => $host->id,
            'room_id' => 'room-'.$host->id.'-'.$status,
            'title' => 'Live Room',
            'room_type' => $roomType,
            'status' => $status,
            'started_at' => now()->subMinutes(5),
            'last_activity_at' => now()->subMinute(),
            'peak_viewers' => 3,
            'meta' => ['max_speakers' => 4],
        ]);

        LiveRoomParticipant::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $hostUser->id,
            'role' => 'host',
            'joined_at' => now()->subMinutes(5),
        ]);

        return [$hostUser, $room];
    }

    private function makeViewerParticipant(LiveRoom $room, string $role = 'viewer', string $suffix = 'viewer'): User
    {
        $viewer = User::factory()->create(['email' => "{$suffix}{$room->id}".uniqid().'@example.com']);
        $viewer->assignRole('user');

        LiveRoomParticipant::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'role' => $role,
            'joined_at' => now()->subMinute(),
        ]);

        return $viewer;
    }
}
