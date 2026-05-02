<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\CallEarningLedger;
use App\Models\CallSession;
use App\Models\EntryPack;
use App\Models\Gift;
use App\Models\Host;
use App\Models\HostAvailability;
use App\Models\LiveRoom;
use App\Models\LiveRoomGift;
use App\Models\LiveRoomGiftEarningLedger;
use App\Models\LiveRoomParticipant;
use App\Models\LiveRoomPkBattle;
use App\Models\LiveRoomPkEvent;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserEntryPack;
use App\Models\UserSubscription;
use App\Models\WalletTransaction;
use App\Services\CallBillingService;
use App\Services\SubscriptionService;
use App\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RealWorldSimulationSeeder extends Seeder
{
    public function run(): void
    {
        $callBilling = app(CallBillingService::class);
        $subscriptionService = app(SubscriptionService::class);

        $bronze = SubscriptionPlan::query()->where('name', 'Bronze')->first();
        $silver = SubscriptionPlan::query()->where('name', 'Silver')->first() ?? SubscriptionPlan::query()->where('is_active', true)->skip(1)->first();
        $gold = SubscriptionPlan::query()->where('name', 'Gold')->first() ?? SubscriptionPlan::query()->where('is_active', true)->latest('id')->first();
        $basicPack = EntryPack::query()->where('name', 'Basic Entry')->first();
        $vipPack = EntryPack::query()->where('name', 'VIP Entry')->first();
        $royalPack = EntryPack::query()->where('name', 'Royal Entry')->first();
        $rose = Gift::query()->where('name', 'Rose')->first() ?? Gift::query()->firstOrFail();
        $crown = Gift::query()->where('name', 'Crown')->first() ?? Gift::query()->orderByDesc('coins')->firstOrFail();
        $rocket = Gift::query()->where('name', 'Rocket')->first() ?? Gift::query()->orderByDesc('coins')->firstOrFail();

        $agencyOne = Agency::query()->where('name', 'Prime Talent Agency')->firstOrFail();
        $agencyTwoOwner = $this->makeUser('agency2@example.com', 'Orbit Agency Owner', ['agency']);
        $agencyTwo = Agency::query()->updateOrCreate(
            ['owner_user_id' => $agencyTwoOwner->id],
            [
                'name' => 'Orbit Creators Network',
                'legal_name' => 'Orbit Creators Network Pvt Ltd',
                'contact_email' => 'agency2@example.com',
                'contact_phone' => '+91-9000000010',
                'notes' => 'Second seeded agency with more varied activity.',
                'is_blocked' => false,
                'payout_percentage' => 12,
                'weekly_bonus' => 650,
            ]
        );

        $primePulseUser = $this->makeUser('host3@example.com', 'Prime Pulse', ['host']);
        $primeEchoUser = $this->makeUser('host4@example.com', 'Prime Echo', ['host']);
        $orbitAstraUser = $this->makeUser('host5@example.com', 'Orbit Astra', ['host']);
        $orbitBlazeUser = $this->makeUser('host6@example.com', 'Orbit Blaze', ['host']);

        $primePulse = $this->ensureHost($primePulseUser, $agencyOne, [
            'stage_name' => 'Prime Pulse',
            'city' => 'Bengaluru',
            'country' => 'India',
            'bio' => 'High-volume video host for seeded realism.',
            'payout_percentage' => 58,
            'weekly_bonus' => 250,
        ]);
        $primeEcho = $this->ensureHost($primeEchoUser, $agencyOne, [
            'stage_name' => 'Prime Echo',
            'city' => 'Pune',
            'country' => 'India',
            'bio' => 'Audio-first host with recurring loyal audience.',
            'payout_percentage' => 57,
            'weekly_bonus' => 180,
        ]);
        $orbitAstra = $this->ensureHost($orbitAstraUser, $agencyTwo, [
            'stage_name' => 'Orbit Astra',
            'city' => 'Jaipur',
            'country' => 'India',
            'bio' => 'Agency two flagship live room host.',
            'payout_percentage' => 56,
            'weekly_bonus' => 220,
        ]);
        $orbitBlaze = $this->ensureHost($orbitBlazeUser, $agencyTwo, [
            'stage_name' => 'Orbit Blaze',
            'city' => 'Ahmedabad',
            'country' => 'India',
            'bio' => 'Fast-turnover call host for payout testing.',
            'payout_percentage' => 54,
            'weekly_bonus' => 160,
        ]);

        $this->ensureAvailability($primePulseUser, 'online');
        $this->ensureAvailability($primeEchoUser, 'online');
        $this->ensureAvailability($orbitAstraUser, 'online');
        $this->ensureAvailability($orbitBlazeUser, 'offline');

        $viewers = [
            'viewer3@example.com' => $this->makeUser('viewer3@example.com', 'Viewer Three', ['user']),
            'viewer4@example.com' => $this->makeUser('viewer4@example.com', 'Viewer Four', ['user']),
            'viewer5@example.com' => $this->makeUser('viewer5@example.com', 'Viewer Five', ['user']),
            'viewer6@example.com' => $this->makeUser('viewer6@example.com', 'Viewer Six', ['user']),
        ];

        $walletTargets = [
            $agencyTwoOwner->id => 2100,
            $primePulseUser->id => 1100,
            $primeEchoUser->id => 950,
            $orbitAstraUser->id => 1200,
            $orbitBlazeUser->id => 980,
            $viewers['viewer3@example.com']->id => 4200,
            $viewers['viewer4@example.com']->id => 3600,
            $viewers['viewer5@example.com']->id => 2800,
            $viewers['viewer6@example.com']->id => 1900,
        ];

        foreach ($walletTargets as $userId => $target) {
            $this->ensureWalletBalance(User::query()->findOrFail($userId), $target, 'real_world_seed_' . $userId);
        }

        if ($bronze) {
            $subscriptionService->grant($viewers['viewer3@example.com'], $bronze, 'seed_real_world');
        }
        if ($silver) {
            $subscriptionService->grant($viewers['viewer4@example.com'], $silver, 'seed_real_world');
        }
        if ($gold) {
            UserSubscription::query()->updateOrCreate(
                ['user_id' => $viewers['viewer5@example.com']->id, 'subscription_plan_id' => $gold->id],
                [
                    'status' => 'expired',
                    'starts_at' => now()->subDays(70),
                    'ends_at' => now()->subDays(10),
                    'last_purchased_at' => now()->subDays(40),
                    'meta' => ['source' => 'seed_real_world', 'charged' => true],
                ]
            );
        }

        $this->assignEntryPack($viewers['viewer3@example.com'], $basicPack, true, now()->subDays(5));
        $this->assignEntryPack($viewers['viewer4@example.com'], $vipPack, true, now()->subDays(3));
        $this->assignEntryPack($viewers['viewer5@example.com'], $royalPack, false, now()->subDays(20), now()->subDays(1));

        $primePulseVideo = $this->ensureRoom('prime-pulse-showcase', $primePulse, 'video', 'live', now()->subDays(1)->setTime(21, 0), null, 18);
        $primeEchoAudio = $this->ensureRoom('prime-echo-lounge', $primeEcho, 'audio', 'live', now()->subDays(2)->setTime(22, 0), null, 24);
        $orbitAstraVideo = $this->ensureRoom('orbit-astra-live', $orbitAstra, 'video', 'ended', now()->subDays(3)->setTime(20, 30), now()->subDays(3)->setTime(21, 15), 27);
        $orbitBlazeAudio = $this->ensureRoom('orbit-blaze-audio', $orbitBlaze, 'audio', 'ended', now()->subDays(5)->setTime(19, 0), now()->subDays(5)->setTime(19, 45), 15);
        $pkArenaA = $this->ensureRoom('pk-arena-alpha', $primePulse, 'video', 'ended', Carbon::parse('2026-04-23 21:00:00'), Carbon::parse('2026-04-23 21:20:00'), 32);
        $pkArenaB = $this->ensureRoom('pk-arena-beta', $orbitAstra, 'video', 'ended', Carbon::parse('2026-04-23 21:00:00'), Carbon::parse('2026-04-23 21:20:00'), 29);

        $this->ensureParticipant($primePulseVideo, $primePulseUser, 'host', now()->subDays(1)->setTime(21, 0));
        $this->ensureParticipant($primePulseVideo, $viewers['viewer3@example.com'], 'viewer', now()->subDays(1)->setTime(21, 5));
        $this->ensureParticipant($primePulseVideo, $viewers['viewer4@example.com'], 'viewer', now()->subDays(1)->setTime(21, 9));
        $this->ensureParticipant($primeEchoAudio, $primeEchoUser, 'host', now()->subDays(2)->setTime(22, 0));
        $this->ensureParticipant($primeEchoAudio, $viewers['viewer5@example.com'], 'listener', now()->subDays(2)->setTime(22, 8));
        $this->ensureParticipant($orbitAstraVideo, $orbitAstraUser, 'host', now()->subDays(3)->setTime(20, 30), now()->subDays(3)->setTime(21, 15));
        $this->ensureParticipant($orbitBlazeAudio, $orbitBlazeUser, 'host', now()->subDays(5)->setTime(19, 0), now()->subDays(5)->setTime(19, 45));
        $this->ensureParticipant($pkArenaA, $primePulseUser, 'host', Carbon::parse('2026-04-23 21:00:00'), Carbon::parse('2026-04-23 21:20:00'));
        $this->ensureParticipant($pkArenaB, $orbitAstraUser, 'host', Carbon::parse('2026-04-23 21:00:00'), Carbon::parse('2026-04-23 21:20:00'));

        $this->ensureCall('prime-pulse-video-1', $viewers['viewer3@example.com'], $primePulseUser, $primePulse, $agencyOne, 'video', Carbon::parse('2026-04-22 20:05:00'), Carbon::parse('2026-04-22 20:11:00'), $callBilling);
        $this->ensureCall('prime-echo-audio-1', $viewers['viewer4@example.com'], $primeEchoUser, $primeEcho, $agencyOne, 'audio', Carbon::parse('2026-04-24 18:10:00'), Carbon::parse('2026-04-24 18:18:00'), $callBilling);
        $this->ensureCall('orbit-astra-video-1', $viewers['viewer5@example.com'], $orbitAstraUser, $orbitAstra, $agencyTwo, 'video', Carbon::parse('2026-04-25 21:00:00'), Carbon::parse('2026-04-25 21:09:00'), $callBilling);
        $this->ensureCall('orbit-blaze-audio-1', $viewers['viewer6@example.com'], $orbitBlazeUser, $orbitBlaze, $agencyTwo, 'audio', Carbon::parse('2026-04-26 17:45:00'), Carbon::parse('2026-04-26 17:52:00'), $callBilling);
        $this->ensureCall('prime-pulse-video-older', $viewers['viewer4@example.com'], $primePulseUser, $primePulse, $agencyOne, 'video', Carbon::parse('2026-04-16 20:00:00'), Carbon::parse('2026-04-16 20:07:00'), $callBilling);

        $this->ensureRoomGift('gift:prime-pulse-showcase:rose', $primePulseVideo, $rose, $viewers['viewer3@example.com'], $primePulseUser, $agencyOne, 10, Carbon::parse('2026-04-26 21:15:00'));
        $this->ensureRoomGift('gift:prime-pulse-showcase:crown', $primePulseVideo, $crown, $viewers['viewer4@example.com'], $primePulseUser, $agencyOne, 2, Carbon::parse('2026-04-26 21:22:00'));
        $this->ensureRoomGift('gift:prime-echo-lounge:rocket', $primeEchoAudio, $rocket, $viewers['viewer5@example.com'], $primeEchoUser, $agencyOne, 1, Carbon::parse('2026-04-25 22:15:00'));
        $this->ensureRoomGift('gift:orbit-astra-live:rose', $orbitAstraVideo, $rose, $viewers['viewer6@example.com'], $orbitAstraUser, $agencyTwo, 20, Carbon::parse('2026-04-24 20:45:00'));
        $this->ensureRoomGift('gift:orbit-blaze-audio:crown', $orbitBlazeAudio, $crown, $viewers['viewer3@example.com'], $orbitBlazeUser, $agencyTwo, 1, Carbon::parse('2026-04-22 19:20:00'));

        $pkGift = $this->ensureRoomGift('gift:pk-arena-alpha:rocket', $pkArenaA, $rocket, $viewers['viewer4@example.com'], $primePulseUser, $agencyOne, 1, Carbon::parse('2026-04-23 21:06:00'));

        $battle = LiveRoomPkBattle::query()->firstOrCreate(
            ['battle_id' => 'pk-seed-real-world-1'],
            [
                'room_a_id' => $pkArenaA->id,
                'room_b_id' => $pkArenaB->id,
                'host_a_id' => $primePulse->id,
                'host_b_id' => $orbitAstra->id,
                'invited_by_host_id' => $primePulse->id,
                'status' => 'completed',
                'duration_seconds' => 300,
                'score_a' => 500,
                'score_b' => 360,
                'started_at' => Carbon::parse('2026-04-23 21:05:00'),
                'ended_at' => Carbon::parse('2026-04-23 21:10:00'),
                'winner_room_id' => $pkArenaA->id,
                'end_reason' => 'timer_expired',
            ]
        );

        LiveRoomPkEvent::query()->updateOrCreate(
            [
                'pk_battle_id' => $battle->id,
                'room_id' => $pkArenaA->id,
                'wallet_transaction_id' => $pkGift->transaction_id,
                'event_type' => 'gift',
            ],
            [
                'user_id' => $viewers['viewer4@example.com']->id,
                'coins' => (int) $pkGift->total_coins,
                'gift_id' => $pkGift->gift_id,
                'metadata' => ['source' => 'seed_real_world_pk'],
                'created_at' => Carbon::parse('2026-04-23 21:06:00'),
                'updated_at' => Carbon::parse('2026-04-23 21:06:00'),
            ]
        );

        $this->command?->info('Real-world agency simulation data seeded.');
    }

    private function makeUser(string $email, string $name, array $roles): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'provider' => 'seed',
                'email_verified_at' => now(),
                'is_blocked' => false,
            ]
        );

        $user->syncRoles($roles);

        return $user;
    }

    private function ensureHost(User $user, Agency $agency, array $overrides): Host
    {
        return Host::query()->updateOrCreate(
            ['user_id' => $user->id],
            array_merge([
                'agency_id' => $agency->id,
                'stage_name' => $user->name,
                'contact_phone' => '+91-9000000099',
                'country' => 'India',
                'city' => 'Mumbai',
                'bio' => 'Seeded real-world simulation host.',
                'kyc' => ['status' => 'approved', 'source' => 'seed_real_world'],
                'is_blocked' => false,
                'payout_percentage' => 55,
                'weekly_bonus' => 100,
            ], $overrides)
        );
    }

    private function ensureAvailability(User $user, string $manualStatus): void
    {
        HostAvailability::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'manual_status' => $manualStatus,
                'socket_status' => 'offline',
                'call_status' => 'available',
                'current_call_session_id' => null,
                'last_seen_at' => now(),
            ]
        );
    }

    private function ensureWalletBalance(User $user, int $targetBalance, string $reference): void
    {
        $wallet = WalletService::getOrCreate($user);
        $current = (int) $wallet->balance;

        if ($current < $targetBalance) {
            WalletService::credit($user, $targetBalance - $current, $reference, ['note' => 'Real world scenario seed']);
        } elseif ($current > $targetBalance) {
            WalletService::debit($user, $current - $targetBalance, $reference, ['note' => 'Real world scenario seed']);
        }
    }

    private function assignEntryPack(User $user, ?EntryPack $pack, bool $active, Carbon $purchasedAt, ?Carbon $expiresAt = null): void
    {
        if (!$pack) {
            return;
        }

        UserEntryPack::query()->updateOrCreate(
            ['user_id' => $user->id, 'entry_pack_id' => $pack->id],
            [
                'is_active' => $active,
                'purchased_at' => $purchasedAt,
                'expires_at' => $expiresAt,
                'purchase_key' => 'seed-real-world-' . $user->id . '-' . $pack->id,
            ]
        );
    }

    private function ensureRoom(string $roomId, Host $host, string $roomType, string $status, Carbon $startedAt, ?Carbon $endedAt, int $peakViewers): LiveRoom
    {
        return LiveRoom::query()->updateOrCreate(
            ['room_id' => $roomId],
            [
                'host_id' => $host->id,
                'title' => ucwords(str_replace('-', ' ', $roomId)),
                'room_type' => $roomType,
                'status' => $status,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'last_activity_at' => $endedAt ?? now(),
                'peak_viewers' => $peakViewers,
                'max_speakers' => $roomType === 'audio' ? 8 : 4,
                'max_participants' => $roomType === 'audio' ? 50 : 12,
            ]
        );
    }

    private function ensureParticipant(LiveRoom $room, User $user, string $role, Carbon $joinedAt, ?Carbon $leftAt = null): void
    {
        LiveRoomParticipant::query()->updateOrCreate(
            ['live_room_id' => $room->id, 'user_id' => $user->id, 'role' => $role],
            ['joined_at' => $joinedAt, 'left_at' => $leftAt]
        );
    }

    private function ensureCall(
        string $roomName,
        User $caller,
        User $receiver,
        Host $host,
        Agency $agency,
        string $type,
        Carbon $startedAt,
        Carbon $endedAt,
        CallBillingService $billing,
    ): void {
        $call = CallSession::query()->firstOrCreate(
            ['livekit_room_name' => $roomName],
            [
                'caller_id' => $caller->id,
                'receiver_id' => $receiver->id,
                'host_id' => $host->id,
                'agency_id' => $agency->id,
                'type' => $type,
                'status' => 'ended',
                'started_at' => $startedAt,
                'accepted_at' => $startedAt,
                'ended_at' => $endedAt,
                'coin_rate_per_minute' => (int) ($type === 'video'
                    ? config('calls.video_coin_rate_per_minute', config('calls.coin_rate_per_minute', 20))
                    : config('calls.audio_coin_rate_per_minute', config('calls.coin_rate_per_minute', 20))),
                'end_reason' => 'completed',
            ]
        );

        if (!$call->billing_processed_at) {
            $billing->processEndedCall($call);
        }

        CallEarningLedger::query()
            ->where('call_session_id', $call->id)
            ->update([
                'created_at' => $endedAt,
                'updated_at' => $endedAt,
            ]);

        WalletTransaction::query()
            ->where('reference', $billing->billingReference($call->id))
            ->update([
                'created_at' => $endedAt,
                'updated_at' => $endedAt,
            ]);

        $call->forceFill([
            'created_at' => $startedAt,
            'updated_at' => $endedAt,
        ])->save();
    }

    private function ensureRoomGift(
        string $reference,
        LiveRoom $room,
        Gift $gift,
        User $sender,
        User $hostUser,
        Agency $agency,
        int $quantity,
        Carbon $createdAt,
    ): LiveRoomGift {
        $existing = LiveRoomGift::query()->where('meta->reference', $reference)->first();
        if ($existing) {
            return $existing;
        }

        $totalCoins = (int) $gift->coins * $quantity;
        $spend = WalletService::spend(
            $sender,
            $totalCoins,
            'gift',
            $hostUser,
            $reference,
            [
                'event' => 'LIVE_ROOM_GIFT',
                'room_id' => $room->room_id,
                'gift_id' => $gift->id,
                'gift_name' => $gift->name,
                'quantity' => $quantity,
                'host_user_id' => $hostUser->id,
            ]
        );

        $host = Host::query()->whereKey($room->host_id)->firstOrFail();
        $hostSharePercent = (float) ($host->payout_percentage ?? 60);
        $agencySharePercent = (float) ($agency->payout_percentage ?? 10);
        $hostEarning = (int) floor(($totalCoins * $hostSharePercent) / 100);
        $agencyEarning = (int) floor(($totalCoins * $agencySharePercent) / 100);
        if (($hostEarning + $agencyEarning) > $totalCoins) {
            $agencyEarning = max(0, $totalCoins - $hostEarning);
        }
        $platformEarning = max(0, $totalCoins - $hostEarning - $agencyEarning);

        $roomGift = LiveRoomGift::query()->create([
            'live_room_id' => $room->id,
            'gift_id' => $gift->id,
            'sender_user_id' => $sender->id,
            'quantity' => $quantity,
            'coins_per_unit' => (int) $gift->coins,
            'total_coins' => $totalCoins,
            'transaction_id' => (string) $spend->id,
            'meta' => [
                'reference' => $reference,
                'host_earning' => $hostEarning,
                'agency_earning' => $agencyEarning,
                'platform_earning' => $platformEarning,
            ],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        LiveRoomGiftEarningLedger::query()->create([
            'live_room_gift_id' => $roomGift->id,
            'live_room_id' => $room->id,
            'sender_user_id' => $sender->id,
            'host_id' => $room->host_id,
            'agency_id' => $agency->id,
            'total_coins' => $totalCoins,
            'host_payout_percentage' => $hostSharePercent,
            'agency_payout_percentage' => $agencySharePercent,
            'host_payout_coins' => $hostEarning,
            'agency_payout_coins' => $agencyEarning,
            'platform_revenue_coins' => $platformEarning,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        WalletTransaction::query()
            ->whereKey($spend->id)
            ->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

        return $roomGift;
    }
}
