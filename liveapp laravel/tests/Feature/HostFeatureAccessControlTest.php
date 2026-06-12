<?php

namespace Tests\Feature;

use App\Models\Host;
use App\Models\HostAvailability;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HostFeatureAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_host_cannot_start_disabled_video_room(): void
    {
        $hostUser = User::factory()->create();
        $hostUser->assignRole('host');

        Host::query()->create([
            'user_id' => $hostUser->id,
            'stage_name' => 'Video Off Host',
            'video_rooms_enabled' => false,
        ]);

        Sanctum::actingAs($hostUser);

        $this->postJson('/api/live/rooms', [
            'title' => 'Blocked Video Room',
            'room_type' => 'video',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Video rooms are disabled for this host.');
    }

    public function test_host_cannot_start_disabled_audio_room(): void
    {
        $hostUser = User::factory()->create();
        $hostUser->assignRole('host');

        Host::query()->create([
            'user_id' => $hostUser->id,
            'stage_name' => 'Audio Off Host',
            'audio_rooms_enabled' => false,
        ]);

        Sanctum::actingAs($hostUser);

        $this->postJson('/api/live/audio-rooms', [
            'title' => 'Blocked Audio Room',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Audio rooms are disabled for this host.');
    }

    public function test_user_cannot_request_disabled_video_call(): void
    {
        [$caller, $receiver] = $this->makeCallableUsers([
            'video_calls_enabled' => false,
        ]);

        Sanctum::actingAs($caller);

        $this->postJson('/api/calls/request', [
            'receiver_id' => $receiver->id,
            'type' => 'video',
        ])->assertStatus(422)
            ->assertJsonPath('msg', 'Receiver is not accepting video calls right now.');
    }

    public function test_live_users_payload_exposes_disabled_call_flags(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $viewer->id], ['balance' => 5000]);

        $receiver = User::factory()->create();
        $receiver->assignRole('host');
        Host::query()->create([
            'user_id' => $receiver->id,
            'stage_name' => 'Selective Host',
            'audio_calls_enabled' => false,
            'video_calls_enabled' => true,
        ]);
        HostAvailability::query()->create([
            'user_id' => $receiver->id,
            'manual_status' => 'online',
            'socket_status' => 'online',
            'call_status' => 'available',
        ]);

        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/live-users')->assertOk();
        $row = data_get($response->json(), 'data.users.0');

        $this->assertFalse((bool) data_get($row, 'audio_calls_enabled'));
        $this->assertTrue((bool) data_get($row, 'video_calls_enabled'));
        $this->assertFalse((bool) data_get($row, 'audio_call_available'));
        $this->assertTrue((bool) data_get($row, 'video_call_available'));
    }

    public function test_live_users_excludes_blocked_hosts(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $viewer->id], ['balance' => 5000]);

        $receiver = User::factory()->create();
        $receiver->assignRole('host');
        Host::query()->create([
            'user_id' => $receiver->id,
            'stage_name' => 'Blocked Host',
            'is_blocked' => true,
        ]);
        HostAvailability::query()->create([
            'user_id' => $receiver->id,
            'manual_status' => 'online',
            'socket_status' => 'online',
            'call_status' => 'available',
        ]);

        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/live-users')->assertOk();

        $this->assertCount(0, data_get($response->json(), 'data.users', []));
    }

    private function makeCallableUsers(array $hostAttributes = []): array
    {
        $caller = User::factory()->create();
        $caller->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $caller->id], ['balance' => 5000]);

        $receiver = User::factory()->create();
        $receiver->assignRole('host');

        Host::query()->create(array_merge([
            'user_id' => $receiver->id,
            'stage_name' => 'Callable Host',
        ], $hostAttributes));

        HostAvailability::query()->create([
            'user_id' => $receiver->id,
            'manual_status' => 'online',
            'socket_status' => 'online',
            'call_status' => 'available',
        ]);

        return [$caller, $receiver];
    }
}
