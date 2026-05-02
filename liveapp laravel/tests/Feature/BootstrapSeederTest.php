<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BootstrapSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_roles_and_admin_user(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['email' => env('SEED_ADMIN_EMAIL', 'admin@example.com')]);
        $this->assertTrue(Role::query()->where('name', 'admin')->exists());
        $this->assertTrue(Role::query()->where('name', 'host')->exists());
        $this->assertDatabaseHas('agencies', ['name' => 'Prime Talent Agency']);
        $this->assertDatabaseHas('hosts', ['stage_name' => 'Host Nova']);
        $this->assertDatabaseHas('wallets', ['balance' => 2500]);
    }
}
