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
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LiveRoomSpeakerFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturn(1);
    }

    public function test_duplicate_speaker_request_returns_existing_pending_request(): void
    {
        [$hostUser, $room] = $this->makeLiveRoom();
        $viewer = $this->makeViewerInRoom($room);

        Sanctum::actingAs($viewer);

        $first = $this->postJson("/api/live/rooms/{$room->room_id}/seat-requests")->assertOk();
        $second = $this->postJson("/api/live/rooms/{$room->room_id}/seat-requests")->assertOk();

        $this->assertSame($first->json('request_id'), $second->json('request_id'));
        $this->assertSame(1, LiveRoomSeatRequest::query()
            ->where('live_room_id', $room->id)
            ->where('user_id', $viewer->id)
            ->where('status', 'pending')
            ->count());
    }

    public function test_accept_is_idempotent_for_same_request(): void
    {
        [$hostUser, $room] = $this->makeLiveRoom(maxSpeakers: 4);
        $viewer = $this->makeViewerInRoom($room);
        $request = LiveRoomSeatRequest::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $this->mock(LiveKitRoomAdminService::class, function ($mock) {
            $mock->shouldReceive('setParticipantCanPublish')->twice()->andReturnNull();
        });

        Sanctum::actingAs($hostUser);

        $this->postJson("/api/live/rooms/{$room->room_id}/seat-requests/{$request->id}/accept")->assertOk();
        $this->postJson("/api/live/rooms/{$room->room_id}/seat-requests/{$request->id}/accept")->assertOk();

        $this->assertDatabaseHas('live_room_seat_requests', [
            'id' => $request->id,
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('live_room_participants', [
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'role' => 'speaker',
            'left_at' => null,
        ]);
    }

    public function test_accept_enforces_max_speakers_limit(): void
    {
        [$hostUser, $room] = $this->makeLiveRoom(maxSpeakers: 1);

        $existingSpeaker = $this->makeViewerInRoom($room);
        LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->where('user_id', $existingSpeaker->id)
            ->update(['role' => 'speaker']);
        LiveRoomSeatRequest::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $existingSpeaker->id,
            'status' => 'accepted',
            'requested_at' => now()->subMinute(),
            'responded_at' => now()->subMinute(),
            'responded_by' => $hostUser->id,
        ]);

        $viewer = $this->makeViewerInRoom($room);
        $request = LiveRoomSeatRequest::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        Sanctum::actingAs($hostUser);

        $this->postJson("/api/live/rooms/{$room->room_id}/seat-requests/{$request->id}/accept")
            ->assertStatus(409);

        $this->assertDatabaseHas('live_room_seat_requests', [
            'id' => $request->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('live_room_participants', [
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'left_at' => null,
        ]);
    }

    public function test_remove_speaker_is_idempotent(): void
    {
        [$hostUser, $room] = $this->makeLiveRoom(maxSpeakers: 4);
        $viewer = $this->makeViewerInRoom($room);
        LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->where('user_id', $viewer->id)
            ->update(['role' => 'speaker']);
        LiveRoomSeatRequest::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'status' => 'accepted',
            'requested_at' => now()->subMinute(),
            'responded_at' => now()->subMinute(),
            'responded_by' => $hostUser->id,
        ]);

        $this->mock(LiveKitRoomAdminService::class, function ($mock) {
            $mock->shouldReceive('setParticipantCanPublish')->twice()->andReturnNull();
        });

        Sanctum::actingAs($hostUser);

        $this->postJson("/api/live/rooms/{$room->room_id}/speakers/{$viewer->id}/remove")->assertOk();
        $this->postJson("/api/live/rooms/{$room->room_id}/speakers/{$viewer->id}/remove")->assertOk();

        $this->assertDatabaseHas('live_room_participants', [
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'left_at' => null,
        ]);
        $this->assertDatabaseHas('live_room_seat_requests', [
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'status' => 'removed',
        ]);
    }

    public function test_leave_cancels_pending_request(): void
    {
        [, $room] = $this->makeLiveRoom();
        $viewer = $this->makeViewerInRoom($room);
        $request = LiveRoomSeatRequest::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        Sanctum::actingAs($viewer);

        $this->postJson("/api/live/rooms/{$room->room_id}/leave", [
            'session_id' => 'sess-'.$viewer->id,
        ])->assertOk();

        $this->assertDatabaseHas('live_room_seat_requests', [
            'id' => $request->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_livekit_failure_rolls_back_accept_transition(): void
    {
        [$hostUser, $room] = $this->makeLiveRoom();
        $viewer = $this->makeViewerInRoom($room);
        $request = LiveRoomSeatRequest::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $this->mock(LiveKitRoomAdminService::class, function ($mock) {
            $mock->shouldReceive('setParticipantCanPublish')
                ->once()
                ->andThrow(new \RuntimeException('livekit failed'));
        });

        Sanctum::actingAs($hostUser);

        $this->postJson("/api/live/rooms/{$room->room_id}/seat-requests/{$request->id}/accept")
            ->assertStatus(502);

        $this->assertDatabaseHas('live_room_seat_requests', [
            'id' => $request->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('live_room_participants', [
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'left_at' => null,
        ]);
    }

    private function makeLiveRoom(int $maxSpeakers = 4): array
    {
        $hostUser = User::factory()->create();
        $hostUser->assignRole('host');

        $host = Host::query()->create([
            'user_id' => $hostUser->id,
            'stage_name' => 'Room Host',
        ]);

        $room = LiveRoom::query()->create([
            'host_id' => $host->id,
            'room_id' => 'room-'.$host->id.'-speaker',
            'title' => 'Speaker Room',
            'status' => 'live',
            'started_at' => now()->subMinutes(5),
            'last_activity_at' => now()->subMinute(),
            'peak_viewers' => 1,
            'max_speakers' => $maxSpeakers,
        ]);

        LiveRoomParticipant::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $hostUser->id,
            'role' => 'host',
            'joined_at' => now()->subMinutes(5),
        ]);

        return [$hostUser, $room];
    }

    private function makeViewerInRoom(LiveRoom $room): User
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('user');

        LiveRoomParticipant::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'session_id' => 'sess-'.$viewer->id,
            'role' => 'viewer',
            'joined_at' => now()->subMinute(),
        ]);

        return $viewer;
    }
}
