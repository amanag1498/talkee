<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class OpsMetrics
{
    public const AUTH_FAILURES = 'auth_failures';
    public const ROOM_JOINS = 'room_joins';
    public const QUEUE_FAILURES = 'queue_failures';

    private const CACHE_PREFIX = 'ops.metrics.';

    public static function increment(string $metric, int $value = 1): int
    {
        $key = self::CACHE_PREFIX.$metric;
        Cache::add($key, 0);

        return (int) Cache::increment($key, $value);
    }

    public static function get(string $metric): int
    {
        return (int) Cache::get(self::CACHE_PREFIX.$metric, 0);
    }

    public static function snapshot(): array
    {
        return [
            self::AUTH_FAILURES => self::get(self::AUTH_FAILURES),
            self::ROOM_JOINS => self::get(self::ROOM_JOINS),
            self::QUEUE_FAILURES => self::get(self::QUEUE_FAILURES),
        ];
    }
}
