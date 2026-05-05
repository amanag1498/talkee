<?php

namespace Tests\Feature;

use App\Models\ProfileFrame;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserProfileFrame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProfileFrameGrantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_admin_can_assign_and_revoke_profile_frame_for_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $user = User::factory()->create();
        $user->assignRole('user');

        $frame = ProfileFrame::query()->where('slug', 'crimson-heart-halo')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.users.profile-frames.store', $user), [
                'profile_frame_id' => $frame->id,
                'auto_equip' => 1,
                'reason' => 'Weekly top gifter reward',
            ])
            ->assertRedirect();

        $ownership = UserProfileFrame::query()
            ->where('user_id', $user->id)
            ->where('profile_frame_id', $frame->id)
            ->firstOrFail();

        $this->assertTrue($ownership->is_equipped);
        $this->assertSame('admin_grant', $ownership->source);

        $notification = UserNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'profile_frame_unlocked')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('Profile frame granted', $notification->title);
        $this->assertSame($frame->slug, data_get($notification->meta, 'profile_frame.slug'));

        $this->actingAs($admin)
            ->delete(route('admin.users.profile-frames.destroy', [$user, $ownership]), [
                'reason' => 'Reward window ended',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('user_profile_frames', [
            'id' => $ownership->id,
        ]);
    }
}
