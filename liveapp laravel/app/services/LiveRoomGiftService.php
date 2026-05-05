<?php

namespace App\Services;

use App\Models\Gift;
use App\Models\LiveRoom;
use App\Models\LiveRoomGift;
use App\Models\LiveRoomGiftEarningLedger;
use App\Models\LiveRoomParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LiveRoomGiftService
{
    public function __construct(
        private LiveRoomPkService $pk,
        private ModerationService $moderation,
    )
    {
    }

    public function availableGifts(): array
    {
        return Gift::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('coins')
            ->get()
            ->map(fn (Gift $gift) => [
                'id' => (int) $gift->id,
                'name' => (string) $gift->name,
                'coins' => (int) $gift->coins,
                'gift_url' => $gift->gift_url,
                'gift_type' => $gift->gift_type,
                'animation_tier' => $gift->animation_tier,
                'animation_duration_ms' => $gift->animation_duration_ms,
                'is_active' => (bool) $gift->is_active,
            ])->values()->all();
    }

    public function send(LiveRoom $room, User $sender, Gift $gift, int $quantity = 1, ?string $message = null): array
    {
        if ($quantity < 1) {
            throw new HttpException(422, 'Quantity must be at least 1.');
        }

        if ($room->status !== 'live' || $room->ended_at) {
            throw new HttpException(409, 'Room is not live.');
        }

        $hostUser = $room->host?->user;
        if (!$hostUser) {
            throw new HttpException(409, 'Room host is missing.');
        }

        $this->moderation->assertNotBlockedByHostUserId(
            $hostUser->id,
            $sender->id,
            'You were blocked by this host.',
        );

        $participant = LiveRoomParticipant::query()
            ->where('live_room_id', $room->id)
            ->where('user_id', $sender->id)
            ->whereNull('left_at')
            ->latest('id')
            ->first();

        if (!$participant) {
            throw new HttpException(409, 'Join the room before sending gifts.');
        }

        if (!$gift->is_active) {
            throw new HttpException(409, 'Gift is not available.');
        }

        $totalCoins = (int) $gift->coins * $quantity;
        $reference = 'ROOM_GIFT:'.$room->room_id.':'.$sender->id.':'.Str::uuid()->toString();

        $result = DB::transaction(function () use ($room, $sender, $gift, $quantity, $message, $hostUser, $totalCoins, $reference) {
            $host = $room->host;
            $agency = $host?->agency;
            $agencyOwner = $agency?->owner;

            $hostSharePercent = max(0, min(100, (float) (($host?->payout_percentage ?? 0) > 0
                ? $host->payout_percentage
                : config('calls.host_share_percent', 60))));

            $agencySharePercent = $agency
                ? max(0, min(100, (float) (($agency->payout_percentage ?? 0) > 0
                    ? $agency->payout_percentage
                    : config('calls.agency_share_percent', 10))))
                : 0.0;

            $hostEarning = (int) floor(($totalCoins * $hostSharePercent) / 100);
            $agencyEarning = $agencyOwner ? (int) floor(($totalCoins * $agencySharePercent) / 100) : 0;
            if (($hostEarning + $agencyEarning) > $totalCoins) {
                $agencyEarning = max(0, $totalCoins - $hostEarning);
            }
            if ($agencyOwner && $agencyOwner->id === $hostUser->id) {
                $hostEarning += $agencyEarning;
                $agencyEarning = 0;
            }
            $platformEarning = max(0, $totalCoins - $hostEarning - $agencyEarning);

            $walletTransaction = WalletService::spend(
                user: $sender,
                coins: $totalCoins,
                category: 'gift',
                counterparty: $hostUser,
                reference: $reference,
                meta: [
                    'event' => 'LIVE_ROOM_GIFT',
                    'room_id' => $room->room_id,
                    'gift_id' => $gift->id,
                    'gift_name' => $gift->name,
                    'quantity' => $quantity,
                    'message' => $message,
                    'host_user_id' => $hostUser->id,
                ]
            );

            $roomGift = LiveRoomGift::query()->create([
                'live_room_id' => $room->id,
                'gift_id' => $gift->id,
                'sender_user_id' => $sender->id,
                'quantity' => $quantity,
                'coins_per_unit' => (int) $gift->coins,
                'total_coins' => $totalCoins,
                'transaction_id' => (string) $walletTransaction->id,
                'meta' => [
                    'message' => $message,
                    'wallet_transaction_id' => $walletTransaction->id,
                    'reference' => $reference,
                    'host_earning' => $hostEarning,
                    'agency_earning' => $agencyEarning,
                    'platform_earning' => $platformEarning,
                    'host_share_percent' => $hostSharePercent,
                    'agency_share_percent' => $agencySharePercent,
                ],
            ]);

            $giftLedger = LiveRoomGiftEarningLedger::query()->create([
                'live_room_gift_id' => $roomGift->id,
                'live_room_id' => $room->id,
                'sender_user_id' => $sender->id,
                'host_id' => $host->id,
                'agency_id' => $agency?->id,
                'total_coins' => $totalCoins,
                'host_payout_percentage' => $hostSharePercent,
                'agency_payout_percentage' => $agencySharePercent,
                'host_payout_coins' => $hostEarning,
                'agency_payout_coins' => $agencyEarning,
                'platform_revenue_coins' => $platformEarning,
            ]);

            DB::afterCommit(function () use ($sender, $host, $agency, $totalCoins, $roomGift) {
                try {
                    app(LeaderboardService::class)->recordGiftSuccess(
                        senderUserId: (int) $sender->id,
                        hostId: (int) $host->id,
                        agencyId: $agency?->id ? (int) $agency->id : null,
                        totalCoins: (int) $totalCoins,
                        occurredAt: $roomGift->created_at,
                    );
                } catch (\Throwable $e) {
                    report($e);
                }
            });

            $room->forceFill(['last_activity_at' => now()])->save();

            return [$roomGift->fresh(['gift', 'sender']), $walletTransaction->fresh(), $giftLedger];
        });

        [$roomGift, $walletTransaction] = $result;
        try {
            $this->pk->recordGiftScore($room, $walletTransaction, $totalCoins, $gift->id, $sender);
        } catch (\Throwable $e) {
            report($e);
        }
        $payload = $this->payload($roomGift, $room, $hostUser);

        Redis::publish('rooms:gift-events', json_encode($payload));

        return [
            'gift' => $payload,
            'wallet_transaction_id' => (int) $walletTransaction->id,
            'balance_after' => (int) $sender->wallet()->value('balance'),
        ];
    }

    public function payload(LiveRoomGift $roomGift, LiveRoom $room, User $hostUser): array
    {
        $activeBattle = $this->pk->activeForRoom($room);
        $pkSide = null;
        $opponentRoomId = null;
        if ($activeBattle) {
            if ((int) $activeBattle->room_a_id === (int) $room->id) {
                $pkSide = 'left';
                $opponentRoomId = $activeBattle->roomB?->room_id;
            } elseif ((int) $activeBattle->room_b_id === (int) $room->id) {
                $pkSide = 'right';
                $opponentRoomId = $activeBattle->roomA?->room_id;
            }
        }

        return [
            'event' => 'room:gift',
            'room_id' => (string) $room->room_id,
            'room_type' => (string) ($room->room_type ?? 'video'),
            'pk_battle_id' => $activeBattle?->battle_id,
            'pk_side' => $pkSide,
            'opponent_room_id' => $opponentRoomId,
            'host_user_id' => (int) $hostUser->id,
            'sender_user_id' => (int) $roomGift->sender_user_id,
            'sender_name' => (string) ($roomGift->sender?->name ?? 'User'),
            'gift_id' => (int) $roomGift->gift_id,
            'gift_name' => (string) ($roomGift->gift?->name ?? 'Gift'),
            'gift_url' => $roomGift->gift?->gift_url,
            'gift_type' => $roomGift->gift?->gift_type,
            'animation_tier' => $roomGift->gift?->animation_tier,
            'animation_duration_ms' => $roomGift->gift?->animation_duration_ms,
            'quantity' => (int) $roomGift->quantity,
            'coins_per_unit' => (int) $roomGift->coins_per_unit,
            'total_coins' => (int) $roomGift->total_coins,
            'message' => data_get($roomGift->meta, 'message'),
            'participant_count' => LiveRoomParticipant::query()
                ->where('live_room_id', $room->id)
                ->whereNull('left_at')
                ->count(),
            'speaker_count' => LiveRoomParticipant::query()
                ->where('live_room_id', $room->id)
                ->whereNull('left_at')
                ->where('role', 'speaker')
                ->count() + LiveRoomParticipant::query()
                    ->where('live_room_id', $room->id)
                    ->whereNull('left_at')
                    ->where('role', 'host')
                    ->count(),
            'listener_count' => $room->room_type === 'audio'
                ? LiveRoomParticipant::query()
                    ->where('live_room_id', $room->id)
                    ->whereNull('left_at')
                    ->where('role', 'listener')
                    ->count()
                : LiveRoomParticipant::query()
                    ->where('live_room_id', $room->id)
                    ->whereNull('left_at')
                    ->where('role', 'viewer')
                    ->count(),
            'created_at' => optional($roomGift->created_at)->toIso8601String() ?? now()->toIso8601String(),
        ];
    }
}
