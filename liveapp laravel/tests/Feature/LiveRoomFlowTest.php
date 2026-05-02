<?php

namespace Tests\Feature;

use App\Models\Host;
use App\Models\LiveRoom;
use App\Models\LiveRoomParticipant;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LiveRoomFlowTest extends TestCase
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

    public function test_live_room_list_returns_db_backed_counts(): void
    {
        [$hostUser, $room] = $this->makeLiveRoom();
        $viewer = User::factory()->create();
        $viewer->assignRole('user');

        LiveRoomParticipant::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/live/rooms');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.room_id', $room->room_id)
            ->assertJsonPath('data.0.viewer_count', 1)
            ->assertJsonPath('data.0.participant_count', 2)
            ->assertJsonPath('data.0.host_id', $hostUser->id);
    }

    public function test_duplicate_join_reuses_existing_active_participant(): void
    {
        [, $room] = $this->makeLiveRoom();
        $viewer = User::factory()->create();
        $viewer->assignRole('user');
        $this->grantSubscription($viewer);
        Sanctum::actingAs($viewer);

        $first = $this->postJson("/api/live/rooms/{$room->room_id}/join", [
            'role' => 'viewer',
            'session_id' => 'sess-a',
        ])->assertOk();

        $second = $this->postJson("/api/live/rooms/{$room->room_id}/join", [
            'role' => 'viewer',
            'session_id' => 'sess-a',
        ])->assertOk();

        $this->assertSame(
            data_get($first->json(), 'participant_id'),
            data_get($second->json(), 'participant_id')
        );

        $this->assertSame(1, LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->where('user_id', $viewer->id)
            ->whereNull('left_at')
            ->count());
    }

    public function test_join_missing_room_returns_clean_room_not_found_error(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('user');
        $this->grantSubscription($viewer);
        Sanctum::actingAs($viewer);

        $this->postJson('/api/live/rooms/missing-room/join', [
            'role' => 'viewer',
            'session_id' => 'sess-a',
        ])->assertStatus(404)
            ->assertJsonPath('message', 'room_not_found');
    }

    public function test_join_ended_room_returns_clean_room_not_joinable_error(): void
    {
        [, $room] = $this->makeLiveRoom(status: 'ended');
        $viewer = User::factory()->create();
        $viewer->assignRole('user');
        $this->grantSubscription($viewer);
        Sanctum::actingAs($viewer);

        $this->postJson("/api/live/rooms/{$room->room_id}/join", [
            'role' => 'viewer',
            'session_id' => 'sess-a',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'room_not_joinable');
    }

    public function test_leave_is_idempotent_and_closes_all_duplicate_open_rows(): void
    {
        [, $room] = $this->makeLiveRoom();
        $viewer = User::factory()->create();
        $viewer->assignRole('user');

        foreach ([1, 2] as $offset) {
            LiveRoomParticipant::query()->create([
                'live_room_id' => $room->id,
                'user_id' => $viewer->id,
                'role' => 'viewer',
                'joined_at' => now()->subMinutes($offset),
            ]);
        }

        Sanctum::actingAs($viewer);

        $this->postJson("/api/live/rooms/{$room->room_id}/leave", [
            'session_id' => 'sess-a',
        ])->assertOk();

        $this->postJson("/api/live/rooms/{$room->room_id}/leave", [
            'session_id' => 'sess-a',
        ])->assertOk();

        $this->assertSame(0, LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->where('user_id', $viewer->id)
            ->whereNull('left_at')
            ->count());
    }

    public function test_host_end_flow_is_idempotent_and_closes_open_participants(): void
    {
        [$hostUser, $room] = $this->makeLiveRoom();
        $viewer = User::factory()->create();
        $viewer->assignRole('user');

        LiveRoomParticipant::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'joined_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($hostUser);

        $this->postJson("/api/live/rooms/{$room->room_id}/end")->assertOk();
        $this->postJson("/api/live/rooms/{$room->room_id}/end")->assertOk();

        $this->assertDatabaseHas('live_rooms', [
            'id' => $room->id,
            'status' => 'ended',
            'end_reason' => 'host_ended',
        ]);
        $this->assertSame(0, LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->whereNull('left_at')
            ->count());
    }

    public function test_cleanup_command_ends_live_room_without_active_host(): void
    {
        [$hostUser, $room] = $this->makeLiveRoom();

        LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->where('user_id', $hostUser->id)
            ->where('role', 'host')
            ->update([
                'left_at' => now()->subMinutes(3),
                'duration_seconds' => 120,
            ]);

        Artisan::call('live-rooms:cleanup', ['--stale-minutes' => 15]);

        $this->assertDatabaseHas('live_rooms', [
            'id' => $room->id,
            'status' => 'ended',
            'end_reason' => 'host_left',
        ]);
    }

    public function test_sync_redis_command_rebuilds_only_live_room_docs(): void
    {
        [, $liveRoom] = $this->makeLiveRoom();
        [, $endedRoom] = $this->makeLiveRoom(status: 'ended');

        Redis::shouldReceive('del')->once()->with('rooms:live')->andReturn(1);
        Redis::shouldReceive('sadd')->once()->with('rooms:live', $liveRoom->room_id)->andReturn(1);
        Redis::shouldReceive('set')->once()->withArgs(function ($key, $value) use ($liveRoom) {
            return $key === "rooms:room:{$liveRoom->room_id}" && str_contains($value, $liveRoom->room_id);
        })->andReturn(true);

        Artisan::call('live-rooms:sync-redis');

        $this->assertStringContainsString('Synced 1 live room(s) to Redis.', Artisan::output());
        $this->assertSame('ended', $endedRoom->status);
    }

    public function test_reconcile_command_detects_live_room_without_host_duplicate_participants_and_redis_mismatch(): void
    {
        [, $liveRoom] = $this->makeLiveRoom();
        LiveRoomParticipant::query()
            ->where('live_room_id', $liveRoom->id)
            ->where('role', 'host')
            ->delete();

        $viewer = User::factory()->create();
        foreach ([1, 2] as $offset) {
            LiveRoomParticipant::query()->create([
                'live_room_id' => $liveRoom->id,
                'user_id' => $viewer->id,
                'role' => 'viewer',
                'joined_at' => now()->subMinutes($offset),
            ]);
        }

        Redis::shouldReceive('smembers')->once()->with('rooms:live')->andReturn([]);

        $this->artisan('live-rooms:reconcile')
            ->expectsOutputToContain('live_room_without_host')
            ->expectsOutputToContain('duplicate_open_participants')
            ->assertExitCode(0);
    }

    private function makeLiveRoom(string $status = 'live'): array
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
            'status' => $status,
            'started_at' => $status === 'live' ? now()->subMinutes(5) : now()->subMinutes(10),
            'ended_at' => $status === 'ended' ? now()->subMinute() : null,
            'end_reason' => $status === 'ended' ? 'host_ended' : null,
            'last_activity_at' => now()->subMinute(),
            'peak_viewers' => 3,
        ]);

        if ($status === 'live') {
            LiveRoomParticipant::query()->create([
                'live_room_id' => $room->id,
                'user_id' => $hostUser->id,
                'role' => 'host',
                'joined_at' => now()->subMinutes(5),
            ]);
        }

        return [$hostUser, $room];
    }

    private function grantSubscription(User $user): void
    {
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Viewer Pass '.$user->id,
            'price_coins' => 100,
            'duration_days' => 30,
            'perks' => ['live_access' => true],
            'is_active' => true,
        ]);

        UserSubscription::query()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDays(10),
            'last_purchased_at' => now()->subMinute(),
            'meta' => ['source' => 'test'],
        ]);
    }
}
