<?php

namespace Tests\Feature;

use App\Models\Gift;
use App\Models\Host;
use App\Models\LiveRoom;
use App\Models\LiveRoomGift;
use App\Models\LiveRoomParticipant;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LiveRoomAccessAndGiftTest extends TestCase
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

    public function test_viewer_join_requires_active_subscription(): void
    {
        [, $room] = $this->makeLiveRoom();
        $viewer = User::factory()->create();
        $viewer->assignRole('user');

        Sanctum::actingAs($viewer);

        $this->postJson("/api/live/rooms/{$room->room_id}/join", [
            'role' => 'viewer',
            'session_id' => 'sess-no-sub',
        ])->assertStatus(402);
    }

    public function test_viewer_with_active_subscription_can_join_room(): void
    {
        [, $room] = $this->makeLiveRoom();
        $viewer = User::factory()->create();
        $viewer->assignRole('user');
        $this->grantSubscription($viewer);

        Sanctum::actingAs($viewer);

        $this->postJson("/api/live/rooms/{$room->room_id}/join", [
            'role' => 'viewer',
            'session_id' => 'sess-active-sub',
        ])->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_room_gift_creates_wallet_debit_and_room_gift_row(): void
    {
        [$hostUser, $room] = $this->makeLiveRoom();
        $viewer = User::factory()->create();
        $viewer->assignRole('user');
        $this->grantSubscription($viewer);

        Wallet::query()->where('user_id', $viewer->id)->update([
            'balance' => 500,
        ]);

        LiveRoomParticipant::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'joined_at' => now()->subMinute(),
        ]);

        $gift = Gift::query()->create([
            'name' => 'Rose',
            'coins' => 25,
            'gift_url' => 'https://example.com/rose.png',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Sanctum::actingAs($viewer);

        $this->postJson("/api/live/rooms/{$room->room_id}/gifts", [
            'gift_id' => $gift->id,
            'quantity' => 2,
        ])->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('gift.total_coins', 50)
            ->assertJsonPath('gift.host_user_id', $hostUser->id);

        $this->assertDatabaseHas('live_room_gifts', [
            'live_room_id' => $room->id,
            'gift_id' => $gift->id,
            'sender_user_id' => $viewer->id,
            'quantity' => 2,
            'total_coins' => 50,
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'category' => 'gift',
            'type' => 'debit',
            'coins' => 50,
            'counterparty_user_id' => $hostUser->id,
        ]);
        $this->assertDatabaseHas('live_room_gift_earning_ledgers', [
            'live_room_id' => $room->id,
            'host_id' => $room->host_id,
            'total_coins' => 50,
            'host_payout_coins' => 30,
            'agency_payout_coins' => 0,
            'platform_revenue_coins' => 20,
        ]);

        $this->assertSame(450, (int) Wallet::query()->where('user_id', $viewer->id)->value('balance'));
        $this->assertSame(0, (int) Wallet::query()->where('user_id', $hostUser->id)->value('balance'));
        $this->assertSame(1, LiveRoomGift::query()->count());
        $this->assertSame(30, (int) data_get(LiveRoomGift::query()->first()?->meta, 'host_earning'));
        $this->assertSame(0, (int) data_get(LiveRoomGift::query()->first()?->meta, 'agency_earning'));
        $this->assertSame(20, (int) data_get(LiveRoomGift::query()->first()?->meta, 'platform_earning'));
    }

    public function test_room_gift_creates_gross_earning_ledger_without_wallet_crediting_host_or_agency(): void
    {
        $agencyOwner = User::factory()->create();
        $agency = \App\Models\Agency::query()->create([
            'name' => 'Prime Agency',
            'owner_user_id' => $agencyOwner->id,
            'payout_percentage' => 15,
        ]);

        [$hostUser, $room] = $this->makeLiveRoom([
            'agency_id' => $agency->id,
            'payout_percentage' => 50,
        ]);

        $viewer = User::factory()->create();
        $viewer->assignRole('user');
        $this->grantSubscription($viewer);
        Wallet::query()->where('user_id', $viewer->id)->update(['balance' => 500]);

        LiveRoomParticipant::query()->create([
            'live_room_id' => $room->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'joined_at' => now()->subMinute(),
        ]);

        $gift = Gift::query()->create([
            'name' => 'Crown',
            'coins' => 100,
            'gift_url' => 'https://example.com/crown.png',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        Sanctum::actingAs($viewer);

        $this->postJson("/api/live/rooms/{$room->room_id}/gifts", [
            'gift_id' => $gift->id,
            'quantity' => 1,
        ])->assertCreated();

        $this->assertDatabaseHas('live_room_gift_earning_ledgers', [
            'live_room_id' => $room->id,
            'host_id' => $room->host_id,
            'agency_id' => $agency->id,
            'total_coins' => 100,
            'host_payout_coins' => 50,
            'agency_payout_coins' => 15,
            'platform_revenue_coins' => 35,
        ]);
        $this->assertDatabaseMissing('wallet_transactions', [
            'category' => 'gift_earning',
        ]);
        $this->assertDatabaseMissing('wallet_transactions', [
            'category' => 'gift_agency_earning',
        ]);
        $this->assertSame(0, (int) Wallet::query()->where('user_id', $hostUser->id)->value('balance'));
        $this->assertSame(0, (int) Wallet::query()->where('user_id', $agencyOwner->id)->value('balance'));
    }

    private function grantSubscription(User $user): void
    {
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Viewer Pass',
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
            'meta' => ['source' => 'test'],
        ]);
    }

    private function makeLiveRoom(array $hostOverrides = []): array
    {
        $hostUser = User::factory()->create();
        $hostUser->assignRole('host');

        $host = Host::query()->create([
            'user_id' => $hostUser->id,
            'stage_name' => 'Gift Host',
        ] + []);
        if ($hostOverrides) {
            $host->update($hostOverrides);
            $host->refresh();
        }

        $room = LiveRoom::query()->create([
            'host_id' => $host->id,
            'room_id' => 'room-'.$host->id.'-gift',
            'title' => 'Gift Live Room',
            'status' => 'live',
            'started_at' => now()->subMinutes(5),
            'last_activity_at' => now()->subMinute(),
            'peak_viewers' => 2,
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
