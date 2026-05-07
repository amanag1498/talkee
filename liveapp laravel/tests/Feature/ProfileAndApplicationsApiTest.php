<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyRequest;
use App\Models\CallSession;
use App\Models\Host;
use App\Models\HostEnrollRequest;
use App\Models\HostRequest;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfileAndApplicationsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_profile_endpoint_returns_wallet_and_roles(): void
    {
        $user = User::factory()->create(['name' => 'Talkee User']);
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 450]);

        Sanctum::actingAs($user);

        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.name', 'Talkee User')
            ->assertJsonPath('data.wallet_balance', 450)
            ->assertJsonPath('data.roles.0', 'user');
    }

    public function test_my_applications_endpoint_aggregates_all_application_types(): void
    {
        $user = User::factory()->create();
        $user->assignRole('host');
        $agencyOwner = User::factory()->create();
        $agency = Agency::query()->create([
            'name' => 'Prime Agency',
            'owner_user_id' => $agencyOwner->id,
        ]);
        Host::query()->create(['user_id' => $user->id, 'stage_name' => 'Nova']);

        AgencyRequest::query()->create([
            'user_id' => $user->id,
            'agency_name' => 'Creator Circle',
            'status' => 'rejected',
            'review_notes' => 'Missing company profile.',
        ]);
        HostRequest::query()->create([
            'user_id' => $user->id,
            'stage_name' => 'Nova',
            'status' => 'approved',
        ]);
        HostEnrollRequest::query()->create([
            'host_user_id' => $user->id,
            'agency_id' => $agency->id,
            'status' => 'pending',
            'message' => 'Would like to join.',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/me/applications')->assertOk();
        $apps = $response->json('data.applications');

        $this->assertCount(3, $apps);
        $this->assertSame(['agency', 'host', 'host_enroll'], collect($apps)->pluck('type')->sort()->values()->all());
        $this->assertSame(
            'Missing company profile.',
            collect($apps)->firstWhere('type', 'agency')['review_notes'] ?? null
        );
    }

    public function test_avatar_upload_updates_profile_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $response = $this->post('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 1200, 1200),
        ], [
            'Accept' => 'application/json',
        ])->assertOk();

        $user->refresh();
        $rawAvatar = (string) $user->getRawOriginal('avatar_url');

        $this->assertStringStartsWith('avatars/avatar_', $rawAvatar);
        Storage::disk('public')->assertExists($rawAvatar);
        $this->assertNotEmpty($response->json('data.avatar_url'));
    }

    public function test_avatar_upload_deletes_previous_local_avatar_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'avatar_url' => 'avatars/old_avatar.jpg',
        ]);
        $user->assignRole('user');
        Storage::disk('public')->put('avatars/old_avatar.jpg', 'old-avatar');

        Sanctum::actingAs($user);

        $this->post('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('new-avatar.jpg', 1200, 1200),
        ], [
            'Accept' => 'application/json',
        ])->assertOk();

        $user->refresh();
        $rawAvatar = (string) $user->getRawOriginal('avatar_url');

        Storage::disk('public')->assertMissing('avatars/old_avatar.jpg');
        Storage::disk('public')->assertExists($rawAvatar);
    }

    public function test_avatar_upload_does_not_treat_external_avatar_as_local_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'avatar_url' => 'https://cdn.example.com/default-avatar.png',
        ]);
        $user->assignRole('user');

        Sanctum::actingAs($user);

        $this->post('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('remote-replace.jpg', 1200, 1200),
        ], [
            'Accept' => 'application/json',
        ])->assertOk();

        $user->refresh();
        $rawAvatar = (string) $user->getRawOriginal('avatar_url');

        $this->assertStringStartsWith('avatars/avatar_', $rawAvatar);
        Storage::disk('public')->assertExists($rawAvatar);
    }

    public function test_host_earnings_report_uses_completed_call_sessions_for_call_metrics(): void
    {
        $hostUser = User::factory()->create();
        $hostUser->assignRole('host');
        $caller = User::factory()->create();
        $caller->assignRole('user');

        $host = Host::query()->create([
            'user_id' => $hostUser->id,
            'stage_name' => 'Nova',
        ]);

        CallSession::query()->create([
            'caller_id' => $caller->id,
            'receiver_id' => $hostUser->id,
            'host_id' => $host->id,
            'agency_id' => null,
            'type' => 'audio',
            'status' => 'ended',
            'started_at' => now()->startOfWeek()->addDay()->setHour(12),
            'accepted_at' => now()->startOfWeek()->addDay()->setHour(12),
            'ended_at' => now()->startOfWeek()->addDay()->setHour(12)->addMinutes(3),
            'duration_seconds' => 180,
            'billable_minutes' => 3,
            'coin_rate_per_minute' => 20,
            'total_coins_charged' => 60,
            'host_earning' => 36,
            'agency_earning' => 0,
            'platform_earning' => 24,
            'end_reason' => 'completed',
            'billing_processed_at' => now()->startOfWeek()->addDay()->setHour(12)->addMinutes(3),
        ]);

        CallSession::query()->create([
            'caller_id' => $caller->id,
            'receiver_id' => $hostUser->id,
            'host_id' => $host->id,
            'agency_id' => null,
            'type' => 'video',
            'status' => 'ended',
            'started_at' => now()->startOfWeek()->addDays(2)->setHour(15),
            'accepted_at' => now()->startOfWeek()->addDays(2)->setHour(15),
            'ended_at' => now()->startOfWeek()->addDays(2)->setHour(15)->addMinutes(4),
            'duration_seconds' => 240,
            'billable_minutes' => 4,
            'coin_rate_per_minute' => 20,
            'total_coins_charged' => 80,
            'host_earning' => 48,
            'agency_earning' => 0,
            'platform_earning' => 32,
            'end_reason' => 'completed',
            'billing_processed_at' => now()->startOfWeek()->addDays(2)->setHour(15)->addMinutes(4),
        ]);

        Sanctum::actingAs($hostUser);

        $this->getJson('/api/profile/host-earnings-report')
            ->assertOk()
            ->assertJsonPath('data.current_week.summary.audio_call_minutes', 3)
            ->assertJsonPath('data.current_week.summary.audio_call_earnings', 36)
            ->assertJsonPath('data.current_week.summary.video_call_minutes', 4)
            ->assertJsonPath('data.current_week.summary.video_call_earnings', 48);
    }
}
