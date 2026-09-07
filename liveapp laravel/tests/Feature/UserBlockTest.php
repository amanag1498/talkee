<?php

namespace Tests\Feature;

use App\Models\CallSession;
use App\Models\Gift;
use App\Models\Host;
use App\Models\HostFollower;
use App\Models\LiveRoom;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\CallSessionService;
use App\Services\LiveRoomAccessService;
use App\Services\LiveRoomGiftService;
use App\Services\LiveRoomPkService;
use App\Services\UserBlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class UserBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Redis::shouldReceive('publish', 'set', 'del', 'sadd', 'srem')
            ->zeroOrMoreTimes()
            ->andReturn(1)
            ->byDefault();
    }

    public function test_user_can_block_list_and_unblock_another_user(): void
    {
        $blocker = User::factory()->create();
        $target = User::factory()->create(['name' => 'Blocked Viewer']);
        Sanctum::actingAs($blocker);

        $this->postJson("/api/me/blocked-users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.user_id', $target->id)
            ->assertJsonPath('data.name', 'Blocked Viewer');

        $this->postJson("/api/me/blocked-users/{$target->id}")->assertOk();
        $this->assertDatabaseCount('user_blocks', 1);
        $this->getJson('/api/me/blocked-users')
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $target->id);
        $this->getJson('/api/ws/verify')
            ->assertOk()
            ->assertJsonPath('blocked_user_ids.0', $target->id);

        $this->deleteJson("/api/me/blocked-users/{$target->id}")->assertOk();
        $this->deleteJson("/api/me/blocked-users/{$target->id}")->assertOk();
        $this->assertDatabaseCount('user_blocks', 0);
    }

    public function test_user_cannot_block_self(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/me/blocked-users/{$user->id}")
            ->assertStatus(422)
            ->assertJsonPath('msg', 'You cannot block yourself.');
    }

    public function test_blocking_a_host_removes_follow_without_creating_host_block(): void
    {
        $viewer = User::factory()->create();
        [$hostUser, $room] = $this->makeRoom('follow');
        HostFollower::query()->create([
            'host_id' => $room->host_id,
            'user_id' => $viewer->id,
        ]);
        Sanctum::actingAs($viewer);

        $this->postJson("/api/me/blocked-users/{$hostUser->id}")->assertOk();

        $this->assertDatabaseMissing('host_followers', [
            'host_id' => $room->host_id,
            'user_id' => $viewer->id,
        ]);
        $this->assertDatabaseCount('host_user_blocks', 0);
    }

    public function test_users_with_a_personal_block_cannot_follow_each_other(): void
    {
        $viewer = User::factory()->create();
        [$hostUser, $room] = $this->makeRoom('blocked-follow');
        app(UserBlockService::class)->block($viewer, $hostUser);
        Sanctum::actingAs($viewer);

        $this->postJson("/api/hosts/{$room->host_id}/follow")
            ->assertStatus(422)
            ->assertJsonPath('msg', 'Unblock this user before following.');
    }

    public function test_viewer_cannot_join_a_host_they_blocked(): void
    {
        $viewer = User::factory()->create();
        [$hostUser, $room] = $this->makeRoom('join');
        app(UserBlockService::class)->block($viewer, $hostUser);

        try {
            app(LiveRoomAccessService::class)->assertCanJoin(
                $room->load('host'),
                $viewer,
                $viewer->id,
                'viewer',
            );
            $this->fail('Expected personal host block to prevent room join.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame(
                'You blocked this host. Unblock them to join this room.',
                $exception->getMessage(),
            );
        }
    }

    public function test_websocket_join_check_rejects_a_host_the_viewer_blocked(): void
    {
        $viewer = User::factory()->create();
        [$hostUser, $room] = $this->makeRoom('ws-join');
        app(UserBlockService::class)->block($viewer, $hostUser);
        Sanctum::actingAs($viewer);

        $this->postJson('/api/ws/rooms/join-check', ['room_id' => $room->room_id])
            ->assertOk()
            ->assertJsonPath('allow', false)
            ->assertJsonPath('code', 'YOU_BLOCKED_HOST');
    }

    public function test_websocket_moderation_snapshot_requires_the_internal_key(): void
    {
        config()->set('services.websocket.internal_key', 'test-ws-key');

        $this->getJson('/api/ws/moderation/snapshot')->assertForbidden();

        $this->withHeader('X-WS-Internal-Key', 'test-ws-key')
            ->getJson('/api/ws/moderation/snapshot')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_personal_block_prevents_joining_an_accepted_private_call(): void
    {
        $caller = User::factory()->create();
        [$receiver, $room] = $this->makeRoom('call');
        $call = CallSession::query()->create([
            'caller_id' => $caller->id,
            'receiver_id' => $receiver->id,
            'host_id' => $room->host_id,
            'type' => 'audio',
            'status' => 'accepted',
            'livekit_room_name' => 'private-call-room',
            'coin_rate_per_minute' => 10,
        ]);
        app(UserBlockService::class)->block($caller, $receiver);

        $this->expectExceptionMessage('Unblock this user before joining the call.');
        app(CallSessionService::class)->issueParticipantToken($call, $caller);
    }

    public function test_blocked_host_rooms_are_excluded_from_discovery(): void
    {
        $viewer = User::factory()->create();
        [, $visibleRoom] = $this->makeRoom('visible');
        [$blockedHostUser, $blockedRoom] = $this->makeRoom('hidden');
        app(UserBlockService::class)->block($viewer, $blockedHostUser);
        Sanctum::actingAs($viewer);

        $roomIds = collect($this->getJson('/api/live/rooms')->assertOk()->json('data'))
            ->pluck('room_id');

        $this->assertTrue($roomIds->contains($visibleRoom->room_id));
        $this->assertFalse($roomIds->contains($blockedRoom->room_id));
    }

    public function test_block_list_returns_more_than_the_old_first_page(): void
    {
        $blocker = User::factory()->create();
        $targets = User::factory()->count(55)->createQuietly();
        $now = now();
        UserBlock::query()->insert($targets->map(fn (User $target) => [
            'blocker_user_id' => $blocker->id,
            'blocked_user_id' => $target->id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
        Sanctum::actingAs($blocker);

        $this->getJson('/api/me/blocked-users')
            ->assertOk()
            ->assertJsonCount(55, 'data')
            ->assertJsonPath('meta.total', 55);
    }

    public function test_personal_block_prevents_gifts_between_viewer_and_host(): void
    {
        $viewer = User::factory()->create();
        [$hostUser, $room] = $this->makeRoom('gift');
        app(UserBlockService::class)->block($viewer, $hostUser);

        try {
            app(LiveRoomGiftService::class)->send(
                $room,
                $viewer,
                new Gift(['name' => 'Test', 'coins' => 1, 'is_active' => true]),
            );
            $this->fail('Expected personal block to prevent gifting.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('Unblock this user before sending gifts.', $exception->getMessage());
        }
    }

    public function test_personal_block_prevents_pk_invites_between_hosts(): void
    {
        [$hostAUser, $roomA] = $this->makeRoom('pk-a');
        [$hostBUser, $roomB] = $this->makeRoom('pk-b');
        app(UserBlockService::class)->block($hostAUser, $hostBUser);

        try {
            app(LiveRoomPkService::class)->invite($roomA, $roomB, $hostAUser);
            $this->fail('Expected personal block to prevent PK invitation.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame(
                'Unblock this host before starting a PK battle.',
                $exception->getMessage(),
            );
        }
    }

    private function makeRoom(string $suffix): array
    {
        $hostUser = User::factory()->create();
        $hostUser->assignRole('host');
        $host = Host::query()->create([
            'user_id' => $hostUser->id,
            'stage_name' => 'Host '.$suffix,
        ]);
        $room = LiveRoom::query()->create([
            'host_id' => $host->id,
            'room_id' => 'user-block-'.$suffix,
            'title' => 'Room '.$suffix,
            'room_type' => 'video',
            'status' => 'live',
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        return [$hostUser, $room];
    }
}
