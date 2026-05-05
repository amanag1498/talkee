<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $mapping = [
        'blush-laurel-crown' => 'weekly_top_gifter',
        'silver-sapphire-crown' => 'weekly_top_agency',
        'scarlet-regal-crown' => 'weekly_top_host',
        'silver-amethyst-crown' => 'alltime_top_gifter',
        'ivory-laurel-crown' => 'alltime_top_agency',
        'obsidian-crown-laurel' => 'alltime_top_host',
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->mapping as $slug => $unlockType) {
            DB::table('profile_frames')
                ->where('slug', $slug)
                ->update([
                    'unlock_type' => $unlockType,
                    'valid_days' => 7,
                    'meta' => json_encode([
                        'reward_days' => 7,
                        'managed_by' => 'leaderboard_admin_trigger',
                    ]),
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->mapping) as $slug) {
            DB::table('profile_frames')
                ->where('slug', $slug)
                ->update([
                    'unlock_type' => 'free_catalog',
                    'valid_days' => null,
                    'meta' => null,
                    'updated_at' => now(),
                ]);
        }
    }
};
