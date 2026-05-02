<?php

namespace Tests\Feature;

use App\Models\EntryPack;
use App\Models\Host;
use App\Models\LiveRoom;
use App\Models\LiveRoomParticipant;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserEntryPack;
use App\Models\UserSubscription;
use App\Models\Wallet;
use App\Services\EntryPackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EntryPackApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'super-admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturn(1);
    }

    public function test_entry_pack_list_marks_owned_and_active_pack_for_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $activePack = EntryPack::query()->create([
            'name' => 'Aurora',
            'price_coins' => 200,
            'svg_url' => 'https://cdn.example.com/aurora.svg',
            'animation_style' => 'center',
            'priority' => 4,
            'duration_ms' => 2800,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        EntryPack::query()->create([
            'name' => 'Disabled',
            'price_coins' => 100,
            'svg_url' => 'https://cdn.example.com/disabled.svg',
            'animation_style' => 'banner',
            'priority' => 1,
            'duration_ms' => 2600,
            'is_active' => false,
            'sort_order' => 2,
        ]);

        UserEntryPack::query()->create([
            'user_id' => $user->id,
            'entry_pack_id' => $activePack->id,
            'is_active' => true,
            'purchased_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/entry-packs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $activePack->id)
            ->assertJsonPath('data.0.owned', true)
            ->assertJsonPath('data.0.active', true);
    }

    public function test_purchase_entry_pack_creates_wallet_ledger_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 500]);

        $pack = EntryPack::query()->create([
            'name' => 'Royal Gate',
            'price_coins' => 150,
            'svg_url' => 'https://cdn.example.com/royal.svg',
            'animation_style' => 'fullscreen',
            'priority' => 5,
            'duration_ms' => 3200,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Sanctum::actingAs($user);

        $first = $this->postJson("/api/entry-packs/{$pack->id}/purchase", [
            'idempotency_key' => 'entry-pack-purchase-1',
        ])->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.entry_pack_id', $pack->id);

        $second = $this->postJson("/api/entry-packs/{$pack->id}/purchase", [
            'idempotency_key' => 'entry-pack-purchase-1',
        ])->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.entry_pack_id', $pack->id);

        $this->assertSame(
            data_get($first->json(), 'data.id'),
            data_get($second->json(), 'data.id')
        );

        $this->assertDatabaseCount('user_entry_packs', 1);
        $this->assertDatabaseHas('wallet_transactions', [
            'type' => 'debit',
            'coins' => 150,
            'category' => 'other',
        ]);
        $this->assertSame(350, (int) Wallet::query()->where('user_id', $user->id)->value('balance'));
    }

    public function test_purchase_entry_pack_sets_expiry_from_pack_duration_days(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 500]);

        $pack = EntryPack::query()->create([
            'name' => 'Timed Entry',
            'price_coins' => 100,
            'svg_url' => 'https://cdn.example.com/timed.svg',
            'animation_style' => 'banner',
            'priority' => 2,
            'duration_ms' => 2400,
            'duration_days' => 15,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/entry-packs/{$pack->id}/purchase")
            ->assertCreated()
            ->assertJsonPath('ok', true);

        $owned = UserEntryPack::query()->where('user_id', $user->id)->where('entry_pack_id', $pack->id)->first();

        $this->assertNotNull($owned);
        $this->assertNotNull($owned->expires_at);
        $this->assertSame(
            $owned->purchased_at?->copy()->addDays(15)->timestamp,
            $owned->expires_at?->timestamp
        );
    }

    public function test_purchase_entry_pack_blocks_when_balance_is_insufficient(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 40]);

        $pack = EntryPack::query()->create([
            'name' => 'Sky Banner',
            'price_coins' => 120,
            'svg_url' => 'https://cdn.example.com/sky.svg',
            'animation_style' => 'banner',
            'priority' => 2,
            'duration_ms' => 2400,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/entry-packs/{$pack->id}/purchase")
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'INSUFFICIENT_FUNDS');

        $this->assertDatabaseCount('user_entry_packs', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(40, (int) Wallet::query()->where('user_id', $user->id)->value('balance'));
    }

    public function test_purchase_free_entry_pack_does_not_require_wallet_balance(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 0]);

        $pack = EntryPack::query()->create([
            'name' => 'Basic Entry',
            'price_coins' => 0,
            'svg_url' => 'https://cdn.example.com/basic.svg',
            'animation_style' => 'banner',
            'priority' => 1,
            'duration_ms' => 2500,
            'duration_days' => 30,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/entry-packs/{$pack->id}/purchase")
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.entry_pack_id', $pack->id);

        $this->assertDatabaseHas('user_entry_packs', [
            'user_id' => $user->id,
            'entry_pack_id' => $pack->id,
        ]);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(0, (int) Wallet::query()->where('user_id', $user->id)->value('balance'));
    }

    public function test_activate_entry_pack_switches_active_pack_and_me_endpoint_returns_it(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $firstPack = EntryPack::query()->create([
            'name' => 'Comet',
            'price_coins' => 100,
            'svg_url' => 'https://cdn.example.com/comet.svg',
            'animation_style' => 'banner',
            'priority' => 1,
            'duration_ms' => 2200,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $secondPack = EntryPack::query()->create([
            'name' => 'Nebula',
            'price_coins' => 300,
            'svg_url' => 'https://cdn.example.com/nebula.svg',
            'animation_style' => 'center',
            'priority' => 3,
            'duration_ms' => 3000,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        UserEntryPack::query()->create([
            'user_id' => $user->id,
            'entry_pack_id' => $firstPack->id,
            'is_active' => true,
            'purchased_at' => now()->subMinutes(10),
        ]);

        UserEntryPack::query()->create([
            'user_id' => $user->id,
            'entry_pack_id' => $secondPack->id,
            'is_active' => false,
            'purchased_at' => now()->subMinutes(5),
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/me/entry-pack/{$secondPack->id}/activate")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.entry_pack_id', $secondPack->id)
            ->assertJsonPath('data.is_active', true);

        $this->getJson('/api/me/entry-pack')
            ->assertOk()
            ->assertJsonPath('data.active.entry_pack_id', $secondPack->id);

        $this->assertDatabaseHas('user_entry_packs', [
            'user_id' => $user->id,
            'entry_pack_id' => $firstPack->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('user_entry_packs', [
            'user_id' => $user->id,
            'entry_pack_id' => $secondPack->id,
            'is_active' => true,
        ]);
    }

    public function test_live_room_join_triggers_entry_effect_and_cooldown_suppresses_repeat(): void
    {
        [, $room] = $this->makeLiveRoom('audio');
        $viewer = User::factory()->create();
        $viewer->assignRole('user');
        $this->grantSubscription($viewer);

        $pack = EntryPack::query()->create([
            'name' => 'Prism Arrival',
            'price_coins' => 100,
            'svg_url' => 'https://cdn.example.com/prism.svg',
            'animation_style' => 'center',
            'priority' => 8,
            'duration_ms' => 3600,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        UserEntryPack::query()->create([
            'user_id' => $viewer->id,
            'entry_pack_id' => $pack->id,
            'is_active' => true,
            'purchased_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($viewer);

        $first = $this->postJson("/api/live/rooms/{$room->room_id}/join", [
            'role' => 'listener',
            'session_id' => 'entry-sess-1',
        ])->assertOk();

        $second = $this->postJson("/api/live/rooms/{$room->room_id}/join", [
            'role' => 'listener',
            'session_id' => 'entry-sess-1',
        ])->assertOk();

        $first->assertJsonPath('entry_effect.entry_pack_id', $pack->id)
            ->assertJsonPath('entry_effect.animation_style', 'center');

        $second->assertJsonPath('entry_effect', null);
    }

    public function test_video_live_room_join_triggers_entry_effect_for_viewer(): void
    {
        [, $room] = $this->makeLiveRoom('video');
        $viewer = User::factory()->create();
        $viewer->assignRole('user');
        $this->grantSubscription($viewer);

        $pack = EntryPack::query()->create([
            'name' => 'Cinema Burst',
            'price_coins' => 220,
            'svg_url' => 'https://cdn.example.com/cinema-burst.svg',
            'animation_style' => 'fullscreen',
            'priority' => 6,
            'duration_ms' => 3400,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        UserEntryPack::query()->create([
            'user_id' => $viewer->id,
            'entry_pack_id' => $pack->id,
            'is_active' => true,
            'purchased_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($viewer);

        $this->postJson("/api/live/rooms/{$room->room_id}/join", [
            'role' => 'viewer',
            'session_id' => 'entry-video-sess-1',
        ])->assertOk()
            ->assertJsonPath('entry_effect.room_id', $room->room_id)
            ->assertJsonPath('entry_effect.room_type', 'video')
            ->assertJsonPath('entry_effect.entry_pack_id', $pack->id)
            ->assertJsonPath('entry_effect.animation_style', 'fullscreen');
    }

    public function test_expired_or_inactive_entry_pack_does_not_trigger_room_entry_effect(): void
    {
        [, $room] = $this->makeLiveRoom('video');
        $viewer = User::factory()->create();
        $viewer->assignRole('user');
        $this->grantSubscription($viewer);

        $inactivePack = EntryPack::query()->create([
            'name' => 'Dormant',
            'price_coins' => 100,
            'svg_url' => 'https://cdn.example.com/dormant.svg',
            'animation_style' => 'banner',
            'priority' => 1,
            'duration_ms' => 2200,
            'is_active' => false,
            'sort_order' => 1,
        ]);

        UserEntryPack::query()->create([
            'user_id' => $viewer->id,
            'entry_pack_id' => $inactivePack->id,
            'is_active' => true,
            'purchased_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($viewer);

        $this->postJson("/api/live/rooms/{$room->room_id}/join", [
            'role' => 'viewer',
            'session_id' => 'entry-sess-inactive',
        ])->assertOk()
            ->assertJsonPath('entry_effect', null);

        UserEntryPack::query()->where('user_id', $viewer->id)->delete();

        $expiredPack = EntryPack::query()->create([
            'name' => 'Expired',
            'price_coins' => 100,
            'svg_url' => 'https://cdn.example.com/expired.svg',
            'animation_style' => 'fullscreen',
            'priority' => 10,
            'duration_ms' => 3800,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        UserEntryPack::query()->create([
            'user_id' => $viewer->id,
            'entry_pack_id' => $expiredPack->id,
            'is_active' => true,
            'purchased_at' => now()->subDays(2),
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson("/api/live/rooms/{$room->room_id}/join", [
            'role' => 'viewer',
            'session_id' => 'entry-sess-expired',
        ])->assertOk()
            ->assertJsonPath('entry_effect', null);
    }

    public function test_entry_pack_service_prefers_highest_priority_active_pack(): void
    {
        $service = app(EntryPackService::class);
        $user = User::factory()->create();
        $user->assignRole('user');

        $low = EntryPack::query()->create([
            'name' => 'Low',
            'price_coins' => 100,
            'svg_url' => 'https://cdn.example.com/low.svg',
            'animation_style' => 'banner',
            'priority' => 1,
            'duration_ms' => 2200,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $high = EntryPack::query()->create([
            'name' => 'High',
            'price_coins' => 200,
            'svg_url' => 'https://cdn.example.com/high.svg',
            'animation_style' => 'fullscreen',
            'priority' => 9,
            'duration_ms' => 3400,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        UserEntryPack::query()->create([
            'user_id' => $user->id,
            'entry_pack_id' => $low->id,
            'is_active' => true,
            'purchased_at' => now()->subMinutes(5),
        ]);

        UserEntryPack::query()->create([
            'user_id' => $user->id,
            'entry_pack_id' => $high->id,
            'is_active' => true,
            'purchased_at' => now()->subMinutes(4),
        ]);

        $active = $service->activeForUser($user);

        $this->assertNotNull($active);
        $this->assertSame($high->id, $active->entry_pack_id);
    }

    public function test_admin_can_edit_user_entry_pack_assignment_and_expiry(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $user = User::factory()->create();
        $user->assignRole('user');

        $firstPack = EntryPack::query()->create([
            'name' => 'First',
            'price_coins' => 100,
            'svg_url' => 'https://cdn.example.com/first.svg',
            'animation_style' => 'banner',
            'priority' => 1,
            'duration_ms' => 2200,
            'duration_days' => 30,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $secondPack = EntryPack::query()->create([
            'name' => 'Second',
            'price_coins' => 200,
            'svg_url' => 'https://cdn.example.com/second.svg',
            'animation_style' => 'center',
            'priority' => 3,
            'duration_ms' => 3200,
            'duration_days' => 45,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $userPack = UserEntryPack::query()->create([
            'user_id' => $user->id,
            'entry_pack_id' => $firstPack->id,
            'is_active' => true,
            'purchased_at' => now()->subDay(),
            'expires_at' => now()->addDays(29),
        ]);

        $this->actingAs($admin)
            ->put(route('admin.entry-packs.purchases.update', $userPack), [
                'entry_pack_id' => $secondPack->id,
                'is_active' => 1,
                'purchased_at' => now()->subHours(3)->format('Y-m-d H:i:s'),
                'expires_at' => now()->addDays(10)->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect(route('admin.entry-packs.reports'));

        $this->assertDatabaseHas('user_entry_packs', [
            'id' => $userPack->id,
            'entry_pack_id' => $secondPack->id,
            'is_active' => true,
        ]);
    }

    private function grantSubscription(User $user): void
    {
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Viewer Pass '.$user->id,
            'price_coins' => 100,
            'duration_days' => 30,
            'perks' => ['live_access' => true],
            'is_active' => true,
        ]);

        UserSubscription::query()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDays(10),
            'last_purchased_at' => now()->subMinute(),
            'meta' => ['source' => 'entry-pack-test'],
        ]);
    }

    private function makeLiveRoom(string $roomType = 'video'): array
    {
        $hostUser = User::factory()->create();
        $hostUser->assignRole('host');

        $host = Host::query()->create([
            'user_id' => $hostUser->id,
            'stage_name' => 'Entry Host',
        ]);

        $room = LiveRoom::query()->create([
            'host_id' => $host->id,
            'room_id' => 'entry-room-'.$host->id.'-'.$roomType,
            'title' => 'Entry Room',
            'room_type' => $roomType,
            'status' => 'live',
            'started_at' => now()->subMinutes(5),
            'last_activity_at' => now()->subMinute(),
            'peak_viewers' => 3,
            'max_participants' => $roomType === 'audio' ? 50 : 12,
            'max_speakers' => $roomType === 'audio' ? 8 : 4,
        ]);

        LiveRoomParticipant::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $hostUser->id,
            'role' => 'host',
            'joined_at' => now()->subMinutes(5),
        ]);

        return [$hostUser, $room];
    }
}
