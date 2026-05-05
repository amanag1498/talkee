<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ThemePurchaseApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_user_can_purchase_limited_paid_theme(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 700]);

        Theme::query()->create([
            'key' => 'paid_theme',
            'name' => 'Paid Theme',
            'unlock_type' => 'limited_paid',
            'token_source' => 'local',
            'is_active' => true,
            'is_default' => false,
            'is_limited' => false,
            'price' => 300,
            'sort_order' => 999,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/themes/purchase', [
            'theme_key' => 'paid_theme',
        ])->assertCreated()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('user_theme_unlocks', [
            'user_id' => $user->id,
            'theme_key' => 'paid_theme',
            'source' => 'shop_purchase',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'type' => 'debit',
            'coins' => 300,
            'category' => 'other',
        ]);
        $this->assertSame(400, (int) Wallet::query()->where('user_id', $user->id)->value('balance'));
    }

    public function test_theme_purchase_blocks_when_balance_is_insufficient(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 50]);

        Theme::query()->create([
            'key' => 'expensive_theme',
            'name' => 'Expensive Theme',
            'unlock_type' => 'limited_paid',
            'token_source' => 'local',
            'is_active' => true,
            'is_default' => false,
            'is_limited' => false,
            'price' => 300,
            'sort_order' => 1000,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/themes/purchase', [
            'theme_key' => 'expensive_theme',
        ])->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertDatabaseMissing('user_theme_unlocks', [
            'user_id' => $user->id,
            'theme_key' => 'expensive_theme',
        ]);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }
}
