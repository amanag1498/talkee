<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\CallEarningLedger;
use App\Models\CallSession;
use App\Models\Host;
use App\Models\LiveRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AgencyReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_agency_backfill_command_fills_missing_agency_ids(): void
    {
        $owner = User::factory()->create();
        $agency = Agency::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Orbit Agency',
        ]);

        $hostUser = User::factory()->create();
        $host = Host::query()->create([
            'user_id' => $hostUser->id,
            'agency_id' => $agency->id,
            'stage_name' => 'Nova',
        ]);

        $caller = User::factory()->create();

        $call = CallSession::query()->create([
            'caller_id' => $caller->id,
            'receiver_id' => $hostUser->id,
            'host_id' => $host->id,
            'agency_id' => null,
            'type' => 'audio',
            'status' => 'ended',
            'coin_rate_per_minute' => 20,
            'billable_minutes' => 1,
            'total_coins_charged' => 20,
            'host_earning' => 12,
            'agency_earning' => 2,
            'platform_earning' => 6,
        ]);

        CallEarningLedger::query()->create([
            'call_session_id' => $call->id,
            'caller_id' => $caller->id,
            'host_id' => $host->id,
            'agency_id' => null,
            'total_coins' => 20,
            'host_earning' => 12,
            'agency_earning' => 2,
            'platform_earning' => 6,
            'duration_seconds' => 60,
            'billable_minutes' => 1,
        ]);

        Artisan::call('agency:backfill');

        $this->assertDatabaseHas('call_sessions', [
            'id' => $call->id,
            'agency_id' => $agency->id,
        ]);

        $this->assertDatabaseHas('call_earning_ledgers', [
            'call_session_id' => $call->id,
            'agency_id' => $agency->id,
        ]);
    }

    public function test_admin_can_view_agency_reports_and_detail(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $owner = User::factory()->create(['name' => 'Agency Owner']);
        $agency = Agency::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Orbit Agency',
        ]);

        $hostUser = User::factory()->create(['name' => 'Host Nova']);
        $host = Host::query()->create([
            'user_id' => $hostUser->id,
            'agency_id' => $agency->id,
            'stage_name' => 'Nova',
        ]);

        $caller = User::factory()->create(['name' => 'Caller One']);

        CallSession::query()->create([
            'caller_id' => $caller->id,
            'receiver_id' => $hostUser->id,
            'host_id' => $host->id,
            'agency_id' => $agency->id,
            'type' => 'video',
            'status' => 'ended',
            'coin_rate_per_minute' => 45,
            'billable_minutes' => 2,
            'total_coins_charged' => 90,
            'host_earning' => 54,
            'agency_earning' => 9,
            'platform_earning' => 27,
        ]);

        LiveRoom::query()->create([
            'host_id' => $host->id,
            'room_id' => 'room-orbit-1',
            'title' => 'Orbit Live',
            'status' => 'ended',
            'started_at' => now()->subMinutes(20),
            'ended_at' => now()->subMinutes(5),
            'last_activity_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.reports.agencies'))
            ->assertOk()
            ->assertSee('Agency Reports')
            ->assertSee('Orbit Agency')
            ->assertSee('Live Rooms');

        $this->actingAs($admin)
            ->get(route('admin.reports.agencies.show', $agency))
            ->assertOk()
            ->assertSee('Agency Detail')
            ->assertSee('Orbit Agency')
            ->assertSee('Host Nova')
            ->assertSee('Recent Live Rooms');
    }

    public function test_admin_can_view_host_report_detail(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $owner = User::factory()->create();
        $agency = Agency::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Orbit Agency',
        ]);

        $hostUser = User::factory()->create(['name' => 'Host Nova']);
        $host = Host::query()->create([
            'user_id' => $hostUser->id,
            'agency_id' => $agency->id,
            'stage_name' => 'Nova',
        ]);

        $caller = User::factory()->create(['name' => 'Caller One']);

        CallSession::query()->create([
            'caller_id' => $caller->id,
            'receiver_id' => $hostUser->id,
            'host_id' => $host->id,
            'agency_id' => $agency->id,
            'type' => 'audio',
            'status' => 'ended',
            'coin_rate_per_minute' => 20,
            'billable_minutes' => 3,
            'total_coins_charged' => 60,
            'host_earning' => 36,
            'agency_earning' => 6,
            'platform_earning' => 18,
        ]);

        LiveRoom::query()->create([
            'host_id' => $host->id,
            'room_id' => 'room-host-1',
            'title' => 'Nova Live',
            'status' => 'ended',
            'started_at' => now()->subMinutes(18),
            'ended_at' => now()->subMinutes(3),
            'last_activity_at' => now()->subMinutes(3),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.reports.hosts.show', $host))
            ->assertOk()
            ->assertSee('Host Detail')
            ->assertSee('Host Nova')
            ->assertSee('Recent Calls')
            ->assertSee('Recent Live Rooms');
    }

    public function test_avatar_media_route_serves_public_avatar_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/7/test.jpg', 'fake-image');

        $owner = User::factory()->create([
            'avatar_url' => 'avatars/7/test.jpg',
        ]);

        $response = $this->get(route('media.avatar', ['path' => 'avatars/7/test.jpg']));

        $response->assertOk();
        $this->assertStringContainsString('/media/avatar/avatars/7/test.jpg', $owner->avatar_url);
    }
}
