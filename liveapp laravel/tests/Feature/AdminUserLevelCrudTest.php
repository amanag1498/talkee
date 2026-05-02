<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUserLevelCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_admin_can_create_update_and_delete_level(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.levels.store'), [
                'level' => 11,
                'title' => 'Meteor',
                'min_spend_coins' => 1500000,
                'badge_icon' => 'rocket',
                'badge_color' => '#FFAA44',
                'benefits' => "Priority support\nEarly access",
                'is_active' => 1,
                'sort_order' => 11,
            ])
            ->assertRedirect(route('admin.levels.index'));

        $level = UserLevel::query()->where('level', 11)->firstOrFail();
        $this->assertSame(['Priority support', 'Early access'], $level->benefits);

        $this->actingAs($admin)
            ->put(route('admin.levels.update', $level), [
                'level' => 11,
                'title' => 'Meteor Plus',
                'min_spend_coins' => 1750000,
                'badge_icon' => 'rocket_launch',
                'badge_color' => '#FFBB55',
                'benefits' => '["VIP queue","Exclusive gifts"]',
                'is_active' => 0,
                'sort_order' => 12,
            ])
            ->assertRedirect(route('admin.levels.index'));

        $level->refresh();
        $this->assertSame('Meteor Plus', $level->title);
        $this->assertSame(['VIP queue', 'Exclusive gifts'], $level->benefits);
        $this->assertFalse($level->is_active);

        $this->actingAs($admin)
            ->delete(route('admin.levels.destroy', $level))
            ->assertRedirect(route('admin.levels.index'));

        $this->assertDatabaseMissing('user_levels', ['id' => $level->id]);
    }

    public function test_admin_cannot_delete_level_when_users_are_assigned(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $level = UserLevel::query()->create([
            'level' => 2,
            'title' => 'Rising Star',
            'min_spend_coins' => 1000,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        User::factory()->create([
            'level_id' => $level->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.levels.destroy', $level))
            ->assertRedirect(route('admin.levels.index'));

        $this->assertDatabaseHas('user_levels', ['id' => $level->id]);
    }
}
