<?php

namespace Tests\Feature;

use App\Models\Host;
use App\Models\HostFollower;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\HostAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HostFollowSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_user_can_follow_host_and_duplicate_follow_is_idempotent(): void
    {
        [$viewer, $host] = $this->viewerAndHost();

        Sanctum::actingAs($viewer);

        $this->postJson("/api/hosts/{$host->id}/follow")->assertOk();
        $this->postJson("/api/hosts/{$host->id}/follow")->assertOk();

        $this->assertDatabaseCount('host_followers', 1);
        $this->assertDatabaseHas('host_followers', [
            'host_id' => $host->id,
            'user_id' => $viewer->id,
        ]);
    }

    public function test_user_cannot_follow_own_host_profile(): void
    {
        $user = User::factory()->create();
        $user->assignRole('host');
        $host = Host::query()->create(['user_id' => $user->id, 'stage_name' => 'Self Host']);

        Sanctum::actingAs($user);

        $this->postJson("/api/hosts/{$host->id}/follow")
            ->assertStatus(422);
    }

    public function test_unfollow_is_idempotent(): void
    {
        [$viewer, $host] = $this->viewerAndHost();

        HostFollower::query()->create([
            'host_id' => $host->id,
            'user_id' => $viewer->id,
        ]);

        Sanctum::actingAs($viewer);

        $this->deleteJson("/api/hosts/{$host->id}/follow")->assertOk();
        $this->deleteJson("/api/hosts/{$host->id}/follow")->assertOk();

        $this->assertDatabaseCount('host_followers', 0);
    }

    public function test_profile_returns_following_and_follower_counts(): void
    {
        [$viewer, $host] = $this->viewerAndHost();
        HostFollower::query()->create([
            'host_id' => $host->id,
            'user_id' => $viewer->id,
        ]);

        Sanctum::actingAs($viewer);
        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.following_count', 1)
            ->assertJsonPath('data.follower_count', 0);

        Sanctum::actingAs($host->user);
        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.following_count', 0)
            ->assertJsonPath('data.follower_count', 1)
            ->assertJsonPath('data.host_id', $host->id);
    }

    public function test_following_and_followers_endpoints_work_and_normal_user_cannot_view_followers(): void
    {
        [$viewer, $host] = $this->viewerAndHost();
        HostFollower::query()->create([
            'host_id' => $host->id,
            'user_id' => $viewer->id,
        ]);

        Sanctum::actingAs($viewer);
        $this->getJson('/api/me/following')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.host_id', $host->id);

        $this->getJson('/api/me/followers')->assertStatus(403);

        Sanctum::actingAs($host->user);
        $this->getJson('/api/me/followers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $viewer->id);
    }

    public function test_live_users_api_includes_follow_state_follower_count_and_sorted_hosts(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('user');

        [$availableUser, $availableHost] = $this->hostPair('Available');
        [$busyUser, $busyHost] = $this->hostPair('Busy');
        [$offlineUser, $offlineHost] = $this->hostPair('Offline');

        HostFollower::query()->create([
            'host_id' => $busyHost->id,
            'user_id' => $viewer->id,
        ]);

        $availability = app(HostAvailabilityService::class);
        $availability->toggleManualStatus($availableUser, 'online');
        $availability->updateSocketStatus($availableUser->id, 'online');

        $availability->toggleManualStatus($busyUser, 'online');
        $availability->updateSocketStatus($busyUser->id, 'online');
        $availability->setCallStatus($busyUser->id, 'busy');

        $availability->toggleManualStatus($offlineUser, 'offline');
        $availability->updateSocketStatus($offlineUser->id, 'offline');

        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/live-users')->assertOk();
        $users = $response->json('data.users');

        $this->assertSame($availableUser->id, $users[0]['id']);
        $this->assertSame($busyUser->id, $users[1]['id']);
        $this->assertSame($offlineUser->id, $users[2]['id']);
        $this->assertTrue($users[1]['is_following']);
        $this->assertSame(1, $users[1]['follower_count']);
        $this->assertTrue($users[0]['is_online']);
        $this->assertTrue($users[0]['is_available']);
        $this->assertTrue($users[1]['is_busy']);
    }

    public function test_online_and_available_transitions_notify_followers_with_cooldown(): void
    {
        [$viewer, $host] = $this->viewerAndHost();
        $hostUser = $host->user;

        HostFollower::query()->create([
            'host_id' => $host->id,
            'user_id' => $viewer->id,
        ]);

        $availability = app(HostAvailabilityService::class);
        $availability->toggleManualStatus($hostUser, 'online');
        $availability->updateSocketStatus($hostUser->id, 'online');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $viewer->id,
            'type' => 'host_online',
        ]);

        $availability->setCallStatus($hostUser->id, 'busy');
        $availability->setCallStatus($hostUser->id, 'available');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $viewer->id,
            'type' => 'host_available',
        ]);

        $availability->updateSocketStatus($hostUser->id, 'offline');
        $availability->updateSocketStatus($hostUser->id, 'online');

        $this->assertSame(1, UserNotification::query()->where('type', 'host_online')->count());
    }

    public function test_unfollowed_user_does_not_receive_host_status_notification(): void
    {
        [$viewer, $host] = $this->viewerAndHost();
        $hostUser = $host->user;

        HostFollower::query()->create([
            'host_id' => $host->id,
            'user_id' => $viewer->id,
        ]);
        HostFollower::query()->where('host_id', $host->id)->where('user_id', $viewer->id)->delete();

        $availability = app(HostAvailabilityService::class);
        $availability->toggleManualStatus($hostUser, 'online');
        $availability->updateSocketStatus($hostUser->id, 'online');

        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $viewer->id,
            'type' => 'host_online',
        ]);
    }

    public function test_admin_can_view_host_follower_report(): void
    {
        [$viewer, $host] = $this->viewerAndHost();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        HostFollower::query()->create([
            'host_id' => $host->id,
            'user_id' => $viewer->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.reports.host-followers'))
            ->assertOk()
            ->assertSee('Host Followers')
            ->assertSee($viewer->name);
    }

    private function viewerAndHost(): array
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('user');
        [, $host] = $this->hostPair('Nova');

        return [$viewer, $host];
    }

    private function hostPair(string $name): array
    {
        $user = User::factory()->create(['name' => "{$name} User"]);
        $user->assignRole('host');
        $host = Host::query()->create([
            'user_id' => $user->id,
            'stage_name' => $name,
        ]);

        return [$user, $host];
    }
}
