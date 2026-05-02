<?php

namespace Database\Seeders;

use App\Models\Gift;
use Illuminate\Database\Seeder;

class GiftSeeder extends Seeder
{
    public function run(): void
    {
        $gifts = [
            [
                'name' => 'Rose',
                'coins' => 25,
                'gift_url' => 'https://picsum.photos/seed/liveapp-rose/320/320',
                'gift_type' => 'image',
                'animation_tier' => 'small',
                'animation_duration_ms' => 1400,
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'name' => 'Heart',
                'coins' => 199,
                'gift_url' => 'https://upload.wikimedia.org/wikipedia/commons/4/42/Love_Heart_SVG.svg',
                'gift_type' => 'svg',
                'animation_tier' => 'medium',
                'animation_duration_ms' => 2400,
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'name' => 'Sparkle',
                'coins' => 75,
                'gift_url' => 'https://picsum.photos/seed/liveapp-sparkle/320/320',
                'gift_type' => 'image',
                'animation_tier' => 'small',
                'animation_duration_ms' => 1500,
                'is_active' => true,
                'sort_order' => 25,
            ],
            [
                'name' => 'Crown',
                'coins' => 12000,
                'gift_url' => 'https://picsum.photos/seed/liveapp-crown/720/720',
                'gift_type' => 'image',
                'animation_tier' => 'legendary',
                'animation_duration_ms' => 6800,
                'is_active' => true,
                'sort_order' => 80,
            ],
            [
                'name' => 'Rocket',
                'coins' => 1299,
                'gift_url' => 'https://picsum.photos/seed/liveapp-rocket/640/640',
                'gift_type' => 'image',
                'animation_tier' => 'premium',
                'animation_duration_ms' => 5000,
                'is_active' => true,
                'sort_order' => 50,
            ],
            [
                'name' => 'Fireworks',
                'coins' => 699,
                'gift_url' => 'https://picsum.photos/seed/liveapp-fireworks/420/420',
                'gift_type' => 'image',
                'animation_tier' => 'medium',
                'animation_duration_ms' => 2600,
                'is_active' => true,
                'sort_order' => 40,
            ],
            [
                'name' => 'Diamond Ring',
                'coins' => 3999,
                'gift_url' => 'https://picsum.photos/seed/liveapp-diamond-ring/640/640',
                'gift_type' => 'image',
                'animation_tier' => 'premium',
                'animation_duration_ms' => 5200,
                'is_active' => true,
                'sort_order' => 60,
            ],
            [
                'name' => 'Phoenix',
                'coins' => 24999,
                'gift_url' => 'https://picsum.photos/seed/liveapp-phoenix/720/720',
                'gift_type' => 'image',
                'animation_tier' => 'legendary',
                'animation_duration_ms' => 7200,
                'is_active' => true,
                'sort_order' => 90,
            ],
            [
                'name' => 'Galaxy',
                'coins' => 8999,
                'gift_url' => 'https://picsum.photos/seed/liveapp-galaxy/640/640',
                'gift_type' => 'image',
                'animation_tier' => 'premium',
                'animation_duration_ms' => 5600,
                'is_active' => true,
                'sort_order' => 70,
            ],
        ];

        foreach ($gifts as $gift) {
            Gift::query()->updateOrCreate(
                ['name' => $gift['name']],
                $gift,
            );
        }
    }
}
