<?php

namespace Tests\Feature;

use App\Models\ProfileFrame;
use App\Models\User;
use App\Models\UserProfileFrame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfileFrameApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_profile_frames_inventory_is_empty_for_user_with_no_unlocked_frames(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $this->getJson('/api/profile/frames')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_reward_only_frames_are_hidden_from_inventory_until_owned(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/profile/frames')
            ->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();

        $this->assertNotContains('host-sovereign-crest', $slugs);
        $this->assertNotContains('lion-king-crest', $slugs);

        $frame = ProfileFrame::query()->where('slug', 'host-sovereign-crest')->firstOrFail();
        UserProfileFrame::query()->create([
            'user_id' => $user->id,
            'profile_frame_id' => $frame->id,
            'source' => 'host_reward',
            'granted_at' => now(),
            'is_equipped' => true,
        ]);

        $response = $this->getJson('/api/profile/frames')
            ->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('host-sovereign-crest', $slugs);
    }

    public function test_user_can_equip_an_unlocked_profile_frame(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $frame = ProfileFrame::query()->where('slug', 'crimson-heart-halo')->firstOrFail();

        UserProfileFrame::query()->create([
            'user_id' => $user->id,
            'profile_frame_id' => $frame->id,
            'source' => 'admin_grant',
            'granted_at' => now(),
            'is_equipped' => false,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/profile/frames/equip', [
            'profile_frame_id' => $frame->id,
        ])->assertOk()
            ->assertJsonPath('profile_frame.id', $frame->id)
            ->assertJsonPath('profile.id', $user->id)
            ->assertJsonPath('profile.profile_frame.id', $frame->id);

        $this->assertDatabaseHas('user_profile_frames', [
            'user_id' => $user->id,
            'profile_frame_id' => $frame->id,
            'is_equipped' => true,
            'source' => 'admin_grant',
        ]);
    }

    public function test_public_profile_payload_exposes_equipped_profile_frame(): void
    {
        $user = User::factory()->create(['name' => 'Frame User']);
        $viewer = User::factory()->create();
        $user->assignRole('user');
        $viewer->assignRole('user');
        $frame = ProfileFrame::query()->where('slug', 'rose-crown-halo')->firstOrFail();

        UserProfileFrame::query()->create([
            'user_id' => $user->id,
            'profile_frame_id' => $frame->id,
            'source' => 'admin_grant',
            'granted_at' => now(),
            'is_equipped' => true,
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson("/api/profile/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Frame User')
            ->assertJsonPath('data.profile_frame.id', $frame->id)
            ->assertJsonPath('data.profile_frame.slug', 'rose-crown-halo');
    }
}
