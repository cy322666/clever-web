<?php

namespace App\Console\Commands\Core;

use App\Services\Core\MonitoringCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueueHealthCheck extends Command
{
    protected $signature = 'app:monitor-queue-health
        {--sample=5 : Deprecated; retained for command compatibility}';

    protected $description = 'Record counts of new failed jobs';

    public function handle(): int
    {
        $newFailedCount = $this->checkNewFailedJobs();

        MonitoringCache::forever('monitoring:queue:health:last_run', now()->timestamp);
        MonitoringCache::forever('monitoring:queue:health:last_new_failed', $newFailedCount);

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
        $lastSeenId = (int)MonitoringCache::get($cacheKey, 0);

        if ($maxId <= $lastSeenId) {
            return 0;
        }

        // SendFailedJobAlert reports each failure; polling only maintains counters.
        $newFailedCount = (int)DB::connection($failedConnection)
            ->table($failedTable)
            ->where('id', '>', $lastSeenId)
            ->where('id', '<=', $maxId)
            ->count();

        MonitoringCache::forever($cacheKey, $maxId);

        return $newFailedCount;
    }
}
