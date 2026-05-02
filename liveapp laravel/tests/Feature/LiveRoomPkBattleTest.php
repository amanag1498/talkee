<?php

namespace Tests\Feature;

use App\Models\Gift;
use App\Models\Host;
use App\Models\LiveRoom;
use App\Models\LiveRoomParticipant;
use App\Models\LiveRoomPkBattle;
use App\Models\LiveRoomPkEvent;
use App\Models\LiveRoomSeatRequest;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\Wallet;
use App\Services\LiveKitRoomAdminService;
use App\Services\LiveRoomPkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LiveRoomPkBattleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'super-admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturn(1);
    }

    public function test_host_can_invite_and_target_host_can_reject_pk(): void
    {
        [$hostAUser, $roomA] = $this->makeLiveRoom('a');
        [, $roomB] = $this->makeLiveRoom('b');

        Sanctum::actingAs($hostAUser);
        $invite = $this->postJson("/api/live/rooms/{$roomA->room_id}/pk/invite", [
            'target_room_id' => $roomB->room_id,
        ])->assertOk();

        $battleId = $invite->json('data.battle_id');
        $this->assertNotEmpty($battleId);

        Sanctum::actingAs($roomB->host->user);
        $this->postJson("/api/live/rooms/{$roomB->room_id}/pk/{$battleId}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_cannot_invite_own_room(): void
    {
        [$hostUser, $room] = $this->makeLiveRoom('self');

        Sanctum::actingAs($hostUser);

        $this->postJson("/api/live/rooms/{$room->room_id}/pk/invite", [
            'target_room_id' => $room->room_id,
        ])->assertStatus(409);
    }

    public function test_cross_invite_returns_same_pending_battle(): void
    {
        [$hostAUser, $roomA] = $this->makeLiveRoom('cross-a');
        [$hostBUser, $roomB] = $this->makeLiveRoom('cross-b');

        Sanctum::actingAs($hostAUser);
        $first = $this->postJson("/api/live/rooms/{$roomA->room_id}/pk/invite", [
            'target_room_id' => $roomB->room_id,
        ])->assertOk();

        Sanctum::actingAs($hostBUser);
        $second = $this->postJson("/api/live/rooms/{$roomB->room_id}/pk/invite", [
            'target_room_id' => $roomA->room_id,
        ])->assertOk();

        $this->assertSame($first->json('data.battle_id'), $second->json('data.battle_id'));
        $this->assertSame(1, LiveRoomPkBattle::query()->count());
    }

    public function test_accept_pk_demotes_existing_speakers_and_cancels_pending_requests(): void
    {
        [$hostAUser, $roomA] = $this->makeLiveRoom('accept-a', maxSpeakers: 4, roomType: 'video');
        [, $roomB] = $this->makeLiveRoom('accept-b', maxSpeakers: 4, roomType: 'video');

        $speakerA = $this->makeViewerInRoom($roomA, role: 'speaker');
        $speakerB = $this->makeViewerInRoom($roomB, role: 'speaker');
        $pendingA = $this->makeViewerInRoom($roomA);
        $pendingB = $this->makeViewerInRoom($roomB);

        LiveRoomSeatRequest::query()->create([
            'live_room_id' => $roomA->id,
            'user_id' => $speakerA->id,
            'status' => 'accepted',
            'requested_at' => now()->subMinutes(2),
            'responded_at' => now()->subMinute(),
            'responded_by' => $hostAUser->id,
        ]);
        LiveRoomSeatRequest::query()->create([
            'live_room_id' => $roomB->id,
            'user_id' => $speakerB->id,
            'status' => 'accepted',
            'requested_at' => now()->subMinutes(2),
            'responded_at' => now()->subMinute(),
            'responded_by' => $roomB->host->user_id,
        ]);
        LiveRoomSeatRequest::query()->create([
            'live_room_id' => $roomA->id,
            'user_id' => $pendingA->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        LiveRoomSeatRequest::query()->create([
            'live_room_id' => $roomB->id,
            'user_id' => $pendingB->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $this->mock(LiveKitRoomAdminService::class, function ($mock) {
            $mock->shouldReceive('setParticipantCanPublish')->twice()->andReturnNull();
        });

        Sanctum::actingAs($hostAUser);
        $battleId = $this->postJson("/api/live/rooms/{$roomA->room_id}/pk/invite", [
            'target_room_id' => $roomB->room_id,
        ])->json('data.battle_id');

        Sanctum::actingAs($roomB->host->user);
        $this->postJson("/api/live/rooms/{$roomB->room_id}/pk/{$battleId}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('live_room_participants', [
            'live_room_id' => $roomA->id,
            'user_id' => $speakerA->id,
            'role' => 'viewer',
            'removed_by_host' => 1,
        ]);
        $this->assertDatabaseHas('live_room_participants', [
            'live_room_id' => $roomB->id,
            'user_id' => $speakerB->id,
            'role' => 'viewer',
            'removed_by_host' => 1,
        ]);
        $this->assertDatabaseHas('live_room_seat_requests', [
            'live_room_id' => $roomA->id,
            'user_id' => $speakerA->id,
            'status' => 'removed',
        ]);
        $this->assertDatabaseHas('live_room_seat_requests', [
            'live_room_id' => $roomB->id,
            'user_id' => $speakerB->id,
            'status' => 'removed',
        ]);
        $this->assertDatabaseHas('live_room_seat_requests', [
            'live_room_id' => $roomA->id,
            'user_id' => $pendingA->id,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('live_room_seat_requests', [
            'live_room_id' => $roomB->id,
            'user_id' => $pendingB->id,
            'status' => 'cancelled',
        ]);

        Sanctum::actingAs($pendingA);
        $this->postJson("/api/live/rooms/{$roomA->room_id}/seat-requests")
            ->assertStatus(409);
    }

    public function test_video_host_cannot_invite_audio_host_to_pk(): void
    {
        [$hostAUser, $videoRoom] = $this->makeLiveRoom('video-pk-a', roomType: 'video');
        [, $audioRoom] = $this->makeLiveRoom('audio-pk-b', roomType: 'audio');

        Sanctum::actingAs($hostAUser);

        $this->postJson("/api/live/rooms/{$videoRoom->room_id}/pk/invite", [
            'target_room_id' => $audioRoom->room_id,
        ])->assertStatus(409)
            ->assertSee('incompatible_room_type');
    }

    public function test_audio_host_cannot_start_pk(): void
    {
        [$audioHostUser, $audioRoom] = $this->makeLiveRoom('audio-pk-a', roomType: 'audio');
        [, $videoRoom] = $this->makeLiveRoom('video-pk-b', roomType: 'video');

        Sanctum::actingAs($audioHostUser);

        $this->postJson("/api/live/rooms/{$audioRoom->room_id}/pk/invite", [
            'target_room_id' => $videoRoom->room_id,
        ])->assertStatus(409)
            ->assertSee('incompatible_room_type');
    }

    public function test_audio_room_active_pk_endpoint_returns_null(): void
    {
        [, $audioRoom] = $this->makeLiveRoom('audio-active-null', roomType: 'audio');
        $viewer = $this->makeViewerInRoom($audioRoom);

        Sanctum::actingAs($viewer);

        $this->getJson("/api/live/rooms/{$audioRoom->room_id}/pk/active")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_cannot_invite_another_room_while_active_pk_exists(): void
    {
        [$hostAUser, $roomA] = $this->makeLiveRoom('dup-a');
        [, $roomB] = $this->makeLiveRoom('dup-b');
        [, $roomC] = $this->makeLiveRoom('dup-c');

        $this->mock(LiveKitRoomAdminService::class, function ($mock) {
            $mock->shouldReceive('setParticipantCanPublish')->zeroOrMoreTimes()->andReturnNull();
        });

        Sanctum::actingAs($hostAUser);
        $battleId = $this->postJson("/api/live/rooms/{$roomA->room_id}/pk/invite", [
            'target_room_id' => $roomB->room_id,
        ])->json('data.battle_id');

        Sanctum::actingAs($roomB->host->user);
        $this->postJson("/api/live/rooms/{$roomB->room_id}/pk/{$battleId}/accept")->assertOk();

        Sanctum::actingAs($hostAUser);
        $this->postJson("/api/live/rooms/{$roomA->room_id}/pk/invite", [
            'target_room_id' => $roomC->room_id,
        ])->assertStatus(409);
    }

    public function test_media_token_is_subscribe_only_for_opponent_room(): void
    {
        [$hostAUser, $roomA] = $this->makeLiveRoom('media-a');
        [, $roomB] = $this->makeLiveRoom('media-b');
        $viewer = $this->makeViewerInRoom($roomA);

        $this->mock(LiveKitRoomAdminService::class, function ($mock) {
            $mock->shouldReceive('setParticipantCanPublish')->zeroOrMoreTimes()->andReturnNull();
        });

        Sanctum::actingAs($hostAUser);
        $battleId = $this->postJson("/api/live/rooms/{$roomA->room_id}/pk/invite", [
            'target_room_id' => $roomB->room_id,
        ])->json('data.battle_id');

        Sanctum::actingAs($roomB->host->user);
        $this->postJson("/api/live/rooms/{$roomB->room_id}/pk/{$battleId}/accept")->assertOk();

        Sanctum::actingAs($viewer);
        $response = $this->getJson("/api/live/rooms/{$roomA->room_id}/pk/{$battleId}/media-token", [
            'X-Device-Id' => 'device-test',
        ])->assertOk();

        $data = $response->json('data');
        $payload = $this->decodeJwtPayload($data['opponent_token']);

        $this->assertSame($roomB->room_id, $data['opponent_room_id']);
        $this->assertSame($roomB->room_id, data_get($payload, 'video.room'));
        $this->assertTrue((bool) data_get($payload, 'video.canSubscribe'));
        $this->assertFalse((bool) data_get($payload, 'video.canPublish'));
        $this->assertFalse((bool) data_get($payload, 'video.canPublishData'));
    }

    public function test_gift_scoring_updates_correct_side_and_does_not_double_count(): void
    {
        [$hostAUser, $roomA] = $this->makeLiveRoom('score-a');
        [, $roomB] = $this->makeLiveRoom('score-b');
        $viewer = $this->makeViewerInRoom($roomA);
        $this->grantSubscription($viewer);
        Wallet::query()->where('user_id', $viewer->id)->update(['balance' => 1000]);

        $gift = Gift::query()->create([
            'name' => 'PK Rose',
            'coins' => 50,
            'gift_url' => 'https://example.com/pk-rose.png',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->mock(LiveKitRoomAdminService::class, function ($mock) {
            $mock->shouldReceive('setParticipantCanPublish')->zeroOrMoreTimes()->andReturnNull();
        });

        Sanctum::actingAs($hostAUser);
        $battleId = $this->postJson("/api/live/rooms/{$roomA->room_id}/pk/invite", [
            'target_room_id' => $roomB->room_id,
        ])->json('data.battle_id');

        Sanctum::actingAs($roomB->host->user);
        $this->postJson("/api/live/rooms/{$roomB->room_id}/pk/{$battleId}/accept")->assertOk();

        Sanctum::actingAs($viewer);
        $this->postJson("/api/live/rooms/{$roomA->room_id}/gifts", [
            'gift_id' => $gift->id,
            'quantity' => 2,
        ])->assertCreated();

        $battle = LiveRoomPkBattle::query()->where('battle_id', $battleId)->firstOrFail();
        $this->assertSame(100, $battle->score_a);
        $this->assertSame(0, $battle->score_b);
        $this->assertSame(1, LiveRoomPkEvent::query()->where('pk_battle_id', $battle->id)->count());

        $event = LiveRoomPkEvent::query()->where('pk_battle_id', $battle->id)->firstOrFail();
        app(LiveRoomPkService::class)->recordGiftScore($roomA, $event->walletTransaction, 100, $gift->id, $viewer);

        $battle->refresh();
        $this->assertSame(100, $battle->score_a);
        $this->assertSame(1, LiveRoomPkEvent::query()->where('pk_battle_id', $battle->id)->count());
    }

    public function test_cleanup_expires_pending_invite_and_completes_expired_active_battle(): void
    {
        [, $roomA] = $this->makeLiveRoom('cleanup-a');
        [, $roomB] = $this->makeLiveRoom('cleanup-b');

        $pending = LiveRoomPkBattle::query()->create([
            'battle_id' => 'pk_pending_cleanup',
            'room_a_id' => $roomA->id,
            'room_b_id' => $roomB->id,
            'host_a_id' => $roomA->host_id,
            'host_b_id' => $roomB->host_id,
            'invited_by_host_id' => $roomA->host_id,
            'status' => 'pending',
            'duration_seconds' => 300,
        ]);
        $pending->forceFill([
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ])->saveQuietly();

        $active = LiveRoomPkBattle::query()->create([
            'battle_id' => 'pk_active_cleanup',
            'room_a_id' => $roomA->id,
            'room_b_id' => $roomB->id,
            'host_a_id' => $roomA->host_id,
            'host_b_id' => $roomB->host_id,
            'invited_by_host_id' => $roomA->host_id,
            'status' => 'active',
            'duration_seconds' => 60,
            'score_a' => 200,
            'score_b' => 100,
            'started_at' => now()->subMinutes(5),
        ]);

        $report = app(LiveRoomPkService::class)->cleanup();

        $pending->refresh();
        $active->refresh();

        $this->assertSame('expired', $pending->status);
        $this->assertSame('completed', $active->status);
        $this->assertSame($roomA->id, $active->winner_room_id);
        $this->assertContains('pk_pending_cleanup', $report['expired_pending']);
        $this->assertSame('timer_expired', $active->end_reason);
    }

    public function test_room_termination_ends_active_pk(): void
    {
        [, $roomA] = $this->makeLiveRoom('terminate-a');
        [, $roomB] = $this->makeLiveRoom('terminate-b');

        $battle = LiveRoomPkBattle::query()->create([
            'battle_id' => 'pk_room_termination',
            'room_a_id' => $roomA->id,
            'room_b_id' => $roomB->id,
            'host_a_id' => $roomA->host_id,
            'host_b_id' => $roomB->host_id,
            'invited_by_host_id' => $roomA->host_id,
            'status' => 'active',
            'duration_seconds' => 300,
            'started_at' => now()->subMinute(),
        ]);

        app(LiveRoomPkService::class)->endForRoomTermination($roomA, 'room_ended');

        $battle->refresh();
        $this->assertSame('completed', $battle->status);
        $this->assertSame('room_ended', $battle->end_reason);
    }

    public function test_admin_pk_report_page_loads(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        [$hostAUser, $roomA] = $this->makeLiveRoom('admin-a');
        [, $roomB] = $this->makeLiveRoom('admin-b');

        LiveRoomPkBattle::query()->create([
            'battle_id' => 'pk_admin_report',
            'room_a_id' => $roomA->id,
            'room_b_id' => $roomB->id,
            'host_a_id' => $roomA->host_id,
            'host_b_id' => $roomB->host_id,
            'invited_by_host_id' => $roomA->host_id,
            'status' => 'completed',
            'duration_seconds' => 300,
            'score_a' => 20,
            'score_b' => 10,
            'started_at' => now()->subMinutes(8),
            'ended_at' => now()->subMinutes(3),
            'winner_room_id' => $roomA->id,
            'end_reason' => 'timer_expired',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.pk-battles.index'))
            ->assertOk()
            ->assertSee('pk_admin_report');
    }

    public function test_admin_pk_reports_hide_audio_room_battles(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        [, $audioRoom] = $this->makeLiveRoom('admin-audio-a', roomType: 'audio');
        [, $videoRoom] = $this->makeLiveRoom('admin-video-b', roomType: 'video');

        LiveRoomPkBattle::query()->create([
            'battle_id' => 'pk_audio_invalid',
            'room_a_id' => $audioRoom->id,
            'room_b_id' => $videoRoom->id,
            'host_a_id' => $audioRoom->host_id,
            'host_b_id' => $videoRoom->host_id,
            'invited_by_host_id' => $audioRoom->host_id,
            'status' => 'failed',
            'duration_seconds' => 300,
            'end_reason' => 'incompatible_room_type',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.pk-battles.index'))
            ->assertOk()
            ->assertDontSee('pk_audio_invalid');
    }

    private function makeLiveRoom(string $suffix, string $roomType = 'video', int $maxSpeakers = 4): array
    {
        $hostUser = User::factory()->create();
        $hostUser->assignRole('host');

        $host = Host::query()->create([
            'user_id' => $hostUser->id,
            'stage_name' => 'Host '.strtoupper($suffix),
        ]);

        $room = LiveRoom::query()->create([
            'host_id' => $host->id,
            'room_id' => 'room-'.$suffix,
            'title' => 'Room '.strtoupper($suffix),
            'room_type' => $roomType,
            'status' => 'live',
            'started_at' => now()->subMinutes(5),
            'last_activity_at' => now()->subMinute(),
            'peak_viewers' => 2,
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

    private function makeViewerInRoom(LiveRoom $room, string $role = 'viewer'): User
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('user');

        LiveRoomParticipant::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'role' => $role,
            'joined_at' => now()->subMinute(),
            'meta' => $role === 'speaker' ? ['speaker_since' => now()->subSeconds(30)->toIso8601String()] : [],
        ]);

        return $viewer;
    }

    private function grantSubscription(User $user): void
    {
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Viewer Pass',
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

    private function decodeJwtPayload(string $token): array
    {
        [, $payload] = explode('.', $token);
        $decoded = base64_decode(strtr($payload, '-_', '+/'));

        return json_decode($decoded ?: '{}', true) ?? [];
    }
}
