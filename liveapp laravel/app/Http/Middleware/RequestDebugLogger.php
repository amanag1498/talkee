<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RequestDebugLogger
{
    protected function enabled(): bool
    {
        return (bool) config('ops.request_debug_logger_enabled', false);
    }

    protected function shouldSkipPath(string $path): bool
    {
        if (str_starts_with($path, 'berry/')) {
            return true;
        }

        if (str_starts_with($path, 'storage/')) {
            return true;
        }

        return (bool) preg_match('/\.(css|js|map|png|jpe?g|gif|svg|ico|woff2?|ttf|eot)$/i', $path);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->enabled()) {
            /** @var Response $response */
            return $next($request);
        }

        if ($this->shouldSkipPath($request->path())) {
            /** @var Response $response */
            return $next($request);
        }

        $start = microtime(true);

        Log::info('HTTP_REQUEST_IN', [
            'request_id' => $request->header('X-Request-Id'),
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_id' => $request->user()?->id,
            'query' => $request->query(),
            'input_keys' => array_keys($request->except(['password', 'token', 'idToken'])),
        ]);

        /** @var Response $response */
        $response = $next($request);

        Log::info('HTTP_REQUEST_OUT', [
            'request_id' => $request->header('X-Request-Id'),
            'method' => $request->method(),
            'path' => $request->path(),
            'user_id' => $request->user()?->id,
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ]);

        return $response;
    }
}
