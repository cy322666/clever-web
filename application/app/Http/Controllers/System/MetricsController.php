<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            $jobsTotal = (int)DB::table('jobs')->count();
            $failedJobsTotal = (int)DB::table('failed_jobs')->count();

            $oldestAvailableAt = DB::table('jobs')->min('available_at');
            $queueOldestAge = $oldestAvailableAt
                ? max(0, now()->timestamp - (int)$oldestAvailableAt)
                : 0;

            $lines[] = '# HELP clever_queue_jobs_total Number of queued jobs.';
            $lines[] = '# TYPE clever_queue_jobs_total gauge';
            $lines[] = 'clever_queue_jobs_total ' . $jobsTotal;

            $lines[] = '# HELP clever_queue_failed_jobs_total Number of failed jobs.';
            $lines[] = '# TYPE clever_queue_failed_jobs_total gauge';
            $lines[] = 'clever_queue_failed_jobs_total ' . $failedJobsTotal;

            $lines[] = '# HELP clever_queue_oldest_job_age_seconds Age of the oldest waiting job in seconds.';
            $lines[] = '# TYPE clever_queue_oldest_job_age_seconds gauge';
            $lines[] = 'clever_queue_oldest_job_age_seconds ' . $queueOldestAge;

            $lines[] = '# HELP clever_queue_jobs_by_queue Number of queued jobs per queue.';
            $lines[] = '# TYPE clever_queue_jobs_by_queue gauge';

            $lines[] = '# HELP clever_queue_oldest_job_age_seconds_by_queue Age of the oldest waiting job in each queue in seconds.';
            $lines[] = '# TYPE clever_queue_oldest_job_age_seconds_by_queue gauge';

            $byQueue = DB::table('jobs')
                ->select('queue', DB::raw('count(*) as count'), DB::raw('min(available_at) as oldest_available_at'))
                ->groupBy('queue')
                ->get();

            foreach ($byQueue as $row) {
                $queueName = $this->escapeLabel((string)($row->queue ?? 'default'));
                $lines[] = sprintf('clever_queue_jobs_by_queue{queue="%s"} %d', $queueName, (int)$row->count);

                $queueOldestAvailableAt = (int)($row->oldest_available_at ?? 0);
                $queueOldestAge = $queueOldestAvailableAt > 0
                    ? max(0, now()->timestamp - $queueOldestAvailableAt)
                    : 0;

                $lines[] = sprintf(
                    'clever_queue_oldest_job_age_seconds_by_queue{queue="%s"} %d',
                    $queueName,
                    $queueOldestAge
                );
            }
        } catch (Throwable $e) {
            $metricsUp = 0;
        }

        $heartbeatTs = (int)Cache::get('monitoring:scheduler:last_heartbeat', 0);
        $heartbeatAge = $heartbeatTs > 0 ? max(0, now()->timestamp - $heartbeatTs) : -1;

        $lines[] = '# HELP clever_scheduler_last_heartbeat_unixtime Last scheduler heartbeat unix timestamp.';
        $lines[] = '# TYPE clever_scheduler_last_heartbeat_unixtime gauge';
        $lines[] = 'clever_scheduler_last_heartbeat_unixtime ' . $heartbeatTs;

        $lines[] = '# HELP clever_scheduler_heartbeat_age_seconds Scheduler heartbeat age in seconds. -1 means no heartbeat yet.';
        $lines[] = '# TYPE clever_scheduler_heartbeat_age_seconds gauge';
        $lines[] = 'clever_scheduler_heartbeat_age_seconds ' . $heartbeatAge;

        $slowQueriesTotal = (int)Cache::get(self::DB_SLOW_QUERY_TOTAL_KEY, 0);
        $lastSlowQueryMs = (float)Cache::get(self::DB_SLOW_QUERY_LAST_MS_KEY, 0);
        $lastSlowQuerySeenAt = (int)Cache::get(self::DB_SLOW_QUERY_LAST_SEEN_KEY, 0);

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

    private function escapeLabel(string $value): string
    {
        return Str::of($value)
            ->replace('\\', '\\\\')
            ->replace('"', '\\"')
            ->replace("\n", '\\n')
            ->toString();
    }
}
