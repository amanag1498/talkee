<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class CommonSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesSeeder::class,
            AppSettingsSeeder::class,
            SubscriptionPlanSeeder::class,
            RechargePlanSeeder::class,
            UserLevelSeeder::class,
            ThemeSeeder::class,
            GiftSeeder::class,
            ModerationRuleSeeder::class,
            EntryPackSeeder::class,
            FortuneWheelSeeder::class,
        ]);
    }
}
