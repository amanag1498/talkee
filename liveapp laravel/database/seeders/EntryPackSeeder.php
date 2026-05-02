<?php

namespace Database\Seeders;

use App\Models\EntryPack;
use Illuminate\Database\Seeder;

class EntryPackSeeder extends Seeder
{
    public function run(): void
    {
        $packs = [
            [
                'name' => 'Basic Entry',
                'price_coins' => 0,
                'svg_url' => 'https://upload.wikimedia.org/wikipedia/commons/0/02/SVG_logo.svg',
                'animation_style' => 'banner',
                'priority' => 1,
                'duration_ms' => 2500,
                'duration_days' => 30,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'VIP Entry',
                'price_coins' => 100,
                'svg_url' => 'https://upload.wikimedia.org/wikipedia/commons/b/bd/Test.svg',
                'animation_style' => 'center',
                'priority' => 2,
                'duration_ms' => 3000,
                'duration_days' => 30,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Royal Entry',
                'price_coins' => 500,
                'svg_url' => 'https://upload.wikimedia.org/wikipedia/commons/6/6b/Bitmap_VS_SVG.svg',
                'animation_style' => 'fullscreen',
                'priority' => 3,
                'duration_ms' => 3500,
                'duration_days' => 30,
                'is_active' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($packs as $pack) {
            EntryPack::query()->updateOrCreate(
                ['name' => $pack['name']],
                $pack,
            );
        }
    }
}
