<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $frames = [
        [
            'name' => 'Crimson Crown Laurel',
            'slug' => 'crimson-crown-laurel',
            'asset_url' => 'profile-frames/seed/crown_00.png',
            'thumbnail_url' => 'profile-frames/seed/crown_00.png',
            'rarity' => 'legendary',
            'category' => 'crown',
            'unlock_type' => 'free_catalog',
            'sort_order' => 100,
            'is_active' => true,
        ],
        [
            'name' => 'Sapphire Crown Laurel',
            'slug' => 'sapphire-crown-laurel',
            'asset_url' => 'profile-frames/seed/crown_01.png',
            'thumbnail_url' => 'profile-frames/seed/crown_01.png',
            'rarity' => 'epic',
            'category' => 'crown',
            'unlock_type' => 'free_catalog',
            'sort_order' => 110,
            'is_active' => true,
        ],
        [
            'name' => 'Amethyst Crown Laurel',
            'slug' => 'amethyst-crown-laurel',
            'asset_url' => 'profile-frames/seed/crown_02.png',
            'thumbnail_url' => 'profile-frames/seed/crown_02.png',
            'rarity' => 'epic',
            'category' => 'crown',
            'unlock_type' => 'free_catalog',
            'sort_order' => 120,
            'is_active' => true,
        ],
        [
            'name' => 'Emerald Crown Laurel',
            'slug' => 'emerald-crown-laurel',
            'asset_url' => 'profile-frames/seed/crown_03.png',
            'thumbnail_url' => 'profile-frames/seed/crown_03.png',
            'rarity' => 'epic',
            'category' => 'crown',
            'unlock_type' => 'free_catalog',
            'sort_order' => 130,
            'is_active' => true,
        ],
        [
            'name' => 'Rose Orbit Crown',
            'slug' => 'rose-orbit-crown',
            'asset_url' => 'profile-frames/seed/crown_04.png',
            'thumbnail_url' => 'profile-frames/seed/crown_04.png',
            'rarity' => 'legendary',
            'category' => 'crown',
            'unlock_type' => 'free_catalog',
            'sort_order' => 140,
            'is_active' => true,
        ],
        [
            'name' => 'Ruby Crown Laurel',
            'slug' => 'ruby-crown-laurel',
            'asset_url' => 'profile-frames/seed/crown_05.png',
            'thumbnail_url' => 'profile-frames/seed/crown_05.png',
            'rarity' => 'legendary',
            'category' => 'crown',
            'unlock_type' => 'free_catalog',
            'sort_order' => 150,
            'is_active' => true,
        ],
        [
            'name' => 'Azure Crown Laurel',
            'slug' => 'azure-crown-laurel',
            'asset_url' => 'profile-frames/seed/crown_06.png',
            'thumbnail_url' => 'profile-frames/seed/crown_06.png',
            'rarity' => 'epic',
            'category' => 'crown',
            'unlock_type' => 'free_catalog',
            'sort_order' => 160,
            'is_active' => true,
        ],
        [
            'name' => 'Imperial Violet Crown',
            'slug' => 'imperial-violet-crown',
            'asset_url' => 'profile-frames/seed/crown_07.png',
            'thumbnail_url' => 'profile-frames/seed/crown_07.png',
            'rarity' => 'epic',
            'category' => 'crown',
            'unlock_type' => 'free_catalog',
            'sort_order' => 170,
            'is_active' => true,
        ],
        [
            'name' => 'Royal Sapphire Crown',
            'slug' => 'royal-sapphire-crown',
            'asset_url' => 'profile-frames/seed/crown_08.png',
            'thumbnail_url' => 'profile-frames/seed/crown_08.png',
            'rarity' => 'epic',
            'category' => 'crown',
            'unlock_type' => 'free_catalog',
            'sort_order' => 180,
            'is_active' => true,
        ],
        [
            'name' => 'Emerald Throne Crown',
            'slug' => 'emerald-throne-crown',
            'asset_url' => 'profile-frames/seed/crown_09.png',
            'thumbnail_url' => 'profile-frames/seed/crown_09.png',
            'rarity' => 'epic',
            'category' => 'crown',
            'unlock_type' => 'free_catalog',
            'sort_order' => 190,
            'is_active' => true,
        ],
        [
            'name' => 'Blush Laurel Crown',
            'slug' => 'blush-laurel-crown',
            'asset_url' => 'profile-frames/seed/crown_10.png',
            'thumbnail_url' => 'profile-frames/seed/crown_10.png',
            'rarity' => 'legendary',
            'category' => 'crown',
            'unlock_type' => 'free_catalog',
            'sort_order' => 200,
            'is_active' => true,
        ],
        [
            'name' => 'Scarlet Regal Crown',
            'slug' => 'scarlet-regal-crown',
            'asset_url' => 'profile-frames/seed/crown_11.png',
            'thumbnail_url' => 'profile-frames/seed/crown_11.png',
            'rarity' => 'legendary',
            'category' => 'crown',
            'unlock_type' => 'free_catalog',
            'sort_order' => 210,
            'is_active' => true,
        ],
        [
            'name' => 'Silver Sapphire Crown',
            'slug' => 'silver-sapphire-crown',
            'asset_url' => 'profile-frames/seed/crown_12.png',
            'thumbnail_url' => 'profile-frames/seed/crown_12.png',
            'rarity' => 'epic',
            'category' => 'crown',
            'unlock_type' => 'free_catalog',
            'sort_order' => 220,
            'is_active' => true,
        ],
        [
            'name' => 'Silver Amethyst Crown',
            'slug' => 'silver-amethyst-crown',
            'asset_url' => 'profile-frames/seed/crown_13.png',
            'thumbnail_url' => 'profile-frames/seed/crown_13.png',
            'rarity' => 'epic',
            'category' => 'crown',
            'unlock_type' => 'free_catalog',
            'sort_order' => 230,
            'is_active' => true,
        ],
        [
            'name' => 'Obsidian Crown Laurel',
            'slug' => 'obsidian-crown-laurel',
            'asset_url' => 'profile-frames/seed/crown_14.png',
            'thumbnail_url' => 'profile-frames/seed/crown_14.png',
            'rarity' => 'mythic',
            'category' => 'crown',
            'unlock_type' => 'free_catalog',
            'sort_order' => 240,
            'is_active' => true,
        ],
        [
            'name' => 'Ivory Laurel Crown',
            'slug' => 'ivory-laurel-crown',
            'asset_url' => 'profile-frames/seed/crown_15.png',
            'thumbnail_url' => 'profile-frames/seed/crown_15.png',
            'rarity' => 'legendary',
            'category' => 'crown',
            'unlock_type' => 'free_catalog',
            'sort_order' => 250,
            'is_active' => true,
        ],
    ];

    public function up(): void
    {
        $now = now();
        $rows = array_map(function (array $frame) use ($now): array {
            return [
                ...$frame,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $this->frames);

        DB::table('profile_frames')->upsert(
            $rows,
            ['slug'],
            ['name', 'asset_url', 'thumbnail_url', 'rarity', 'category', 'unlock_type', 'sort_order', 'is_active', 'updated_at']
        );
    }

    public function down(): void
    {
        DB::table('profile_frames')
            ->whereIn('slug', array_column($this->frames, 'slug'))
            ->delete();
    }
};
