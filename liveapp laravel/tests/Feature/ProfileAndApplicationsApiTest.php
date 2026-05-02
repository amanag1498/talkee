<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyRequest;
use App\Models\Host;
use App\Models\HostEnrollRequest;
use App\Models\HostRequest;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
