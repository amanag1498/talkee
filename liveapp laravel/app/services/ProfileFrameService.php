<?php

namespace App\Services;

use App\Models\ProfileFrame;
use App\Models\User;
use App\Models\UserProfileFrame;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProfileFrameService
{
    public const TYPE_UNLOCKED = 'profile_frame_unlocked';

    public function equippedFramePayload(User $user): ?array
    {
        $ownership = $this->equippedOwnership($user);

        return $ownership ? $this->framePayload($ownership->profileFrame, $ownership) : null;
    }

    public function inventoryPayload(User $user): array
    {
        $ownerships = UserProfileFrame::query()
            ->with('profileFrame')
            ->where('user_id', $user->id)
            ->orderByDesc('is_equipped')
            ->orderByDesc('granted_at')
            ->orderByDesc('id')
            ->get()
            ->keyBy('profile_frame_id');

        $frames = ProfileFrame::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(function (ProfileFrame $frame) use ($ownerships): bool {
                /** @var UserProfileFrame|null $ownership */
                $ownership = $ownerships->get($frame->id);

                return $ownership !== null && !$this->isExpired($ownership);
            })
            ->values();

        return $frames->map(function (ProfileFrame $frame) use ($ownerships): array {
            /** @var UserProfileFrame|null $ownership */
            $ownership = $ownerships->get($frame->id);

            return $this->framePayload($frame, $ownership);
        })->values()->all();
    }

    public function equip(User $user, int $profileFrameId): array
    {
        return DB::transaction(function () use ($user, $profileFrameId): array {
            $frame = ProfileFrame::query()
                ->where('is_active', true)
                ->findOrFail($profileFrameId);

            $ownership = UserProfileFrame::query()
                ->where('user_id', $user->id)
                ->where('profile_frame_id', $frame->id)
                ->first();

            if (!$ownership) {
                abort(422, 'This profile frame is not available for your account.');
            }

            if ($this->isExpired($ownership)) {
                abort(422, 'This profile frame has expired.');
            }

            UserProfileFrame::query()
                ->where('user_id', $user->id)
                ->where('is_equipped', true)
                ->update(['is_equipped' => false]);

            $ownership->forceFill([
                'is_equipped' => true,
            ])->save();

            return $this->framePayload(
                $frame->fresh(),
                $ownership->fresh(['profileFrame'])
            );
        });
    }

    public function grantBySlug(
        User $user,
        string $slug,
        string $source,
        ?Carbon $expiresAt = null,
        bool $autoEquip = false,
    ): UserProfileFrame {
        $frame = ProfileFrame::query()
            ->where('slug', trim($slug))
            ->where('is_active', true)
            ->firstOrFail();

        return $this->grant($user, $frame, $source, $expiresAt, $autoEquip);
    }

    public function grant(
        User $user,
        ProfileFrame $frame,
        string $source,
        ?Carbon $expiresAt = null,
        bool $autoEquip = false,
    ): UserProfileFrame {
        return DB::transaction(function () use ($user, $frame, $source, $expiresAt, $autoEquip): UserProfileFrame {
            $ownership = UserProfileFrame::query()->firstOrNew([
                'user_id' => $user->id,
                'profile_frame_id' => $frame->id,
            ]);

            $currentExpiry = $ownership->expires_at;
            $ownership->forceFill([
                'source' => $source,
                'granted_at' => $ownership->granted_at ?? now(),
                'expires_at' => $this->laterExpiry($currentExpiry, $expiresAt),
            ])->save();

            if ($autoEquip) {
                UserProfileFrame::query()
                    ->where('user_id', $user->id)
                    ->where('is_equipped', true)
                    ->update(['is_equipped' => false]);

                $ownership->forceFill(['is_equipped' => true])->save();
            }

            return $ownership->fresh(['profileFrame']);
        });
    }

    public function framePayload(ProfileFrame $frame, ?UserProfileFrame $ownership = null): array
    {
        $owned = $ownership !== null && !$this->isExpired($ownership);
        $equipped = $owned && $ownership->is_equipped;

        return [
            'id' => (int) $frame->id,
            'name' => (string) $frame->name,
            'slug' => (string) $frame->slug,
            'asset_url' => $frame->asset_url,
            'thumbnail_url' => $frame->thumbnail_url ?: $frame->asset_url,
            'rarity' => (string) ($frame->rarity ?? 'rare'),
            'category' => (string) ($frame->category ?? 'general'),
            'unlock_type' => (string) ($frame->unlock_type ?? 'free_catalog'),
            'valid_days' => $frame->valid_days,
            'sort_order' => (int) ($frame->sort_order ?? 0),
            'is_active' => (bool) $frame->is_active,
            'owned' => $owned,
            'can_equip' => (bool) $frame->is_active && $owned,
            'is_equipped' => $equipped,
            'source' => $ownership?->source,
            'granted_at' => optional($ownership?->granted_at)->toIso8601String(),
            'expires_at' => optional($ownership?->expires_at)->toIso8601String(),
            'is_expired' => $ownership ? $this->isExpired($ownership) : false,
        ];
    }

    public function notifyUnlocked(
        User $user,
        UserProfileFrame $ownership,
        string $title,
        string $body,
        bool $push = false,
    ): void {
        $frame = $ownership->profileFrame ?: $ownership->loadMissing('profileFrame')->profileFrame;
        if (!$frame) {
            return;
        }

        try {
            NotifyUser::send($user->id, [
                'type' => self::TYPE_UNLOCKED,
                'title' => $title,
                'body' => $body,
                'screen' => 'profile',
                'meta' => [
                    'profile_frame' => $this->framePayload($frame, $ownership),
                ],
            ], [
                'push' => $push,
                'persist' => true,
            ]);
        } catch (\Throwable) {
            // Frame reward feedback must never block approval/admin flows.
        }
    }

    private function equippedOwnership(User $user): ?UserProfileFrame
    {
        return UserProfileFrame::query()
            ->with('profileFrame')
            ->where('user_id', $user->id)
            ->where('is_equipped', true)
            ->orderByDesc('id')
            ->get()
            ->first(fn (UserProfileFrame $ownership) => !$this->isExpired($ownership) && $ownership->profileFrame?->is_active);
    }

    private function isExpired(UserProfileFrame $ownership): bool
    {
        return $ownership->expires_at !== null && $ownership->expires_at->isPast();
    }

    private function laterExpiry(?Carbon $current, ?Carbon $incoming): ?Carbon
    {
        if ($incoming === null) {
            return $current;
        }

        if ($current === null) {
            return $incoming;
        }

        return $incoming->greaterThan($current) ? $incoming : $current;
    }
}
