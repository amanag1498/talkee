<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SubscriptionPlan;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        SubscriptionPlan::updateOrCreate(['name'=>'Bronze'], ['price_coins'=>500, 'duration_days'=>30]);
        SubscriptionPlan::updateOrCreate(['name'=>'Silver'], ['price_coins'=>1200,'duration_days'=>30]);
        SubscriptionPlan::updateOrCreate(['name'=>'Gold'],   ['price_coins'=>3000,'duration_days'=>90]);
    }
}
