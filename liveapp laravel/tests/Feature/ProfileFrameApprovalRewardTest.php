<?php

namespace Tests\Feature;

use App\Models\AgencyRequest;
use App\Models\HostRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserProfileFrame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfileFrameApprovalRewardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_host_approval_unlocks_host_sovereign_crest(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $user = User::factory()->create();
        $user->assignRole('user');

        $request = HostRequest::query()->create([
            'user_id' => $user->id,
            'stage_name' => 'Star Host',
            'contact_phone' => '9999999999',
            'country' => 'India',
            'city' => 'Delhi',
            'about' => 'Approved host',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.host-requests.update', $request), [
                'action' => 'approve',
                'notes' => 'Approved for launch',
            ])
            ->assertRedirect(route('admin.host-requests.index'));

        $ownership = UserProfileFrame::query()
            ->where('user_id', $user->id)
            ->whereHas('profileFrame', fn ($query) => $query->where('slug', 'host-sovereign-crest'))
            ->first();

        $this->assertNotNull($ownership);
        $this->assertSame('host_reward', $ownership->source);
        $this->assertTrue((bool) $ownership->is_equipped);

        $notification = UserNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'profile_frame_unlocked')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('host-sovereign-crest', data_get($notification->meta, 'profile_frame.slug'));
    }

    public function test_agency_approval_unlocks_lion_king_crest(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $user = User::factory()->create();
        $user->assignRole('user');

        $request = AgencyRequest::query()->create([
            'user_id' => $user->id,
            'agency_name' => 'Alpha Agency',
            'legal_name' => 'Alpha Agency Pvt Ltd',
            'contact_phone' => '9999999999',
            'website' => 'https://example.com',
            'about' => 'Approved agency',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.agency-requests.update', $request), [
                'action' => 'approve',
                'notes' => 'Agency approved',
            ])
            ->assertRedirect(route('admin.agency-requests.index'));

        $ownership = UserProfileFrame::query()
            ->where('user_id', $user->id)
            ->whereHas('profileFrame', fn ($query) => $query->where('slug', 'lion-king-crest'))
            ->first();

        $this->assertNotNull($ownership);
        $this->assertSame('agency_reward', $ownership->source);
        $this->assertTrue((bool) $ownership->is_equipped);

        $notification = UserNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'profile_frame_unlocked')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('lion-king-crest', data_get($notification->meta, 'profile_frame.slug'));
    }
}
