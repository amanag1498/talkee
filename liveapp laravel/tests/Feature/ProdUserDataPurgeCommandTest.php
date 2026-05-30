<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProdUserDataPurgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProdUserDataPurgeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_requires_exact_confirmation_for_real_run(): void
    {
        User::factory()->create(['id' => 1]);
        User::factory()->create();

        $this->artisan('prod:purge-user-data', ['--keep-user' => 1])
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 2);
    }

    public function test_purge_keeps_admin_and_catalogs_but_deletes_user_host_agency_data(): void
    {
        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $admin = User::factory()->create(['id' => 1, 'email' => 'admin@example.test']);
        $user = User::factory()->create(['email' => 'user@example.test']);

        $admin->assignRole('admin');
        $user->assignRole('user');

        $this->seedCatalogData();
        $this->seedUserOwnedData($admin->id, $user->id);

        $this->artisan('prod:purge-user-data', [
            '--keep-user' => 1,
            '--confirm' => ProdUserDataPurgeService::CONFIRMATION_TOKEN,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('users', ['id' => 1, 'email' => 'admin@example.test']);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseHas('model_has_roles', ['model_id' => 1]);
        $this->assertDatabaseMissing('model_has_roles', ['model_id' => $user->id]);

        foreach (['hosts', 'agencies', 'wallets', 'wallet_transactions', 'user_subscriptions', 'user_entry_packs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('sessions', 1);
        $this->assertDatabaseHas('sessions', ['id' => 'admin-session', 'user_id' => 1]);

        $this->assertDatabaseHas('user_levels', ['title' => 'Starter']);
        $this->assertDatabaseHas('themes', ['key' => 'midnight']);
        $this->assertDatabaseHas('profile_frames', ['slug' => 'starter-frame']);
        $this->assertDatabaseHas('recharge_plans', ['title' => 'Starter Recharge']);
        $this->assertDatabaseHas('gifts', ['name' => 'Rose']);
        $this->assertDatabaseHas('entry_packs', ['name' => 'Gold Entry']);
        $this->assertDatabaseHas('subscription_plans', ['name' => 'VIP']);
    }

    public function test_dry_run_does_not_delete_anything(): void
    {
        User::factory()->create(['id' => 1]);
        User::factory()->create();

        $this->artisan('prod:purge-user-data', [
            '--keep-user' => 1,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('users', 2);
    }

    private function seedCatalogData(): void
    {
        DB::table('user_levels')->insert([
            'level' => 1,
            'title' => 'Starter',
            'min_spend_coins' => 0,
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('recharge_plans')->insert([
            'title' => 'Starter Recharge',
            'amount_rupees' => 99,
            'coins' => 1000,
            'bonus_coins' => 0,
            'total_coins' => 1000,
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('gifts')->insert([
            'name' => 'Rose',
            'coins' => 10,
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('entry_packs')->insert([
            'name' => 'Gold Entry',
            'price_coins' => 100,
            'animation_style' => 'banner',
            'priority' => 1,
            'duration_ms' => 3000,
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('subscription_plans')->insert([
            'name' => 'VIP',
            'price_coins' => 500,
            'duration_days' => 30,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('themes')->insert([
            'key' => 'midnight',
            'name' => 'Midnight',
            'unlock_type' => 'free_catalog',
            'is_active' => true,
            'is_default' => false,
            'is_limited' => false,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('profile_frames')->insert([
            'name' => 'Starter Frame',
            'slug' => 'starter-frame',
            'rarity' => 'rare',
            'category' => 'general',
            'unlock_type' => 'free_catalog',
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedUserOwnedData(int $adminId, int $userId): void
    {
        $agencyId = DB::table('agencies')->insertGetId([
            'owner_user_id' => $userId,
            'name' => 'Demo Agency',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('hosts')->insert([
            'user_id' => $userId,
            'agency_id' => $agencyId,
            'stage_name' => 'Demo Host',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $walletId = DB::table('wallets')->where('user_id', $userId)->value('id');
        if ($walletId) {
            DB::table('wallets')->where('id', $walletId)->update(['balance' => 100, 'updated_at' => now()]);
        } else {
            $walletId = DB::table('wallets')->insertGetId([
                'user_id' => $userId,
                'balance' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('wallet_transactions')->insert([
            'wallet_id' => $walletId,
            'type' => 'credit',
            'amount' => 100,
            'reference' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_subscriptions')->insert([
            'user_id' => $userId,
            'subscription_plan_id' => DB::table('subscription_plans')->value('id'),
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
            'last_purchased_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_entry_packs')->insert([
            'user_id' => $userId,
            'entry_pack_id' => DB::table('entry_packs')->value('id'),
            'is_active' => true,
            'purchased_at' => now(),
            'expires_at' => now()->addDays(7),
            'purchase_key' => 'test-pack',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sessions')->insert([
            'id' => 'admin-session',
            'user_id' => $adminId,
            'payload' => 'admin',
            'last_activity' => time(),
        ]);

        DB::table('sessions')->insert([
            'id' => 'user-session',
            'user_id' => $userId,
            'payload' => 'user',
            'last_activity' => time(),
        ]);
    }
}
