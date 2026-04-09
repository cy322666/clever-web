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
        $newFailedCount = $this->checkNewFailedJobs();
        $stuckCount = $this->checkStuckJobs();

        Cache::forever('monitoring:queue:health:last_run', now()->timestamp);
        Cache::forever('monitoring:queue:health:last_new_failed', $newFailedCount);
        Cache::forever('monitoring:queue:health:last_stuck', $stuckCount);

        return self::SUCCESS;
    }

    private function checkNewFailedJobs(): int
    {
        $failedConnection = (string)(config('queue.failed.database') ?? config('database.default'));
        $failedTable = (string)config('queue.failed.table', 'failed_jobs');

        if (!Schema::connection($failedConnection)->hasTable($failedTable)) {
            return 0;
        }

        $maxId = (int)DB::connection($failedConnection)
            ->table($failedTable)
            ->max('id');

        if ($maxId <= 0) {
            return 0;
        }

        $cacheKey = 'monitoring:queue:last_failed_job_id';
        $lastSeenId = (int)Cache::get($cacheKey, 0);

        if ($lastSeenId === 0) {
            $initialCount = (int)DB::connection($failedConnection)
                ->table($failedTable)
                ->count();

            if ($initialCount > 0) {
                AlertService::critical(
                    title: 'Очередь: обнаружены existing failed jobs',
                    message: "В таблице {$failedTable} уже есть {$initialCount} failed jobs.",
                    context: [
                        'max_id' => $maxId,
                        'connection' => $failedConnection,
                    ],
                    dedupeKey: 'queue:failed:bootstrap:max_id:' . $maxId,
                    ttlSeconds: 3600,
                );
            }

            Cache::forever($cacheKey, $maxId);

            return $initialCount;
        }

        if ($maxId <= $lastSeenId) {
            return 0;
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

        return $newFailedCount;
    }

    private function checkStuckJobs(): int
    {
        if (!Schema::hasTable('jobs')) {
            return 0;
        }

        $stuckAfter = (int)($this->option('stuck-after') ?: config('alerts.queue.stuck_after_seconds', 900));
        $stuckAfter = max(60, $stuckAfter);

        $threshold = now()->timestamp - $stuckAfter;

        $stuckQuery = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', $threshold);

        $stuckCount = (int)$stuckQuery->count();

        if ($stuckCount <= 0) {
            return 0;
        }

        $oldestReservedAt = (int)DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', $threshold)
            ->min('reserved_at');

        $oldestAge = $oldestReservedAt > 0
            ? max(0, now()->timestamp - $oldestReservedAt)
            : null;

        $releasedCount = $this->autoHealStuckJobs($stuckAfter);

        AlertService::warning(
            title: 'Очередь: зависшие jobs',
            message: "Найдено {$stuckCount} зависших jobs (reserved_at старше {$stuckAfter} сек).",
            context: [
                'stuck_count' => $stuckCount,
                'oldest_age_seconds' => $oldestAge,
                'threshold_seconds' => $stuckAfter,
                'auto_heal_released' => $releasedCount,
            ],
            dedupeKey: 'queue:stuck:' . intdiv(now()->timestamp, 300) . ':' . $stuckCount,
            ttlSeconds: 300,
        );

        return $stuckCount;
    }

    private function autoHealStuckJobs(int $stuckAfter): int
    {
        if (!(bool)config('alerts.queue.auto_heal.enabled', false)) {
            return 0;
        }

        $releaseAfter = (int)config('alerts.queue.auto_heal.release_after_seconds', 1800);
        $releaseAfter = max($stuckAfter + 60, $releaseAfter);
        $threshold = now()->timestamp - $releaseAfter;

        $maxReleases = max(1, (int)config('alerts.queue.auto_heal.max_releases_per_run', 100));
        $excludeQueues = array_values(array_filter((array)config('alerts.queue.auto_heal.exclude_queues', [])));

        $query = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', $threshold);

        if (!empty($excludeQueues)) {
            $query->whereNotIn('queue', $excludeQueues);
        }

        $stuckRows = $query
            ->orderBy('reserved_at')
            ->limit($maxReleases)
            ->get(['id', 'queue', 'reserved_at']);

        if ($stuckRows->isEmpty()) {
            return 0;
        }

        $releasedIds = $stuckRows->pluck('id')->map(static fn($id) => (int)$id)->all();

        DB::table('jobs')
            ->whereIn('id', $releasedIds)
            ->update([
                'reserved_at' => null,
                'available_at' => now()->timestamp,
            ]);

        AlertService::warning(
            title: 'Очередь: auto-heal выполнен',
            message: 'Зависшие jobs возвращены в очередь.',
            context: [
                'released_count' => count($releasedIds),
                'release_after_seconds' => $releaseAfter,
                'excluded_queues' => $excludeQueues,
            ],
            dedupeKey: 'queue:auto-heal:' . intdiv(now()->timestamp, 300) . ':' . count($releasedIds),
            ttlSeconds: 300,
        );

        return count($releasedIds);
    }
}
