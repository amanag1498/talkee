<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Exceptions\PostTooLargeException;
use App\Http\Middleware\AppMaintenanceMode;
use App\Http\Middleware\EnforceAndroidClientVersion;
use App\Http\Middleware\EnsureNotBlocked;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureLiveRoomFeatureEnabled;
use App\Http\Middleware\RequestDebugLogger;

// Spatie (correct namespace is "Middleware", singular)
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('calls:timeout-missed')->everyMinute()->withoutOverlapping();
        $schedule->command('calls:cleanup-stale-availability 120')->everyTwoMinutes()->withoutOverlapping();
        $schedule->command('calls:reconcile-billing')->hourly()->withoutOverlapping();
        $schedule->command('live-rooms:cleanup --stale-minutes=2')->everyMinute()->withoutOverlapping();
        $schedule->command('live-rooms:remind-hosts --lead-minutes=2')->everyMinute()->withoutOverlapping();
        $schedule->command('live-rooms:sync-redis')->everyTenMinutes()->withoutOverlapping();
        $schedule->command('live-rooms:reconcile')->hourly()->withoutOverlapping();
        $schedule->command('agency:payout-reports:generate')
            ->weeklyOn(
                (int) config('agency_payouts.schedule_day', 1),
                config('agency_payouts.schedule_time', '00:10')
            )
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware) {
        // ⬇️ Register aliases so you can use "role:admin" in routes
$middleware->alias([
        'not_blocked'        => EnsureNotBlocked::class,
        'feature_enabled'    => EnsureFeatureEnabled::class,
        'live_room_feature_enabled' => EnsureLiveRoomFeatureEnabled::class,
        'role'               => RoleMiddleware::class,
        'permission'         => PermissionMiddleware::class,
        'role_or_permission' => RoleOrPermissionMiddleware::class,
]);

        // Global request/response logs
        $middleware->append(RequestDebugLogger::class);
        $middleware->append(AppMaintenanceMode::class);
        $middleware->append(EnforceAndroidClientVersion::class);

        // App uses Firebase-based auth flow and does not define a "login" route.
        // Redirect guests to home instead of route('login').
        $middleware->redirectGuestsTo(fn (Request $request) => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if (!$request->expectsJson()) {
                return back()->withErrors([
                    'asset_file' => 'The uploaded file is larger than the server limit. Increase PHP upload_max_filesize, post_max_size, and nginx client_max_body_size.',
                    'gift_file' => 'The uploaded file is larger than the server limit. Increase PHP upload_max_filesize, post_max_size, and nginx client_max_body_size.',
                ])->withInput();
            }

            return response()->json([
                'ok' => false,
                'error' => 'POST_TOO_LARGE',
                'message' => 'Uploaded file exceeds the server size limit.',
            ], 413);
        });
    })
    ->create();
