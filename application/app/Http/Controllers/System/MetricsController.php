<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Core\MonitoringCache;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

class MetricsController extends Controller
{
    private const DB_SLOW_QUERY_TOTAL_KEY = 'monitoring:db:slow_queries:total';
    private const DB_SLOW_QUERY_LAST_MS_KEY = 'monitoring:db:slow_queries:last_ms';
    private const DB_SLOW_QUERY_LAST_SEEN_KEY = 'monitoring:db:slow_queries:last_seen_unixtime';

    public function __invoke(Request $request): Response
    {
        if (!$this->isAuthorized($request)) {
            abort(403);
        }

        $lines = [];

        $lines[] = '# HELP clever_app_info Static app metadata.';
        $lines[] = '# TYPE clever_app_info gauge';
        $lines[] = sprintf(
            'clever_app_info{app="%s",env="%s"} 1',
            $this->escapeLabel((string)config('app.name', 'clever-web')),
            $this->escapeLabel((string)config('app.env', 'unknown')),
        );

        $metricsUp = 1;

        try {
            $queueStats = $this->queueStats();
            $jobsTotal = array_sum(array_column($queueStats, 'count'));
            $failedJobsTotal = (int)DB::table('failed_jobs')->count();
            $recentFailedJobsTotal = (int)DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subMinutes(10))
                ->count();
            $queueOldestAge = $queueStats === [] ? 0 : max(array_column($queueStats, 'oldest_age'));

            $lines[] = '# HELP clever_queue_jobs_total Number of queued jobs.';
            $lines[] = '# TYPE clever_queue_jobs_total gauge';
            $lines[] = 'clever_queue_jobs_total ' . $jobsTotal;

            $lines[] = '# HELP clever_queue_failed_jobs_total Number of failed jobs.';
            $lines[] = '# TYPE clever_queue_failed_jobs_total gauge';
            $lines[] = 'clever_queue_failed_jobs_total ' . $failedJobsTotal;

            $lines[] = '# HELP clever_queue_failed_jobs_recent_total Number of failed jobs in the last 10 minutes.';
            $lines[] = '# TYPE clever_queue_failed_jobs_recent_total gauge';
            $lines[] = 'clever_queue_failed_jobs_recent_total ' . $recentFailedJobsTotal;

            $lines[] = '# HELP clever_queue_oldest_job_age_seconds Age of the oldest waiting job in seconds.';
            $lines[] = '# TYPE clever_queue_oldest_job_age_seconds gauge';
            $lines[] = 'clever_queue_oldest_job_age_seconds ' . $queueOldestAge;

            $lines[] = '# HELP clever_queue_jobs_by_queue Number of queued jobs per queue.';
            $lines[] = '# TYPE clever_queue_jobs_by_queue gauge';

            $lines[] = '# HELP clever_queue_oldest_job_age_seconds_by_queue Age of the oldest waiting job in each queue in seconds.';
            $lines[] = '# TYPE clever_queue_oldest_job_age_seconds_by_queue gauge';

            foreach ($queueStats as $queue => $stats) {
                $queueName = $this->escapeLabel((string)$queue);
                $lines[] = sprintf('clever_queue_jobs_by_queue{queue="%s"} %d', $queueName, (int)$stats['count']);
                $lines[] = sprintf(
                    'clever_queue_oldest_job_age_seconds_by_queue{queue="%s"} %d',
                    $queueName,
                    (int)$stats['oldest_age']
                );
            }
        } catch (Throwable $e) {
            $metricsUp = 0;
        }

        $heartbeatTs = (int)MonitoringCache::get('monitoring:scheduler:last_heartbeat', 0);
        $heartbeatAge = $heartbeatTs > 0 ? max(0, now()->timestamp - $heartbeatTs) : -1;

        $lines[] = '# HELP clever_scheduler_last_heartbeat_unixtime Last scheduler heartbeat unix timestamp.';
        $lines[] = '# TYPE clever_scheduler_last_heartbeat_unixtime gauge';
        $lines[] = 'clever_scheduler_last_heartbeat_unixtime ' . $heartbeatTs;

        $lines[] = '# HELP clever_scheduler_heartbeat_age_seconds Scheduler heartbeat age in seconds. -1 means no heartbeat yet.';
        $lines[] = '# TYPE clever_scheduler_heartbeat_age_seconds gauge';
        $lines[] = 'clever_scheduler_heartbeat_age_seconds ' . $heartbeatAge;

        $slowQueriesTotal = (int)MonitoringCache::get(self::DB_SLOW_QUERY_TOTAL_KEY, 0);
        $lastSlowQueryMs = (float)MonitoringCache::get(self::DB_SLOW_QUERY_LAST_MS_KEY, 0);
        $lastSlowQuerySeenAt = (int)MonitoringCache::get(self::DB_SLOW_QUERY_LAST_SEEN_KEY, 0);

        $lines[] = '# HELP clever_db_slow_queries_total Total number of DB queries above configured slow threshold.';
        $lines[] = '# TYPE clever_db_slow_queries_total counter';
        $lines[] = 'clever_db_slow_queries_total ' . $slowQueriesTotal;

        $lines[] = '# HELP clever_db_last_slow_query_ms Duration of the latest detected slow DB query in milliseconds.';
        $lines[] = '# TYPE clever_db_last_slow_query_ms gauge';
        $lines[] = 'clever_db_last_slow_query_ms ' . $lastSlowQueryMs;

        $lines[] = '# HELP clever_db_last_slow_query_unixtime Unix timestamp of the latest detected slow DB query.';
        $lines[] = '# TYPE clever_db_last_slow_query_unixtime gauge';
        $lines[] = 'clever_db_last_slow_query_unixtime ' . $lastSlowQuerySeenAt;

        $lines[] = '# HELP clever_metrics_up 1 when app metrics collection works, 0 on collection errors.';
        $lines[] = '# TYPE clever_metrics_up gauge';
        $lines[] = 'clever_metrics_up ' . $metricsUp;

        $body = implode("\n", $lines) . "\n";

        return response($body, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }

    private function isAuthorized(Request $request): bool
    {
        $ip = (string)$request->ip();
        if ($this->isPrivateOrLoopbackIp($ip)) {
            return true;
        }

        $token = trim((string)env('METRICS_TOKEN', (string)getenv('METRICS_TOKEN')));

        if ($token === '') {
            return app()->environment('local');
        }

        $bearer = trim((string)$request->bearerToken());
        $queryToken = trim((string)$request->query('token', ''));

        return hash_equals($token, $bearer) || hash_equals($token, $queryToken);
    }

    private function isPrivateOrLoopbackIp(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return true;
        }

        return filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
    }

    /**
     * @return array<string, array{count: int, oldest_age: int}>
     */
    private function queueStats(): array
    {
        $stats = [];

        foreach ($this->databaseQueueStats() as $queue => $queueStats) {
            $this->mergeQueueStats($stats, $queue, $queueStats['count'], $queueStats['oldest_age']);
        }

        foreach ($this->redisQueueStats() as $queue => $queueStats) {
            $this->mergeQueueStats($stats, $queue, $queueStats['count'], $queueStats['oldest_age']);
        }

        ksort($stats);

        return $stats;
    }

    /**
     * @return array<string, array{count: int, oldest_age: int}>
     */
    private function databaseQueueStats(): array
    {
        if (!DB::getSchemaBuilder()->hasTable('jobs')) {
            return [];
        }

        $stats = [];
        $rows = DB::table('jobs')
            ->select('queue', DB::raw('count(*) as count'), DB::raw('min(available_at) as oldest_available_at'))
            ->groupBy('queue')
            ->get();

        foreach ($rows as $row) {
            $queue = (string)($row->queue ?? 'default');
            $oldestAvailableAt = (int)($row->oldest_available_at ?? 0);

            $stats[$queue] = [
                'count' => (int)$row->count,
                'oldest_age' => $oldestAvailableAt > 0 ? max(0, now()->timestamp - $oldestAvailableAt) : 0,
            ];
        }

        return $stats;
    }

    /**
     * @return array<string, array{count: int, oldest_age: int}>
     */
    private function redisQueueStats(): array
    {
        if ((string)config('queue.default') !== 'redis') {
            return [];
        }

        try {
            $redis = Redis::connection((string)config('queue.connections.redis.connection', 'default'));
        } catch (Throwable) {
            return [];
        }

        $stats = [];

        foreach ($this->monitoredRedisQueues() as $queue) {
            try {
                $readyKey = 'queues:' . $queue;
                $delayedKey = $readyKey . ':delayed';
                $reservedKey = $readyKey . ':reserved';

                $readyCount = (int)$redis->llen($readyKey);
                $delayedCount = (int)$redis->zcard($delayedKey);
                $reservedCount = (int)$redis->zcard($reservedKey);
                $count = $readyCount + $delayedCount + $reservedCount;

                if ($count <= 0) {
                    continue;
                }

                $stats[$queue] = [
                    'count' => $count,
                    'oldest_age' => max(
                        $this->redisReadyQueueOldestAge($redis, $readyKey),
                        $this->redisSortedSetOverdueAge($redis, $delayedKey),
                    ),
                ];
            } catch (Throwable) {
                continue;
            }
        }

        return $stats;
    }

    /**
     * @return array<int, string>
     */
    private function monitoredRedisQueues(): array
    {
        $queues = [
            (string)config('queue.connections.redis.queue', 'default'),
            (string)config('filament-workflows.queue.name', 'workflows'),
            (string)config('workflow-webhooks.queue.name', 'workflows'),
        ];

        foreach ((array)config('horizon.defaults', []) as $supervisor) {
            foreach ((array)($supervisor['queue'] ?? []) as $queue) {
                $queues[] = (string)$queue;
            }
        }

        return array_values(array_unique(array_filter($queues)));
    }

    private function redisReadyQueueOldestAge(mixed $redis, string $key): int
    {
        $payload = $redis->lindex($key, 0);

        if (!is_string($payload) || $payload === '') {
            return 0;
        }

        $decoded = json_decode($payload, true);
        $pushedAt = is_array($decoded) ? (float)($decoded['pushedAt'] ?? 0) : 0.0;

        return $pushedAt > 0 ? max(0, now()->timestamp - (int)$pushedAt) : 0;
    }

    private function redisSortedSetOverdueAge(mixed $redis, string $key): int
    {
        $items = $redis->zrange($key, 0, 0, ['withscores' => true]);

        if (!is_array($items) || $items === []) {
            return 0;
        }

        $score = (int)reset($items);

        return $score > 0 && $score < now()->timestamp
            ? now()->timestamp - $score
            : 0;
    }

    /**
     * @param array<string, array{count: int, oldest_age: int}> $stats
     */
    private function mergeQueueStats(array &$stats, string $queue, int $count, int $oldestAge): void
    {
        if ($count <= 0) {
            return;
        }

        $stats[$queue] ??= ['count' => 0, 'oldest_age' => 0];
        $stats[$queue]['count'] += $count;
        $stats[$queue]['oldest_age'] = max($stats[$queue]['oldest_age'], $oldestAge);
    }

    private function escapeLabel(string $value): string
    {
        return Str::of($value)
            ->replace('\\', '\\\\')
            ->replace('"', '\\"')
            ->replace("\n", '\\n')
            ->toString();
    }
}
