<?php

namespace Database\Seeders;

use App\Models\RechargePlan;
use Illuminate\Database\Seeder;

class RechargePlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['title' => 'Starter 500', 'amount_rupees' => 49, 'coins' => 450, 'bonus_coins' => 50, 'total_coins' => 500, 'sort_order' => 10],
            ['title' => 'Boost 1100', 'amount_rupees' => 99, 'coins' => 1000, 'bonus_coins' => 100, 'total_coins' => 1100, 'sort_order' => 20],
            ['title' => 'Popular 2300', 'amount_rupees' => 199, 'coins' => 2100, 'bonus_coins' => 200, 'total_coins' => 2300, 'sort_order' => 30],
            ['title' => 'Creator 6000', 'amount_rupees' => 499, 'coins' => 5500, 'bonus_coins' => 500, 'total_coins' => 6000, 'sort_order' => 40],
            ['title' => 'Legend 13000', 'amount_rupees' => 999, 'coins' => 12000, 'bonus_coins' => 1000, 'total_coins' => 13000, 'sort_order' => 50],
        ];

        foreach ($plans as $plan) {
            RechargePlan::query()->updateOrCreate(
                ['title' => $plan['title']],
                $plan + ['is_active' => true]
            );
        }
    }
}
