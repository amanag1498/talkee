<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserGameAccess;

class GameAccessService
{
    public const GAME_TEEN_PATTI = 'teen_patti';
    public const GAME_GREEDY = 'greedy';

    public function supportedGames(): array
    {
        return [
            self::GAME_TEEN_PATTI,
            self::GAME_GREEDY,
        ];
    }

    public function userAccessMap(?User $user): array
    {
        $map = array_fill_keys($this->supportedGames(), false);

        if (!$user) {
            return $map;
        }

        $allowed = $user->relationLoaded('gameAccesses')
            ? $user->gameAccesses->pluck('game_key')
            : UserGameAccess::query()
                ->where('user_id', $user->id)
                ->pluck('game_key');

        foreach ($allowed as $gameKey) {
            if (array_key_exists($gameKey, $map)) {
                $map[$gameKey] = true;
            }
        }

        return $map;
    }

    public function userHasAccess(?User $user, string $gameKey): bool
    {
        $gameKey = strtolower(trim($gameKey));

        if (!$user || !in_array($gameKey, $this->supportedGames(), true)) {
            return false;
        }

        if ($user->relationLoaded('gameAccesses')) {
            return $user->gameAccesses->contains(fn (UserGameAccess $access) => $access->game_key === $gameKey);
        }

        return UserGameAccess::query()
            ->where('user_id', $user->id)
            ->where('game_key', $gameKey)
            ->exists();
    }

    public function syncUserAccess(User $user, array $requestedAccess, ?User $admin = null): array
    {
        $normalized = collect($requestedAccess)
            ->mapWithKeys(fn ($enabled, $gameKey) => [strtolower(trim((string) $gameKey)) => (bool) $enabled]);

        $supported = collect($this->supportedGames());
        $finalMap = $supported
            ->mapWithKeys(fn (string $gameKey) => [$gameKey => (bool) $normalized->get($gameKey, false)])
            ->all();

        $existing = UserGameAccess::query()
            ->where('user_id', $user->id)
            ->whereIn('game_key', $this->supportedGames())
            ->get()
            ->keyBy('game_key');

        foreach ($finalMap as $gameKey => $enabled) {
            if ($enabled) {
                UserGameAccess::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'game_key' => $gameKey,
                    ],
                    [
                        'granted_by' => $admin?->id,
                        'metadata' => [
                            'source' => 'admin_user_360',
                        ],
                    ],
                );
            } elseif ($existing->has($gameKey)) {
                $existing->get($gameKey)?->delete();
            }
        }

        return $finalMap;
    }
}
