<?php

namespace App\Http\Middleware;

use App\Services\GameAccessService;
use Closure;
use Illuminate\Http\Request;

class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $featureKey)
    {
        $configKey = "app_features.platform.android.{$featureKey}";
        if (!(bool) config($configKey, true)) {
            return response()->json([
                'ok' => false,
                'error' => 'FEATURE_DISABLED',
                'feature' => $featureKey,
                'message' => $this->messageFor($featureKey),
            ], 403);
        }

        $gameKey = match ($featureKey) {
            'teen_patti_enabled' => GameAccessService::GAME_TEEN_PATTI,
            'greedy_enabled' => GameAccessService::GAME_GREEDY,
            default => null,
        };

        if ($gameKey !== null) {
            $games = app(GameAccessService::class);

            if (!$games->userHasAccess($request->user(), $gameKey)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'FEATURE_DISABLED',
                    'feature' => $featureKey,
                    'message' => $this->lockedMessageFor($featureKey),
                ], 403);
            }
        }

        return $next($request);
    }

    private function messageFor(string $featureKey): string
    {
        return match ($featureKey) {
            'audio_rooms_enabled' => 'Audio rooms are currently unavailable.',
            'video_rooms_enabled' => 'Video rooms are currently unavailable.',
            'pk_battles_enabled' => 'PK battles are currently unavailable.',
            'gifts_enabled' => 'Gifts are currently unavailable.',
            'subscriptions_enabled' => 'Subscriptions are currently unavailable.',
            'entry_effects_enabled' => 'Entry effects are currently unavailable.',
            'wallet_recharge_enabled' => 'Wallet recharge is currently unavailable.',
            'host_calling_enabled' => 'Host calling is currently unavailable.',
            'teen_patti_enabled' => 'Teen Patti is currently unavailable.',
            'greedy_enabled' => 'Greedy is currently unavailable.',
            'video_room_games_enabled' => 'Live room games are currently unavailable.',
            default => 'This feature is currently unavailable.',
        };
    }

    private function lockedMessageFor(string $featureKey): string
    {
        return match ($featureKey) {
            'teen_patti_enabled' => 'Teen Patti is locked for this user.',
            'greedy_enabled' => 'Greedy is locked for this user.',
            default => $this->messageFor($featureKey),
        };
    }
}
