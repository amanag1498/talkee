<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['admin','agency','host','user'] as $name) {
            Role::findOrCreate($name); // idempotent
        }
    }
}
