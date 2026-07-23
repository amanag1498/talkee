<?php

namespace Tests\Feature;

use App\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BannerMediaDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploaded_banner_is_returned_through_the_media_route(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('banners/promo.png', 'banner-image');
        Storage::disk('public')->put('banners/legacy.webp', 'legacy-banner');

        $banner = Banner::query()->create([
            'title' => 'Promo',
            'image_url' => '/storage/banners/promo.png',
            'placement' => 'home',
            'action_type' => 'none',
            'platforms' => [],
            'target_roles' => [],
            'is_active' => true,
        ]);
        Banner::query()->create([
            'title' => 'Legacy promo',
            'image_url' => 'https://old.example/storage/banners/legacy.webp',
            'placement' => 'home',
            'action_type' => 'none',
            'platforms' => [],
            'target_roles' => [],
            'is_active' => true,
        ]);

        $this->getJson('/api/banners?placement=home&platform=android&role=user')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $banner->id,
                'image_url' => url('/media/banner/banners/promo.png'),
            ])
            ->assertJsonFragment([
                'title' => 'Legacy promo',
                'image_url' => url('/media/banner/banners/legacy.webp'),
            ]);

        $this->get('/media/banner/banners/promo.png')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }
}
