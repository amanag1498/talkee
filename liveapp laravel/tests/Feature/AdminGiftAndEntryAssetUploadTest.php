<?php

namespace Tests\Feature;

use App\Models\EntryPack;
use App\Models\Gift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminGiftAndEntryAssetUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'super-admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Storage::fake('public');
    }

    public function test_admin_can_create_and_replace_gift_svga_asset(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->post(route('admin.gifts.store'), [
            'name' => 'Fireworks',
            'coins' => 700,
            'gift_type' => 'auto',
            'animation_tier' => 'premium',
            'animation_duration_ms' => 2600,
            'sort_order' => 1,
            'is_active' => 1,
            'gift_file' => UploadedFile::fake()->createWithContent('fireworks.svga', 'svga-data'),
        ])->assertRedirect(route('admin.gifts.index'));

        $gift = Gift::query()->firstOrFail();
        $firstPath = (string) $gift->getRawOriginal('gift_url');

        $this->assertSame('svga', $gift->gift_type);
        $this->assertStringEndsWith('.svga', $firstPath);
        Storage::disk('public')->assertExists($firstPath);

        $this->actingAs($admin)->put(route('admin.gifts.update', $gift), [
            'name' => 'Fireworks XL',
            'coins' => 750,
            'gift_type' => 'auto',
            'animation_tier' => 'legendary',
            'animation_duration_ms' => 3000,
            'sort_order' => 2,
            'is_active' => 1,
            'gift_file' => UploadedFile::fake()->createWithContent(
                'fireworks-updated.svg',
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"></svg>',
            ),
        ])->assertRedirect(route('admin.gifts.index'));

        $gift->refresh();
        $secondPath = (string) $gift->getRawOriginal('gift_url');

        $this->assertSame('svg', $gift->gift_type);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_admin_can_create_and_replace_entry_pack_asset(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->post(route('admin.entry-packs.store'), [
            'name' => 'Aurora Burst',
            'price_coins' => 250,
            'animation_style' => 'fullscreen',
            'priority' => 3,
            'duration_ms' => 3200,
            'duration_days' => 45,
            'sort_order' => 1,
            'is_active' => 1,
            'asset_file' => UploadedFile::fake()->createWithContent('aurora.svga', 'svga-data'),
        ])->assertRedirect(route('admin.entry-packs.index'));

        $pack = EntryPack::query()->firstOrFail();
        $firstPath = (string) $pack->getRawOriginal('svg_url');
        $this->assertStringEndsWith('.svga', $firstPath);
        Storage::disk('public')->assertExists($firstPath);

        $this->actingAs($admin)->put(route('admin.entry-packs.update', $pack), [
            'name' => 'Aurora Burst',
            'price_coins' => 250,
            'animation_style' => 'center',
            'priority' => 4,
            'duration_ms' => 3000,
            'duration_days' => 30,
            'sort_order' => 2,
            'is_active' => 1,
            'asset_file' => UploadedFile::fake()->createWithContent(
                'aurora.svg',
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"></svg>',
            ),
        ])->assertRedirect(route('admin.entry-packs.index'));

        $pack->refresh();
        $secondPath = (string) $pack->getRawOriginal('svg_url');

        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }
}
