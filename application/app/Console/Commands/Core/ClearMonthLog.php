<?php

namespace App\Console\Commands\Core;

use App\Services\Core\IntegrationHistoryPruner;
use App\Services\Core\ApiRequestRetention;
use App\Services\Core\ExpiredCachePruner;
use Illuminate\Console\Command;
use Throwable;

class ClearMonthLog extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clear-month-log
        {--days=14 : Keep successful history for N days}
        {--chunk=500 : Maximum rows per delete}
        {--dry-run : Count eligible rows without deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete app logs older than the specified number of days';

    /**
     * Execute the console command.
     */
    public function handle(IntegrationHistoryPruner $pruner, ApiRequestRetention $apiRequests, ExpiredCachePruner $cache): int
    {
        $days = max(1, (int)$this->option('days'));

        $errors = 0;
        $dryRun = (bool) $this->option('dry-run');

        foreach (IntegrationHistoryPruner::INTEGRATIONS as $integration) {
            $historyDays = $integration === 'import-excel' ? (int) config('retention.import_records_days') : $days;

            try {
                $rows = $dryRun
                    ? $pruner->count($integration, $historyDays)
                    : $pruner->prune($integration, $historyDays, (int) $this->option('chunk'));
                $this->line(sprintf('%s [%s]: %d successful rows older than %d days.', $dryRun ? 'Dry-run' : 'Deleted', $integration, $rows, $historyDays));
            } catch (Throwable $e) {
                $errors++;
                $this->error("Failed for [{$integration}]: {$e->getMessage()}");
            }
        }

        try {
            $apiDays = max(1, (int) config('retention.api_requests_days', 3));
            $errorDays = max($apiDays, (int) config('retention.api_errors_days', 30));
            $apiRows = $dryRun ? $apiRequests->count($apiDays, $errorDays)
                : $apiRequests->prune($apiDays, $errorDays, (int) $this->option('chunk'));
            $cacheRows = $cache->prune($dryRun, (int) $this->option('chunk'));
            $this->line(sprintf('%s API history: %d rows; expired cache: %d rows.', $dryRun ? 'Dry-run' : 'Deleted', $apiRows, $cacheRows));
        } catch (Throwable $e) {
            $errors++;
            $this->error('Platform history cleanup failed: '.$e->getMessage());
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
