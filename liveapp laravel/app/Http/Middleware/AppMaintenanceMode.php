<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AppMaintenanceMode
{
    public function handle(Request $request, Closure $next)
    {
        if (!(bool) config('app_features.maintenance_mode_enabled', false)) {
            return $next($request);
        }

        if ($this->shouldBypass($request)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'ok' => false,
                'error' => 'MAINTENANCE_MODE',
                'message' => 'The platform is temporarily unavailable for maintenance.',
            ], 503);
        }

        return response()->view('maintenance', status: 503);
    }

    private function shouldBypass(Request $request): bool
    {
        if ($request->is('admin') || $request->is('admin/*')) {
            return true;
        }

        if ($request->is('up')) {
            return true;
        }

        if ($request->is('api/ping') || $request->is('api/health/*') || $request->is('api/metrics')) {
            return true;
        }

        if ($request->is('api/app-config') || $request->is('api/app/settings')) {
            return true;
        }

        return false;
    }
}
