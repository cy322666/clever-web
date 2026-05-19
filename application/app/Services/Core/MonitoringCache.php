<?php

namespace App\Services\Core;

use Illuminate\Support\Facades\Cache;

class MonitoringCache
{
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            return Cache::store(self::storeName())->get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    private static function storeName(): string
    {
        return (string)config('alerts.cache_store', 'monitoring');
    }

    public static function forever(string $key, mixed $value): bool
    {
        try {
            Cache::store(self::storeName())->forever($key, $value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function forget(string $key): bool
    {
        try {
            Cache::store(self::storeName())->forget($key);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function add(string $key, mixed $value, int $ttlSeconds): bool
    {
        try {
            return Cache::store(self::storeName())->add($key, $value, $ttlSeconds);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function increment(string $key, int $value = 1): int
    {
        try {
            return (int)Cache::store(self::storeName())->increment($key, $value);
        } catch (\Throwable) {
            return 0;
        }
    }
}
