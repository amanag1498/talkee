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

        if ($platform !== 'android') {
            return $this->reject(
                'UNSUPPORTED_CLIENT_PLATFORM',
                'Android client headers are required to access this API while a mandatory upgrade is active.',
                $minimumVersionCode,
            );
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
        return $request->is('api/ping')
            || $request->is('api/health/*')
            || $request->is('api/metrics')
            || $request->is('api/app-config')
            || $request->is('api/app/settings');
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
