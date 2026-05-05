<?php

namespace Database\Seeders;

use App\Models\UserLevel;
use Illuminate\Database\Seeder;

class UserLevelSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            ['level' => 1, 'title' => 'Newbie', 'min_spend_coins' => 0, 'badge_icon' => 'sparkles', 'badge_color' => '#8A63E8', 'sort_order' => 10],
            ['level' => 2, 'title' => 'Rising Star', 'min_spend_coins' => 1000, 'badge_icon' => 'trending_up', 'badge_color' => '#4BE3C2', 'sort_order' => 20],
            ['level' => 3, 'title' => 'Popular', 'min_spend_coins' => 5000, 'badge_icon' => 'whatshot', 'badge_color' => '#5EA1FF', 'sort_order' => 30],
            ['level' => 4, 'title' => 'Super Fan', 'min_spend_coins' => 10000, 'badge_icon' => 'favorite', 'badge_color' => '#FF8A65', 'sort_order' => 40],
            ['level' => 5, 'title' => 'VIP', 'min_spend_coins' => 25000, 'badge_icon' => 'workspace_premium', 'badge_color' => '#FFC857', 'sort_order' => 50],
            ['level' => 6, 'title' => 'Elite', 'min_spend_coins' => 50000, 'badge_icon' => 'military_tech', 'badge_color' => '#FF6B9A', 'sort_order' => 60],
            ['level' => 7, 'title' => 'Royal', 'min_spend_coins' => 100000, 'badge_icon' => 'diamond', 'badge_color' => '#9A7EF0', 'sort_order' => 70],
            ['level' => 8, 'title' => 'Legend', 'min_spend_coins' => 250000, 'badge_icon' => 'auto_awesome', 'badge_color' => '#2DD4BF', 'sort_order' => 80],
            ['level' => 9, 'title' => 'Icon', 'min_spend_coins' => 500000, 'badge_icon' => 'bolt', 'badge_color' => '#60A5FA', 'sort_order' => 90],
            ['level' => 10, 'title' => 'Superstar', 'min_spend_coins' => 1000000, 'badge_icon' => 'stars', 'badge_color' => '#F472B6', 'sort_order' => 100],
        ];

        $threshold = 1000000;
        $palette = [
            '#8A63E8',
            '#4BE3C2',
            '#5EA1FF',
            '#FF8A65',
            '#FFC857',
            '#FF6B9A',
            '#9A7EF0',
            '#2DD4BF',
            '#60A5FA',
            '#F472B6',
        ];

        for ($level = 11; $level <= 100; $level++) {
            $threshold += 500000 + (($level - 11) * 50000);
            $levels[] = [
                'level' => $level,
                'title' => 'Level '.$level,
                'min_spend_coins' => $threshold,
                'badge_icon' => 'stars',
                'badge_color' => $palette[($level - 1) % count($palette)],
                'sort_order' => $level * 10,
            ];
        }

        foreach ($levels as $data) {
            UserLevel::query()->updateOrCreate(
                ['level' => $data['level']],
                array_merge($data, [
                    'benefits' => [],
                    'is_active' => true,
                ])
            );
        }
    }
}
