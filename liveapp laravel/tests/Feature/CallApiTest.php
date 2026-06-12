<?php

namespace Tests\Feature;

use App\Models\CallSession;
use App\Models\Host;
use App\Models\HostAvailability;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CallBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CallApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_user_cannot_call_themselves(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/calls/request', [
            'receiver_id' => $user->id,
            'type' => 'audio',
        ]);

        $response
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'msg' => 'Caller cannot call themselves.']);
    }

    public function test_non_host_cannot_toggle_host_status(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/host/status/toggle', [
            'manual_status' => 'online',
        ]);

        $response->assertForbidden();
    }

    public function test_blocked_host_cannot_toggle_online_status(): void
    {
        $user = User::factory()->create();
        $user->assignRole('host');
        Host::query()->create([
            'user_id' => $user->id,
            'stage_name' => 'Blocked Host',
            'is_blocked' => true,
        ]);
        HostAvailability::query()->create([
            'user_id' => $user->id,
            'manual_status' => 'offline',
            'socket_status' => 'offline',
            'call_status' => 'available',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/host/status/toggle', [
            'manual_status' => 'online',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Blocked hosts cannot go online.');
    }

    public function test_host_status_reports_blocked_host_as_offline(): void
    {
        $user = User::factory()->create();
        $user->assignRole('host');
        Host::query()->create([
            'user_id' => $user->id,
            'stage_name' => 'Blocked Host',
            'is_blocked' => true,
        ]);
        HostAvailability::query()->create([
            'user_id' => $user->id,
            'manual_status' => 'online',
            'socket_status' => 'online',
            'call_status' => 'available',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/host/status')
            ->assertOk()
            ->assertJsonPath('data.manual_status', 'offline')
            ->assertJsonPath('data.socket_status', 'offline')
            ->assertJsonPath('data.host_is_blocked', true);
    }

    public function test_duplicate_call_request_returns_existing_active_call(): void
    {
        $caller = User::factory()->create();
        $caller->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $caller->id], ['balance' => 5000]);

        $receiver = User::factory()->create();
        $receiver->assignRole('host');
        Host::query()->create(['user_id' => $receiver->id, 'stage_name' => 'Tester']);
        HostAvailability::query()->create([
            'user_id' => $receiver->id,
            'manual_status' => 'online',
            'socket_status' => 'online',
            'call_status' => 'available',
        ]);

        Sanctum::actingAs($caller);

        $first = $this->postJson('/api/calls/request', [
            'receiver_id' => $receiver->id,
            'type' => 'audio',
        ])->assertCreated();

        $second = $this->postJson('/api/calls/request', [
            'receiver_id' => $receiver->id,
            'type' => 'audio',
        ])->assertCreated();

        $this->assertSame(
            data_get($first->json(), 'data.id'),
            data_get($second->json(), 'data.id')
        );
        $this->assertDatabaseCount('call_sessions', 1);
    }

    public function test_end_call_is_idempotent_for_terminal_call(): void
    {
        $caller = User::factory()->create();
        $caller->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $caller->id], ['balance' => 5000]);

        $receiver = User::factory()->create();
        $receiver->assignRole('host');
        Host::query()->create(['user_id' => $receiver->id, 'stage_name' => 'Tester']);
        HostAvailability::query()->create([
            'user_id' => $receiver->id,
            'manual_status' => 'online',
            'socket_status' => 'online',
            'call_status' => 'busy',
        ]);

        $call = CallSession::query()->create([
            'caller_id' => $caller->id,
            'receiver_id' => $receiver->id,
            'host_id' => Host::query()->where('user_id', $receiver->id)->value('id'),
            'type' => 'audio',
            'status' => 'ended',
            'accepted_at' => now(),
            'started_at' => now()->subMinutes(2),
            'ended_at' => now(),
            'coin_rate_per_minute' => 10,
            'billing_processed_at' => now(),
            'total_coins_charged' => 20,
        ]);

        Sanctum::actingAs($caller);

        $response = $this->postJson("/api/calls/{$call->id}/end", [
            'reason' => 'completed',
        ]);

        $response->assertOk()->assertJsonPath('data.id', $call->id);
    }

    public function test_audio_call_snapshots_audio_rate(): void
    {
        Config::set('calls.audio_coin_rate_per_minute', 15);
        Config::set('calls.video_coin_rate_per_minute', 40);
        Config::set('calls.minimum_balance_to_start_call', 10);

        [$caller, $receiver] = $this->makeCallableUsers(balance: 5000);
        Sanctum::actingAs($caller);

        $response = $this->postJson('/api/calls/request', [
            'receiver_id' => $receiver->id,
            'type' => 'audio',
        ])->assertCreated();

        $callId = data_get($response->json(), 'data.id');
        $this->assertDatabaseHas('call_sessions', [
            'id' => $callId,
            'type' => 'audio',
            'coin_rate_per_minute' => 15,
        ]);
    }

    public function test_video_call_snapshots_video_rate(): void
    {
        Config::set('calls.audio_coin_rate_per_minute', 15);
        Config::set('calls.video_coin_rate_per_minute', 45);
        Config::set('calls.minimum_balance_to_start_call', 10);

        [$caller, $receiver] = $this->makeCallableUsers(balance: 5000);
        Sanctum::actingAs($caller);

        $response = $this->postJson('/api/calls/request', [
            'receiver_id' => $receiver->id,
            'type' => 'video',
        ])->assertCreated();

        $callId = data_get($response->json(), 'data.id');
        $this->assertDatabaseHas('call_sessions', [
            'id' => $callId,
            'type' => 'video',
            'coin_rate_per_minute' => 45,
        ]);
    }

    public function test_host_specific_rates_override_global_rates(): void
    {
        Config::set('calls.audio_coin_rate_per_minute', 15);
        Config::set('calls.video_coin_rate_per_minute', 45);
        Config::set('calls.minimum_balance_to_start_call', 10);

        [$caller, $receiver, $host] = $this->makeCallableUsersWithHost(balance: 5000, hostAttributes: [
            'audio_call_rate_per_minute' => 22,
            'video_call_rate_per_minute' => 60,
        ]);
        Sanctum::actingAs($caller);

        $audio = $this->postJson('/api/calls/request', [
            'receiver_id' => $receiver->id,
            'type' => 'audio',
        ])->assertCreated();

        $this->assertDatabaseHas('call_sessions', [
            'id' => data_get($audio->json(), 'data.id'),
            'coin_rate_per_minute' => 22,
        ]);

        [$videoCaller, $videoReceiver] = $this->makeCallableUsers(balance: 5000);
        $videoHost = Host::query()->where('user_id', $videoReceiver->id)->firstOrFail();
        $videoHost->update([
            'audio_call_rate_per_minute' => 22,
            'video_call_rate_per_minute' => 60,
        ]);
        Sanctum::actingAs($videoCaller);

        $video = $this->postJson('/api/calls/request', [
            'receiver_id' => $videoReceiver->id,
            'type' => 'video',
        ])->assertCreated();

        $this->assertDatabaseHas('call_sessions', [
            'id' => data_get($video->json(), 'data.id'),
            'coin_rate_per_minute' => 60,
        ]);
        $this->assertSame($host->id, Host::query()->findOrFail($host->id)->id);
    }

    public function test_legacy_global_rate_fallback_still_works(): void
    {
        Config::set('calls.coin_rate_per_minute', 33);
        Config::set('calls.audio_coin_rate_per_minute', null);
        Config::set('calls.video_coin_rate_per_minute', null);
        Config::set('calls.minimum_balance_to_start_call', 10);

        [$caller, $receiver] = $this->makeCallableUsers(balance: 5000);
        Sanctum::actingAs($caller);

        $audio = $this->postJson('/api/calls/request', [
            'receiver_id' => $receiver->id,
            'type' => 'audio',
        ])->assertCreated();

        $this->assertDatabaseHas('call_sessions', [
            'id' => data_get($audio->json(), 'data.id'),
            'coin_rate_per_minute' => 33,
        ]);

        [$videoCaller, $videoReceiver] = $this->makeCallableUsers(balance: 5000);
        Sanctum::actingAs($videoCaller);
        $video = $this->postJson('/api/calls/request', [
            'receiver_id' => $videoReceiver->id,
            'type' => 'video',
        ])->assertCreated();

        $this->assertDatabaseHas('call_sessions', [
            'id' => data_get($video->json(), 'data.id'),
            'coin_rate_per_minute' => 33,
        ]);
    }

    public function test_insufficient_balance_is_checked_against_selected_call_type_rate(): void
    {
        Config::set('calls.audio_coin_rate_per_minute', 30);
        Config::set('calls.video_coin_rate_per_minute', 80);
        Config::set('calls.minimum_balance_to_start_call', 20);

        [$caller, $receiver] = $this->makeCallableUsers(balance: 50);
        Sanctum::actingAs($caller);

        $this->postJson('/api/calls/request', [
            'receiver_id' => $receiver->id,
            'type' => 'audio',
        ])->assertCreated();

        $this->postJson('/api/calls/request', [
            'receiver_id' => $receiver->id,
            'type' => 'video',
        ])->assertStatus(422)->assertJson([
            'ok' => false,
            'msg' => 'Insufficient coins to start call.',
        ]);
    }

    public function test_live_users_payload_includes_audio_and_video_rates(): void
    {
        Config::set('calls.audio_coin_rate_per_minute', 25);
        Config::set('calls.video_coin_rate_per_minute', 70);
        Config::set('calls.minimum_balance_to_start_call', 20);

        [$caller, $receiver, $host] = $this->makeCallableUsersWithHost(balance: 5000, hostAttributes: [
            'audio_call_rate_per_minute' => 35,
            'video_call_rate_per_minute' => 90,
        ]);
        Sanctum::actingAs($caller);

        $response = $this->getJson('/api/live-users')->assertOk();
        $row = collect(data_get($response->json(), 'data.users', []))
            ->firstWhere('id', $receiver->id);

        $this->assertSame(20, data_get($response->json(), 'data.minimum_balance_to_start_call'));
        $this->assertSame(35, data_get($row, 'audio_call_rate_per_minute'));
        $this->assertSame(90, data_get($row, 'video_call_rate_per_minute'));
        $this->assertSame(35, data_get($row, 'audio_minimum_balance_required'));
        $this->assertSame(90, data_get($row, 'video_minimum_balance_required'));
    }

    public function test_billing_uses_stored_rate_even_if_config_changes_after_call_start(): void
    {
        Config::set('calls.audio_coin_rate_per_minute', 12);
        Config::set('calls.minimum_billable_minutes', 1);

        [$caller, $receiver, $host] = $this->makeCallableUsersWithHost(balance: 5000);
        $call = CallSession::query()->create([
            'caller_id' => $caller->id,
            'receiver_id' => $receiver->id,
            'host_id' => $host->id,
            'agency_id' => $host->agency_id,
            'type' => 'audio',
            'status' => 'ended',
            'accepted_at' => now()->subMinutes(3),
            'started_at' => now()->subMinutes(3),
            'ended_at' => now(),
            'coin_rate_per_minute' => 12,
        ]);

        Config::set('calls.audio_coin_rate_per_minute', 99);
        app(CallBillingService::class)->processEndedCall($call->fresh());

        $call->refresh();
        $this->assertSame(12, (int) $call->coin_rate_per_minute);
        $this->assertSame(36, (int) $call->total_coins_charged);
    }

    private function makeCallableUsers(int $balance = 5000): array
    {
        [$caller, $receiver] = $this->makeCallableUsersWithHost(balance: $balance);

        return [$caller, $receiver];
    }

    private function makeCallableUsersWithHost(int $balance = 5000, array $hostAttributes = []): array
    {
        $caller = User::factory()->create();
        $caller->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $caller->id], ['balance' => $balance]);

        $receiver = User::factory()->create();
        $receiver->assignRole('host');
        $host = Host::query()->create(array_merge([
            'user_id' => $receiver->id,
            'stage_name' => 'Tester',
        ], $hostAttributes));
        HostAvailability::query()->create([
            'user_id' => $receiver->id,
            'manual_status' => 'online',
            'socket_status' => 'online',
            'call_status' => 'available',
        ]);

        return [$caller, $receiver, $host];
    }
}
