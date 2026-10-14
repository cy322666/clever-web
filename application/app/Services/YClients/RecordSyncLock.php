<?php

namespace App\Services\YClients;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class RecordSyncLock
{
    // Longer than RecordSend's 300-second worker timeout, including both commands.
    public const SECONDS = 900;

    public static function make(int $recordId, ?string $owner = null): Lock
    {
        return Cache::store(config('cache.yclients_lock_store', 'database'))
            ->lock('yclients:send-record:'.$recordId, self::SECONDS, $owner);
    }

    public static function run(int $recordId, callable $operation, ?string $owner = null): mixed
    {
        $lock = self::make($recordId, $owner);

        if ($owner !== null) {
            if (!$lock->isOwnedByCurrentProcess()) {
                throw new LockTimeoutException('YClients record sync lock ownership was lost.');
            }

            return $operation();
        }

        // Contention is not a failed export. Queue callers release instead of waiting a minute.
        return $lock->block(1, $operation);
    }
}
