<?php

namespace App\Console\Commands\Core;

use App\Services\Core\AlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueueHealthCheck extends Command
{
    protected $signature = 'app:monitor-queue-health {--stuck-after=} {--sample=5}';

    protected $description = 'Detect new failed/stuck queue jobs and send alerts';

    public function handle(): int
    {
        $this->checkNewFailedJobs();
        $this->checkStuckJobs();

        return self::SUCCESS;
    }

    private function checkNewFailedJobs(): void
    {
        $failedConnection = (string)(config('queue.failed.database') ?? config('database.default'));
        $failedTable = (string)config('queue.failed.table', 'failed_jobs');

        if (!Schema::connection($failedConnection)->hasTable($failedTable)) {
            return;
        }

        $maxId = (int)DB::connection($failedConnection)
            ->table($failedTable)
            ->max('id');

        if ($maxId <= 0) {
            return;
        }

        $cacheKey = 'monitoring:queue:last_failed_job_id';
        $lastSeenId = (int)Cache::get($cacheKey, 0);

        if ($lastSeenId === 0) {
            Cache::forever($cacheKey, $maxId);

            return;
        }

        if ($maxId <= $lastSeenId) {
            return;
        }

        $newFailedQuery = DB::connection($failedConnection)
            ->table($failedTable)
            ->where('id', '>', $lastSeenId);

        $newFailedCount = (int)$newFailedQuery->count();
        $sampleLimit = max(1, (int)$this->option('sample'));

        $sample = DB::connection($failedConnection)
            ->table($failedTable)
            ->where('id', '>', $lastSeenId)
            ->orderByDesc('id')
            ->limit($sampleLimit)
            ->get(['id', 'uuid', 'queue', 'failed_at', 'exception']);

        $last = $sample->first();

        AlertService::critical(
            title: 'Очередь: новые failed jobs',
            message: "Обнаружено {$newFailedCount} новых failed jobs.",
            context: [
                'from_id' => $lastSeenId + 1,
                'to_id' => $maxId,
                'last_queue' => (string)($last->queue ?? '-'),
                'last_failed_at' => (string)($last->failed_at ?? '-'),
            ],
            dedupeKey: 'queue:failed:max_id:' . $maxId,
            ttlSeconds: 3600,
        );

        Cache::forever($cacheKey, $maxId);
    }

    private function checkStuckJobs(): void
    {
        if (!Schema::hasTable('jobs')) {
            return;
        }

        $stuckAfter = (int)($this->option('stuck-after') ?: config('alerts.queue.stuck_after_seconds', 900));
        $stuckAfter = max(60, $stuckAfter);

        $threshold = now()->timestamp - $stuckAfter;

        $stuckQuery = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', $threshold);

        $stuckCount = (int)$stuckQuery->count();

        if ($stuckCount <= 0) {
            return;
        }

        $oldestReservedAt = (int)DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', $threshold)
            ->min('reserved_at');

        $oldestAge = $oldestReservedAt > 0
            ? max(0, now()->timestamp - $oldestReservedAt)
            : null;

        AlertService::warning(
            title: 'Очередь: зависшие jobs',
            message: "Найдено {$stuckCount} зависших jobs (reserved_at старше {$stuckAfter} сек).",
            context: [
                'stuck_count' => $stuckCount,
                'oldest_age_seconds' => $oldestAge,
                'threshold_seconds' => $stuckAfter,
            ],
            dedupeKey: 'queue:stuck:' . intdiv(now()->timestamp, 300) . ':' . $stuckCount,
            ttlSeconds: 300,
        );
    }
}
