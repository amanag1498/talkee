<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\CallSession;
use App\Models\Gift;
use App\Models\Host;
use App\Models\LiveRoom;
use App\Models\LiveRoomGift;
use App\Models\LiveRoomGiftEarningLedger;
use App\Models\LiveRoomParticipant;
use App\Models\LiveRoomPkBattle;
use App\Models\LiveRoomPkEvent;
use App\Models\PaymentOrder;
use App\Models\RechargePlan;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\Wallet;
use App\Services\CallBillingService;
use App\Services\RechargeOrderService;
use App\Services\SubscriptionService;
use App\Services\WalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletLifecycleDemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', env('SEED_ADMIN_EMAIL', 'admin@example.com'))->first();
        $agencyOwner = User::query()->where('email', 'agency@example.com')->firstOrFail();
        $hostUser = User::query()->where('email', 'host@example.com')->firstOrFail();
        $secondHostUser = User::query()->where('email', 'host2@example.com')->firstOrFail();
        $viewer = User::query()->where('email', 'viewer@example.com')->firstOrFail();
        $viewerTwo = User::query()->where('email', 'viewer2@example.com')->firstOrFail();

        $host = Host::query()->where('user_id', $hostUser->id)->firstOrFail();
        $secondHost = Host::query()->where('user_id', $secondHostUser->id)->firstOrFail();
        $agency = Agency::query()->whereKey($host->agency_id)->first();

        $rechargePlan = RechargePlan::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail();
        $subscriptionPlan = SubscriptionPlan::query()->where('is_active', true)->orderBy('id')->firstOrFail();

        $rechargeService = app(RechargeOrderService::class);
        $subscriptionService = app(SubscriptionService::class);
        $callBillingService = app(CallBillingService::class);

        $giftReference = 'ROOM_GIFT:demo-room-wallet-flow:' . $viewer->id . ':demo';

        $this->ensureWalletBalance($viewer, 3500, 'demo_seed_viewer');
        $this->ensureWalletBalance($viewerTwo, 2200, 'demo_seed_viewer2');
        $this->ensureWalletBalance($hostUser, 800, 'demo_seed_host');
        $this->ensureWalletBalance($secondHostUser, 900, 'demo_seed_host_two');
        $this->ensureWalletBalance($agencyOwner, 1500, 'demo_seed_agency_owner');
        if ($admin) {
            $this->ensureWalletBalance($admin, 2000, 'demo_seed_admin');
        }

        Wallet::query()->where('user_id', $agencyOwner->id)->first()?->refresh();

        // Cleanup previous demo gift credits if this seeder ran under the old immediate-credit model.
        \App\Models\WalletTransaction::query()
            ->whereIn('reference', [$giftReference . ':host', $giftReference . ':agency'])
            ->delete();

        $successfulRecharge = PaymentOrder::query()
            ->where('user_id', $viewer->id)
            ->where('status', 'success')
            ->where('gateway', 'mock')
            ->first();

        if (!$successfulRecharge) {
            $successfulRecharge = $rechargeService->createOrder($viewer, $rechargePlan->id, 'mock');
            $rechargeService->verifyOrder($viewer, $successfulRecharge->order_id, [
                'result' => 'success',
                'gateway_payment_id' => 'demo_success_' . Str::lower((string) Str::uuid()),
                'gateway_response' => ['demo' => true, 'result' => 'success'],
            ]);
        }

        PaymentOrder::query()->firstOrCreate(
            [
                'user_id' => $viewer->id,
                'status' => 'failed',
                'gateway' => 'mock',
            ],
            [
                'recharge_plan_id' => $rechargePlan->id,
                'order_id' => 'talkee_ro_demo_failed_' . Str::lower((string) Str::uuid()),
                'amount_rupees' => $rechargePlan->amount_rupees,
                'coins' => $rechargePlan->coins,
                'bonus_coins' => $rechargePlan->bonus_coins,
                'total_coins' => $rechargePlan->total_coins,
                'gateway_payment_id' => 'demo_failed_' . Str::lower((string) Str::uuid()),
                'gateway_response' => ['demo' => true, 'result' => 'failed'],
                'verified_at' => now(),
            ]
        );

        if (!UserSubscription::query()
            ->where('user_id', $viewer->id)
            ->where('subscription_plan_id', $subscriptionPlan->id)
            ->where('meta->source', 'USER_PURCHASE')
            ->exists()) {
            $subscriptionService->purchase($viewer, $subscriptionPlan);
        }

        $room = LiveRoom::query()->firstOrCreate(
            ['room_id' => 'demo-room-wallet-flow'],
            [
                'host_id' => $host->id,
                'title' => 'Demo Wallet Lifecycle Room',
                'status' => 'live',
                'started_at' => now()->subMinutes(12),
                'last_activity_at' => now()->subMinute(),
                'peak_viewers' => 3,
                'max_speakers' => 4,
            ]
        );

        LiveRoomParticipant::query()->updateOrCreate(
            ['live_room_id' => $room->id, 'user_id' => $hostUser->id, 'role' => 'host'],
            ['joined_at' => now()->subMinutes(12), 'left_at' => null]
        );
        LiveRoomParticipant::query()->updateOrCreate(
            ['live_room_id' => $room->id, 'user_id' => $viewer->id, 'role' => 'viewer'],
            ['joined_at' => now()->subMinutes(5), 'left_at' => null]
        );

        $audioRoom = LiveRoom::query()->firstOrCreate(
            ['room_id' => 'demo-room-audio-flow'],
            [
                'host_id' => $host->id,
                'title' => 'Demo Audio Room',
                'room_type' => 'audio',
                'status' => 'live',
                'started_at' => now()->subMinutes(18),
                'last_activity_at' => now()->subMinutes(2),
                'peak_viewers' => 6,
                'max_speakers' => 8,
            ]
        );

        LiveRoomParticipant::query()->updateOrCreate(
            ['live_room_id' => $audioRoom->id, 'user_id' => $hostUser->id, 'role' => 'host'],
            ['joined_at' => now()->subMinutes(18), 'left_at' => null]
        );
        LiveRoomParticipant::query()->updateOrCreate(
            ['live_room_id' => $audioRoom->id, 'user_id' => $viewerTwo->id, 'role' => 'listener'],
            ['joined_at' => now()->subMinutes(7), 'left_at' => null]
        );

        $pkRoom = LiveRoom::query()->firstOrCreate(
            ['room_id' => 'demo-room-pk-rival'],
            [
                'host_id' => $secondHost->id,
                'title' => 'Demo PK Rival Room',
                'room_type' => 'video',
                'status' => 'ended',
                'started_at' => now()->subDays(3)->setTime(11, 0),
                'ended_at' => now()->subDays(3)->setTime(11, 30),
                'last_activity_at' => now()->subDays(3)->setTime(11, 30),
                'peak_viewers' => 8,
                'max_speakers' => 4,
            ]
        );

        $gift = Gift::query()->firstOrCreate(
            ['name' => 'Demo Crown'],
            [
                'coins' => 120,
                'gift_url' => 'https://example.com/demo-crown.png',
                'is_active' => true,
                'sort_order' => 5,
            ]
        );

        $existingGift = LiveRoomGift::query()->where('meta->reference', $giftReference)->first();
        if (!$existingGift) {
            DB::transaction(function () use ($viewer, $hostUser, $agency, $agencyOwner, $room, $gift, $giftReference) {
                $senderDebit = WalletService::spend(
                    $viewer,
                    240,
                    'gift',
                    $hostUser,
                    $giftReference,
                    [
                        'event' => 'LIVE_ROOM_GIFT',
                        'room_id' => $room->room_id,
                        'gift_id' => $gift->id,
                        'gift_name' => $gift->name,
                        'quantity' => 2,
                        'message' => 'Demo seeded gift',
                        'host_user_id' => $hostUser->id,
                    ]
                );

                $hostSharePercent = (float) ($hostUser->host?->payout_percentage ?? 60);
                $agencySharePercent = (float) ($agency?->payout_percentage ?? 10);
                $hostEarning = (int) floor((240 * $hostSharePercent) / 100);
                $agencyEarning = $agency ? (int) floor((240 * $agencySharePercent) / 100) : 0;
                if ($agencyOwner && $agencyOwner->id === $hostUser->id) {
                    $hostEarning += $agencyEarning;
                    $agencyEarning = 0;
                }
                if (($hostEarning + $agencyEarning) > 240) {
                    $agencyEarning = max(0, 240 - $hostEarning);
                }
                $platformEarning = max(0, 240 - $hostEarning - $agencyEarning);

                $roomGift = LiveRoomGift::query()->create([
                    'live_room_id' => $room->id,
                    'gift_id' => $gift->id,
                    'sender_user_id' => $viewer->id,
                    'quantity' => 2,
                    'coins_per_unit' => 120,
                    'total_coins' => 240,
                    'transaction_id' => (string) $senderDebit->id,
                    'meta' => [
                        'message' => 'Demo seeded gift',
                        'wallet_transaction_id' => $senderDebit->id,
                        'reference' => $giftReference,
                        'host_earning' => $hostEarning,
                        'agency_earning' => $agencyEarning,
                        'platform_earning' => $platformEarning,
                    ],
                ]);

                LiveRoomGiftEarningLedger::query()->create([
                    'live_room_gift_id' => $roomGift->id,
                    'live_room_id' => $room->id,
                    'sender_user_id' => $viewer->id,
                    'host_id' => $room->host_id,
                    'agency_id' => $agency?->id,
                    'total_coins' => 240,
                    'host_payout_percentage' => $hostSharePercent,
                    'agency_payout_percentage' => $agencySharePercent,
                    'host_payout_coins' => $hostEarning,
                    'agency_payout_coins' => $agencyEarning,
                    'platform_revenue_coins' => $platformEarning,
                ]);
            });
        } else {
            $hostSharePercent = (float) ($hostUser->host?->payout_percentage ?? 60);
            $agencySharePercent = (float) ($agency?->payout_percentage ?? 10);
            $hostEarning = (int) floor((((int) $existingGift->total_coins) * $hostSharePercent) / 100);
            $agencyEarning = $agency ? (int) floor((((int) $existingGift->total_coins) * $agencySharePercent) / 100) : 0;
            if (($hostEarning + $agencyEarning) > (int) $existingGift->total_coins) {
                $agencyEarning = max(0, (int) $existingGift->total_coins - $hostEarning);
            }
            $platformEarning = max(0, (int) $existingGift->total_coins - $hostEarning - $agencyEarning);

            LiveRoomGiftEarningLedger::query()->updateOrCreate(
                ['live_room_gift_id' => $existingGift->id],
                [
                    'live_room_id' => $existingGift->live_room_id,
                    'sender_user_id' => $existingGift->sender_user_id,
                    'host_id' => $room->host_id,
                    'agency_id' => $agency?->id,
                    'total_coins' => (int) $existingGift->total_coins,
                    'host_payout_percentage' => $hostSharePercent,
                    'agency_payout_percentage' => $agencySharePercent,
                    'host_payout_coins' => $hostEarning,
                    'agency_payout_coins' => $agencyEarning,
                    'platform_revenue_coins' => $platformEarning,
                ]
            );

            $meta = $existingGift->meta ?? [];
            unset($meta['host_wallet_transaction_id'], $meta['agency_wallet_transaction_id']);
            $meta['host_earning'] = $hostEarning;
            $meta['agency_earning'] = $agencyEarning;
            $meta['platform_earning'] = $platformEarning;
            $existingGift->update(['meta' => $meta]);
        }

        $pkBattle = LiveRoomPkBattle::query()->firstOrCreate(
            ['battle_id' => 'pk-demo-agency-1'],
            [
                'room_a_id' => $room->id,
                'room_b_id' => $pkRoom->id,
                'host_a_id' => $host->id,
                'host_b_id' => $secondHost->id,
                'invited_by_host_id' => $host->id,
                'status' => 'completed',
                'duration_seconds' => 180,
                'score_a' => 120,
                'score_b' => 80,
                'started_at' => now()->subDays(3)->setTime(11, 5),
                'ended_at' => now()->subDays(3)->setTime(11, 8),
                'winner_room_id' => $room->id,
                'end_reason' => 'timer_expired',
            ]
        );

        LiveRoomPkEvent::query()->firstOrCreate(
            [
                'pk_battle_id' => $pkBattle->id,
                'room_id' => $room->id,
                'user_id' => $viewer->id,
                'event_type' => 'gift',
                'coins' => 120,
            ],
            [
                'wallet_transaction_id' => null,
                'gift_id' => $gift->id,
                'metadata' => ['source' => 'seed', 'reference' => 'pk-demo-agency-1-event'],
            ]
        );

        $call = CallSession::query()->where('livekit_room_name', 'demo-call-wallet-flow')->first();
        if (!$call) {
            $call = CallSession::query()->create([
                'caller_id' => $viewerTwo->id,
                'receiver_id' => $hostUser->id,
                'host_id' => $host->id,
                'agency_id' => $agency?->id,
                'type' => 'video',
                'status' => 'ended',
                'livekit_room_name' => 'demo-call-wallet-flow',
                'started_at' => now()->subMinutes(8),
                'accepted_at' => now()->subMinutes(8),
                'ended_at' => now()->subMinutes(4),
                'coin_rate_per_minute' => (int) config('calls.coin_rate_per_minute', 20),
                'end_reason' => 'completed',
            ]);
            $callBillingService->processEndedCall($call);
        }

        $this->command?->info('Wallet lifecycle demo data seeded.');
        $this->command?->table(
            ['Flow', 'Where to inspect'],
            [
                ['Recharge success', 'payment_orders + wallet_transactions(category=recharge)'],
                ['Recharge failure', 'payment_orders(status=failed)'],
                ['Subscription purchase', 'user_subscriptions + wallet_transactions(category=subscription)'],
                ['Live room gift', 'live_room_gifts + live_room_gift_earning_ledgers + wallet_transactions(category=gift)'],
                ['Completed call', 'call_sessions + call_earning_ledgers + wallet_transactions(category=video_call)'],
            ]
        );
    }

    private function ensureWalletBalance(User $user, int $targetBalance, string $reference): void
    {
        $wallet = WalletService::getOrCreate($user);
        $current = (int) $wallet->balance;

        if ($current < $targetBalance) {
            WalletService::credit($user, $targetBalance - $current, $reference, ['note' => 'Lifecycle demo prep']);
        } elseif ($current > $targetBalance) {
            WalletService::debit($user, $current - $targetBalance, $reference, ['note' => 'Lifecycle demo prep']);
        }
    }
}
