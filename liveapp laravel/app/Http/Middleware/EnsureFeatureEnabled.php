<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $featureKey)
    {
        $configKey = "app_features.platform.android.{$featureKey}";
        if ((bool) config($configKey, true)) {
            return $next($request);
        }

        return response()->json([
            'ok' => false,
            'error' => 'FEATURE_DISABLED',
            'feature' => $featureKey,
            'message' => $this->messageFor($featureKey),
        ], 403);
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
            default => 'This feature is currently unavailable.',
        };
    }
}
