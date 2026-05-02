<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DevBootstrapSeeder::class,
            WalletLifecycleDemoSeeder::class,
            RealWorldSimulationSeeder::class,
        ]);
    }
}
