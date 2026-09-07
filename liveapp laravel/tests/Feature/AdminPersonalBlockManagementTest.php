<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPersonalBlockManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_admin_can_view_filter_and_remove_personal_blocks_with_audit(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $blocker = User::factory()->create(['name' => 'Safety Tester']);
        $blocked = User::factory()->create(['name' => 'Hidden User']);
        $block = UserBlock::query()->create([
            'blocker_user_id' => $blocker->id,
            'blocked_user_id' => $blocked->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.moderation.personal-blocks', ['q' => 'Safety Tester']))
            ->assertOk()
            ->assertSee('Personal User Blocks')
            ->assertSee('Hidden User');

        $this->actingAs($admin)
            ->delete(route('admin.moderation.personal-blocks.destroy', $block), [
                'reason' => 'Resolved support request',
            ])
            ->assertRedirect()
            ->assertSessionHas('ok');

        $this->assertDatabaseMissing('user_blocks', ['id' => $block->id]);
        $this->assertDatabaseHas('admin_action_audits', [
            'admin_user_id' => $admin->id,
            'target_user_id' => $blocked->id,
            'area' => 'moderation',
            'action' => 'personal_block_removed',
            'reason' => 'Resolved support request',
        ]);
    }

    public function test_admin_api_can_list_and_remove_personal_blocks(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $blocker = User::factory()->create(['name' => 'API Blocker']);
        $blocked = User::factory()->create(['name' => 'API Target']);
        $block = UserBlock::query()->create([
            'blocker_user_id' => $blocker->id,
            'blocked_user_id' => $blocked->id,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/personal-blocks?q=API Blocker')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.blocker.name', 'API Blocker');
        $this->deleteJson('/api/admin/personal-blocks/'.$block->id, [
            'reason' => 'API support override',
        ])->assertOk();

        $this->assertDatabaseMissing('user_blocks', ['id' => $block->id]);
    }
}
