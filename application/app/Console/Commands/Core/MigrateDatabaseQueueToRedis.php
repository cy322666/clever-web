<?php

namespace App\Console\Commands\Core;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MigrateDatabaseQueueToRedis extends Command
{
    protected $signature = 'app:queue-migrate-database-to-redis
        {--delete : Delete database jobs after successful Redis write}
        {--limit=1000 : Maximum jobs to migrate per run}';

    protected $description = 'Move pending jobs from the database queue table to Redis for Horizon processing';

    public function handle(): int
    {
        if (!Schema::hasTable('jobs')) {
            $this->warn('Table jobs does not exist.');

            return self::SUCCESS;
        }

        $limit = max(1, (int)$this->option('limit'));
        $delete = (bool)$this->option('delete');
        $redisConnection = (string)config('queue.connections.redis.connection', 'default');

        try {
            $redis = Redis::connection($redisConnection);
        } catch (Throwable $e) {
            $this->error('Redis connection failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $migrated = 0;
        $skipped = 0;

        DB::table('jobs')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'queue', 'payload', 'available_at'])
            ->each(function (object $job) use ($redis, $delete, &$migrated, &$skipped): void {
                $queue = trim((string)($job->queue ?: config('queue.connections.redis.queue', 'default')));
                $payload = (string)$job->payload;

                if ($queue === '' || $payload === '' || json_decode($payload, true) === null) {
                    $skipped++;

                    $this->warn("Skipped invalid database job #{$job->id}.");

                    return;
                }

                $readyKey = 'queues:' . $queue;
                $availableAt = (int)($job->available_at ?? 0);

                if ($availableAt > now()->timestamp) {
                    $redis->zadd($readyKey . ':delayed', $availableAt, $payload);
                } else {
                    $redis->rpush($readyKey, $payload);
                }

                if ($delete) {
                    DB::table('jobs')->where('id', (int)$job->id)->delete();
                }

                $migrated++;
            });

        $this->info(sprintf(
            'Migrated %d database job(s) to Redis%s. Skipped: %d.',
            $migrated,
            $delete ? ' and deleted source rows' : '',
            $skipped,
        ));

        return self::SUCCESS;
    }
}
