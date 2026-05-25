<?php

namespace App\Providers;

use App\Services\AppSettingsService;
use App\Support\OpsMetrics;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AppSettingsService::class);
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        try {
            if (Schema::hasTable('app_settings')) {
                app(AppSettingsService::class)->loadAppSettingsIntoConfig();
                app(AppSettingsService::class)->loadCallSettingsIntoConfig();
                app(AppSettingsService::class)->loadLiveRoomSettingsIntoConfig();
                app(AppSettingsService::class)->loadGameSettingsIntoConfig();
            }
        } catch (\Throwable $e) {
            Log::warning('APP_SETTINGS_BOOT_SKIP', [
                'message' => $e->getMessage(),
            ]);
        }

        if ((bool) env('LOG_SQL_QUERIES', false)) {
            $slowMs = (int) env('LOG_SLOW_QUERY_MS', 200);

            DB::listen(function ($query) use ($slowMs): void {
                $duration = (float) $query->time;
                $payload = [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'time_ms' => $duration,
                    'connection' => $query->connectionName,
                ];

                if ($duration >= $slowMs) {
                    Log::warning('DB_QUERY_SLOW', $payload);
                } else {
                    Log::debug('DB_QUERY', $payload);
                }
            });
        }

        Queue::failing(function (JobFailed $event): void {
            OpsMetrics::increment(OpsMetrics::QUEUE_FAILURES);

            Log::error('QUEUE_JOB_FAILED', [
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job_name' => $event->job->resolveName(),
                'job_id' => method_exists($event->job, 'getJobId') ? $event->job->getJobId() : null,
                'exception' => $event->exception->getMessage(),
            ]);
        });

        Log::info('QUEUE_WORKER_POLICY', [
            'tries' => (int) config('queue.worker.tries', 3),
            'timeout' => (int) config('queue.worker.timeout', 60),
            'backoff' => (int) config('queue.worker.backoff', 5),
            'sleep' => (int) config('queue.worker.sleep', 3),
            'max_time' => (int) config('queue.worker.max_time', 3600),
            'max_jobs' => (int) config('queue.worker.max_jobs', 1000),
        ]);
    }
}
