<?php

namespace App\Services\YClients;

use App\Jobs\YClients\RecordSend;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

final class RecordEventQueueSnapshot
{
    private const MAX_JOBS = 5000;

    private const QUEUE = 'yclients_record';

    /** @return array{events: array<int, true>, legacy_records: array<int, true>}|null */
    public function read(): ?array
    {
        $connection = config('queue.default');
        $driver = config("queue.connections.$connection.driver");
        $payloads = match ($driver) {
            'redis' => $this->redisPayloads($connection),
            'database' => DB::connection(config("queue.connections.$connection.connection"))
                ->table(config("queue.connections.$connection.table", 'jobs'))
                ->where('queue', self::QUEUE)->limit(self::MAX_JOBS + 1)->pluck('payload')->all(),
            'sync' => [],
            default => null,
        };
        if ($payloads === null || count($payloads) > self::MAX_JOBS) {
            return null;
        }

        $snapshot = ['events' => [], 'legacy_records' => []];
        foreach ($payloads as $payload) {
            $data = json_decode($payload, true);
            $command = $data['data']['command'] ?? null;
            if (($data['displayName'] ?? null) !== RecordSend::class || !is_string($command)) {
                return null;
            }

            $job = (array) @unserialize($command, ['allowed_classes' => false, 'max_depth' => 32]);
            if (($job['__PHP_Incomplete_Class_Name'] ?? null) !== RecordSend::class) {
                return null;
            }

            $eventId = $job['eventId'] ?? null;
            if (is_int($eventId) && $eventId > 0) {
                $snapshot['events'][$eventId] = true;
                continue;
            }

            // Pre-outbox jobs can still process the current head for their record.
            $record = (array) ($job['record'] ?? []);
            $recordId = $record['id'] ?? null;
            if ($eventId !== null || !is_scalar($recordId) || !ctype_digit((string) $recordId) || (int) $recordId < 1) {
                return null;
            }
            $snapshot['legacy_records'][(int) $recordId] = true;
        }

        return $snapshot;
    }

    private function redisPayloads(string $connection): ?array
    {
        $redis = Redis::connection(config("queue.connections.$connection.connection", 'default'));
        $key = 'queues:'.self::QUEUE;

        // A worker can move a job between these keys; read all three atomically.
        $groups = $redis->eval(<<<'LUA'
            local count = redis.call('LLEN', KEYS[1]) + redis.call('ZCARD', KEYS[2]) + redis.call('ZCARD', KEYS[3])
            if count > tonumber(ARGV[1]) then return {} end
            return {
                redis.call('LRANGE', KEYS[1], 0, -1),
                redis.call('ZRANGE', KEYS[2], 0, -1),
                redis.call('ZRANGE', KEYS[3], 0, -1)
            }
            LUA, 3, $key, $key.':delayed', $key.':reserved', self::MAX_JOBS);

        return is_array($groups) && count($groups) === 3 ? array_merge(...$groups) : null;
    }
}
