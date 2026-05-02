<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\FirebaseAdminConfig;
use App\Support\OpsMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Kreait\Firebase\Factory;

class OpsController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'status' => 'up',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function ready(): JsonResponse
    {
        $dependencies = [
            'db' => $this->checkDb(),
            'redis' => $this->checkRedis(),
            'firebase' => $this->checkFirebase(),
            'queue' => $this->checkQueue(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
        ];

        $failed = collect($dependencies)->contains(fn (array $dep) => ($dep['ok'] ?? false) !== true);
        $statusCode = $failed ? 503 : 200;

        return response()->json([
            'ok' => !$failed,
            'status' => $failed ? 'degraded' : 'up',
            'timestamp' => now()->toIso8601String(),
            'dependencies' => $dependencies,
            'bootstrap' => $this->bootstrapSummary(),
        ], $statusCode);
    }

    public function metrics(Request $request): JsonResponse
    {
        $configuredKey = (string) config('ops.metrics.key', '');
        if ($configuredKey !== '' && !hash_equals($configuredKey, (string) $request->header('X-Metrics-Key', ''))) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 401);
        }

        return response()->json([
            'ok' => true,
            'timestamp' => now()->toIso8601String(),
            'counters' => OpsMetrics::snapshot(),
        ]);
    }

    private function checkDb(): array
    {
        try {
            DB::select('select 1 as ok');

            return ['ok' => true, 'driver' => config('database.default')];
        } catch (\Throwable $e) {
            $this->logHealthDependencyFailure('db', $e);

            return $this->failDependency('db', $e);
        }
    }

    private function checkRedis(): array
    {
        try {
            $connection = config('database.redis.client') === 'predis'
                ? Redis::connection()->client()->ping()
                : Redis::connection()->ping();

            return [
                'ok' => true,
                'driver' => config('database.redis.client'),
                'ping' => is_scalar($connection) ? (string) $connection : 'PONG',
            ];
        } catch (\Throwable $e) {
            $this->logHealthDependencyFailure('redis', $e);

            return $this->failDependency('redis', $e);
        }
    }

    private function checkFirebase(): array
    {
        try {
            $serviceAccountPath = FirebaseAdminConfig::serviceAccountPath();
            if (!is_file($serviceAccountPath)) {
                throw new \RuntimeException('Service account not found');
            }

            $projectId = FirebaseAdminConfig::projectId();
            if (!$projectId) {
                throw new \RuntimeException('Firebase project id missing');
            }

            // Smoke test Admin SDK initialization with the configured credentials.
            (new Factory())
                ->withServiceAccount($serviceAccountPath)
                ->withProjectId($projectId)
                ->createAuth();

            return [
                'ok' => true,
                'project_id' => $projectId,
                'service_account_present' => true,
            ];
        } catch (\Throwable $e) {
            $this->logHealthDependencyFailure('firebase', $e);

            return $this->failDependency('firebase', $e);
        }
    }

    private function checkQueue(): array
    {
        try {
            $connectionName = config('queue.default');
            $config = (array) config("queue.connections.{$connectionName}", []);
            $driver = (string) ($config['driver'] ?? 'unknown');

            if ($driver === 'database') {
                $table = (string) ($config['table'] ?? 'jobs');
                if (!Schema::hasTable($table)) {
                    throw new \RuntimeException("Queue table [{$table}] missing");
                }
                DB::table($table)->limit(1)->get();
            }

            if ($driver === 'redis') {
                Redis::connection((string) ($config['connection'] ?? 'default'))->ping();
            }

            return [
                'ok' => true,
                'connection' => $connectionName,
                'driver' => $driver,
                'retry_after' => $config['retry_after'] ?? null,
            ];
        } catch (\Throwable $e) {
            $this->logHealthDependencyFailure('queue', $e);

            return $this->failDependency('queue', $e);
        }
    }

    private function checkCache(): array
    {
        try {
            $store = config('cache.default');
            cache()->put('ops_health_check', 'ok', now()->addSeconds(10));
            $value = cache()->get('ops_health_check');

            return [
                'ok' => $value === 'ok',
                'store' => $store,
            ];
        } catch (\Throwable $e) {
            $this->logHealthDependencyFailure('cache', $e);

            return $this->failDependency('cache', $e);
        }
    }

    private function checkStorage(): array
    {
        try {
            $disk = Storage::disk(config('filesystems.default'));
            $path = 'ops-health-check.txt';
            $disk->put($path, 'ok');
            $contents = $disk->get($path);
            $disk->delete($path);

            return [
                'ok' => $contents === 'ok',
                'disk' => config('filesystems.default'),
            ];
        } catch (\Throwable $e) {
            $this->logHealthDependencyFailure('storage', $e);

            return $this->failDependency('storage', $e);
        }
    }

    private function bootstrapSummary(): array
    {
        try {
            $usersTablePresent = Schema::hasTable('users');
            $rolesTablePresent = Schema::hasTable('roles');

            return [
                'users_table' => $usersTablePresent,
                'roles_table' => $rolesTablePresent,
                'roles_seeded' => $rolesTablePresent ? DB::table('roles')->count() : 0,
                'admin_users' => $usersTablePresent ? DB::table('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->where('roles.name', 'admin')
                    ->count() : 0,
            ];
        } catch (\Throwable $e) {
            return [
                'users_table' => false,
                'roles_table' => false,
                'roles_seeded' => 0,
                'admin_users' => 0,
            ];
        }
    }

    private function failDependency(string $name, \Throwable $e): array
    {
        $response = ['ok' => false, 'dependency' => $name];
        if ((bool) config('ops.health.expose_errors', false)) {
            $response['error'] = $e->getMessage();
        }

        return $response;
    }

    private function logHealthDependencyFailure(string $dependency, \Throwable $e): void
    {
        Log::warning('OPS_HEALTH_DEPENDENCY_FAIL', [
            'dependency' => $dependency,
            'error' => $e->getMessage(),
        ]);
    }
}
