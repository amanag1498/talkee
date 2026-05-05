<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $rewardFrames = [
        1 => 'crimson-crown-laurel',
        11 => 'sapphire-crown-laurel',
        21 => 'amethyst-crown-laurel',
        31 => 'emerald-crown-laurel',
        41 => 'rose-orbit-crown',
        51 => 'ruby-crown-laurel',
        61 => 'azure-crown-laurel',
        71 => 'imperial-violet-crown',
        81 => 'royal-sapphire-crown',
        91 => 'emerald-throne-crown',
    ];

    public function up(): void
    {
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

        $threshold = 1000000;
        $rows = [];
        $now = now();

        for ($level = 11; $level <= 100; $level++) {
            $threshold += 500000 + (($level - 11) * 50000);
            $rows[] = [
                'level' => $level,
                'title' => 'Level '.$level,
                'min_spend_coins' => $threshold,
                'badge_icon' => 'stars',
                'badge_color' => $palette[($level - 1) % count($palette)],
                'benefits' => json_encode([]),
                'is_active' => true,
                'sort_order' => $level * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('user_levels')->upsert(
            $rows,
            ['level'],
            ['title', 'min_spend_coins', 'badge_icon', 'badge_color', 'benefits', 'is_active', 'sort_order', 'updated_at']
        );

        foreach ($this->rewardFrames as $startLevel => $slug) {
            DB::table('profile_frames')
                ->where('slug', $slug)
                ->update([
                    'unlock_type' => 'level_reward',
                    'meta' => json_encode([
                        'level_range_start' => $startLevel,
                        'level_range_end' => min(100, $startLevel + 9),
                    ]),
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        DB::table('user_levels')
            ->whereBetween('level', [11, 100])
            ->delete();

        foreach ($this->rewardFrames as $slug) {
            DB::table('profile_frames')
                ->where('slug', $slug)
                ->update([
                    'unlock_type' => 'free_catalog',
                    'meta' => null,
                    'updated_at' => now(),
                ]);
        }
    }
};
