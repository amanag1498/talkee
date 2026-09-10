<?php

namespace App\Http\Middleware;

use App\Services\AppSettingsService;
use Closure;
use Illuminate\Http\Request;

class EnforceAndroidClientVersion
{
    public function __construct(private AppSettingsService $settings)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if (!$request->is('api/*') || $this->shouldBypass($request)) {
            return $next($request);
        }

        if (!(bool) config('app_features.force_app_upgrade_enabled', false)) {
            return $next($request);
        }

        $platform = strtolower(trim((string) $request->header('X-Client-Platform', '')));
        $versionCode = (int) $request->header('X-App-Version-Code', 0);
        $minimumVersionCode = $this->settings->minimumAndroidVersionCode();

        if ($platform === '') {
            return $this->reject(
                'APP_UPGRADE_REQUIRED',
                $this->settings->androidUpdateMessage(),
                $minimumVersionCode,
            );
        }

        if ($platform !== 'android') {
            return $next($request);
        }

        if ($versionCode < $minimumVersionCode) {
            return $this->reject(
                'APP_UPGRADE_REQUIRED',
                $this->settings->androidUpdateMessage(),
                $minimumVersionCode,
            );
        }

        return $next($request);
    }

    private function shouldBypass(Request $request): bool
    {
        return $this->isTrustedRealtimeServerRequest($request)
            || $this->isPaymentProviderCallback($request)
            || $request->is('api/ping')
            || $request->is('api/health/*')
            || $request->is('api/metrics')
            || $request->is('api/app-config')
            || $request->is('api/app/settings');
    }

    private function isPaymentProviderCallback(Request $request): bool
    {
        return $request->is('api/payments/razorpay/webhook')
            || $request->is('api/payments/apple/notifications');
    }

    private function isTrustedRealtimeServerRequest(Request $request): bool
    {
        $expected = trim((string) config('services.websocket.internal_key', ''));
        $provided = trim((string) $request->header('X-WS-Internal-Key', ''));

        return $expected !== ''
            && $provided !== ''
            && hash_equals($expected, $provided);
    }

    private function reject(string $error, string $message, int $minimumVersionCode)
    {
        return response()->json([
            'ok' => false,
            'error' => $error,
            'message' => $message,
            'minimum_android_version_code' => $minimumVersionCode,
        ], 426);
    }
}
