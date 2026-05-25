<?php

namespace App\Services;

use App\Models\EntryPack;
use App\Models\LiveRoom;
use App\Models\User;
use App\Models\UserEntryPack;
use App\Models\WalletTransaction;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class EntryPackService
{
    private const ENTRY_EFFECT_CHANNEL = 'rooms:entry-effects';
    private const ENTRY_COOLDOWN_SECONDS = 0;
    private const ENTRY_EVENT_MAX_AGE_SECONDS = 8;

    public function listForUser(?User $user): array
    {
        $ownedByPackId = [];
        $activePackId = null;

        if ($user) {
            $owned = UserEntryPack::query()
                ->with('entryPack:id,name,is_active')
                ->where('user_id', $user->id)
                ->latest('id')
                ->get();

            foreach ($owned as $userPack) {
                $ownedByPackId[(int) $userPack->entry_pack_id] = true;
                if (
                    $activePackId === null &&
                    $userPack->is_active &&
                    $userPack->entryPack?->is_active &&
                    (!$userPack->expires_at || $userPack->expires_at->isFuture())
                ) {
                    $activePackId = (int) $userPack->entry_pack_id;
                }
            }
        }

        return EntryPack::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->map(fn (EntryPack $pack) => $this->packPayload(
                $pack,
                owned: isset($ownedByPackId[$pack->id]),
                active: $activePackId === $pack->id,
            ))
            ->values()
            ->all();
    }

    public function purchase(User $user, EntryPack $pack, ?string $idempotencyKey = null): array
    {
        if (!$pack->is_active) {
            throw $this->error('ENTRY_PACK_INACTIVE', 'This entry pack is unavailable.', 409);
        }

        $normalizedKey = $idempotencyKey ? trim($idempotencyKey) : null;

        return DB::transaction(function () use ($user, $pack, $normalizedKey) {
            if ($normalizedKey) {
                $existingPurchase = UserEntryPack::query()
                    ->with('entryPack')
                    ->where('user_id', $user->id)
                    ->where('purchase_key', $normalizedKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingPurchase) {
                    return $this->ownedPackPayload($existingPurchase->fresh('entryPack'));
                }
            }

            $alreadyActive = UserEntryPack::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->exists();

            $reference = 'ENTRY_PACK_PURCHASE:'.$user->id.':'.$pack->id.':'.($normalizedKey ?: Str::uuid()->toString());

            $walletTx = null;
            if ((int) $pack->price_coins > 0) {
                try {
                    $walletTx = WalletService::spend(
                        user: $user,
                        coins: (int) $pack->price_coins,
                        category: 'other',
                        counterparty: null,
                        reference: $reference,
                        meta: [
                            'event' => 'ENTRY_PACK_PURCHASE',
                            'entry_pack_id' => $pack->id,
                            'entry_pack_name' => $pack->name,
                            'purchase_key' => $normalizedKey,
                        ],
                    );
                } catch (\InvalidArgumentException $e) {
                    throw $this->error('INSUFFICIENT_FUNDS', 'Not enough coins to purchase this entry pack.', 422);
                }
            }

            $userPack = UserEntryPack::query()->create([
                'user_id' => $user->id,
                'entry_pack_id' => $pack->id,
                'is_active' => !$alreadyActive,
                'purchased_at' => now(),
                'expires_at' => now()->addDays(max(1, (int) ($pack->duration_days ?? 30))),
                'purchase_key' => $normalizedKey,
            ]);

            if ((int) $pack->price_coins > 0) {
                DB::afterCommit(function () use ($user, $pack, $walletTx) {
                    try {
                        app(LeaderboardService::class)->recordEntryPurchase(
                            userId: (int) $user->id,
                            totalCoins: (int) $pack->price_coins,
                            occurredAt: $walletTx?->created_at ?? now(),
                        );
                    } catch (\Throwable $e) {
                        report($e);
                    }
                });
            }

            return $this->ownedPackPayload($userPack->fresh('entryPack'), walletTx: $walletTx);
        });
    }

    public function activate(User $user, EntryPack $pack): array
    {
        return DB::transaction(function () use ($user, $pack) {
            $userPack = UserEntryPack::query()
                ->with('entryPack')
                ->where('user_id', $user->id)
                ->where('entry_pack_id', $pack->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (!$userPack) {
                throw $this->error('ENTRY_PACK_NOT_OWNED', 'Purchase the entry pack before activating it.', 404);
            }

            if (!$pack->is_active) {
                throw $this->error('ENTRY_PACK_INACTIVE', 'This entry pack is unavailable.', 409);
            }

            if ($userPack->expires_at && $userPack->expires_at->isPast()) {
                $userPack->update(['is_active' => false]);
                throw $this->error('ENTRY_PACK_EXPIRED', 'This entry pack has expired.', 409);
            }

            UserEntryPack::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->whereKeyNot($userPack->id)
                ->update(['is_active' => false]);

            $userPack->update(['is_active' => true]);

            return $this->ownedPackPayload($userPack->fresh('entryPack'));
        });
    }

    public function activeForUser(User $user): ?UserEntryPack
    {
        return UserEntryPack::query()
            ->with('entryPack')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereHas('entryPack', fn ($query) => $query->where('is_active', true))
            ->orderByDesc('entry_pack_id')
            ->get()
            ->sortByDesc(fn (UserEntryPack $userPack) => (int) ($userPack->entryPack?->priority ?? 0))
            ->first();
    }

    public function maybeTriggerRoomEntryEffect(LiveRoom $room, User $user): ?array
    {
        $activeUserPack = $this->activeForUser($user);
        if (!$activeUserPack || !$activeUserPack->entryPack) {
            return null;
        }

        $pack = $activeUserPack->entryPack;
        if (self::ENTRY_COOLDOWN_SECONDS > 0) {
            $cooldownKey = sprintf('entry-pack:cooldown:%s:%s:%s', $room->room_id, $user->id, $pack->id);
            if (!Cache::add($cooldownKey, now()->toIso8601String(), self::ENTRY_COOLDOWN_SECONDS)) {
                return null;
            }
        }

        $payload = [
            'room_id' => (string) $room->room_id,
            'room_type' => (string) ($room->room_type ?? 'video'),
            'user_id' => (int) $user->id,
            'user_name' => (string) ($user->name ?? 'User'),
            'avatar_url' => $user->avatar_url,
            'entry_pack_id' => (int) $pack->id,
            'entry_pack_name' => (string) $pack->name,
            'svg_url' => $pack->svg_url,
            'asset_type' => $this->detectAssetType($pack->svg_url),
            'animation_style' => (string) $pack->animation_style,
            'priority' => (int) $pack->priority,
            'duration_ms' => max(2000, (int) $pack->duration_ms),
            'triggered_at' => now()->toIso8601String(),
            'max_age_ms' => self::ENTRY_EVENT_MAX_AGE_SECONDS * 1000,
        ];

        Redis::publish(self::ENTRY_EFFECT_CHANNEL, json_encode($payload));

        return $payload;
    }

    public function reportSummary(): array
    {
        $coinsSpent = (int) WalletTransaction::query()
            ->where('category', 'other')
            ->where('type', 'debit')
            ->where('reference', 'like', 'ENTRY_PACK_PURCHASE:%')
            ->sum('coins');

        $purchases = UserEntryPack::query()->count();
        $activeUsers = UserEntryPack::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->distinct('user_id')
            ->count('user_id');

        $topPacks = EntryPack::query()
            ->withCount('userPacks')
            ->orderByDesc('user_packs_count')
            ->orderByDesc('priority')
            ->limit(10)
            ->get()
            ->map(fn (EntryPack $pack) => [
                'id' => (int) $pack->id,
                'name' => (string) $pack->name,
                'purchases' => (int) $pack->user_packs_count,
                'price_coins' => (int) $pack->price_coins,
            ])
            ->values()
            ->all();

        return [
            'purchases' => $purchases,
            'coins_spent' => $coinsSpent,
            'active_users' => $activeUsers,
            'most_used_packs' => $topPacks,
        ];
    }

    public function activePackPayloadForUser(User $user): array
    {
        $active = $this->activeForUser($user);

        return [
            'active' => $active ? $this->ownedPackPayload($active) : null,
            'owned' => UserEntryPack::query()
                ->with('entryPack')
                ->where('user_id', $user->id)
                ->latest('id')
                ->get()
                ->map(fn (UserEntryPack $userPack) => $this->ownedPackPayload($userPack))
                ->values()
                ->all(),
        ];
    }

    public function packPayload(EntryPack $pack, bool $owned = false, bool $active = false): array
    {
        return [
            'id' => (int) $pack->id,
            'name' => (string) $pack->name,
            'price_coins' => (int) $pack->price_coins,
            'svg_url' => $pack->svg_url,
            'asset_type' => $this->detectAssetType($pack->svg_url),
            'animation_style' => (string) $pack->animation_style,
            'priority' => (int) $pack->priority,
            'duration_ms' => (int) $pack->duration_ms,
            'duration_days' => (int) ($pack->duration_days ?? 30),
            'is_active' => (bool) $pack->is_active,
            'sort_order' => (int) $pack->sort_order,
            'owned' => $owned,
            'active' => $active,
        ];
    }

    public function ownedPackPayload(UserEntryPack $userPack, ?WalletTransaction $walletTx = null): array
    {
        $pack = $userPack->entryPack;

        return [
            'id' => (int) $userPack->id,
            'user_id' => (int) $userPack->user_id,
            'entry_pack_id' => (int) $userPack->entry_pack_id,
            'is_active' => (bool) $userPack->is_active,
            'purchased_at' => optional($userPack->purchased_at)->toIso8601String(),
            'expires_at' => optional($userPack->expires_at)->toIso8601String(),
            'entry_pack' => $pack ? $this->packPayload($pack, owned: true, active: (bool) $userPack->is_active) : null,
            'wallet_transaction_id' => $walletTx?->id,
            'wallet_balance_after' => $walletTx ? (int) $walletTx->balance_after : null,
        ];
    }

    private function error(string $error, string $message, int $status): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'ok' => false,
            'error' => $error,
            'message' => $message,
        ], $status));
    }

    private function detectAssetType(?string $value): string
    {
        $path = strtolower(trim((string) $value));
        if ($path === '') {
            return 'svg';
        }

        return str_ends_with(parse_url($path, PHP_URL_PATH) ?: $path, '.svga')
            ? 'svga'
            : 'svg';
    }
}
