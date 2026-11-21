<?php

namespace App\Console\Commands\Core;

use App\Services\Core\IntegrationHistoryPruner;
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
    public function handle(IntegrationHistoryPruner $pruner): int
    {
        $days = max(1, (int)$this->option('days'));

        $errors = 0;
        $dryRun = (bool) $this->option('dry-run');

        foreach (IntegrationHistoryPruner::INTEGRATIONS as $integration) {
            try {
                $rows = $dryRun
                    ? $pruner->count($integration, $days)
                    : $pruner->prune($integration, $days, (int) $this->option('chunk'));
                $this->line(sprintf('%s [%s]: %d successful rows older than %d days.', $dryRun ? 'Dry-run' : 'Deleted', $integration, $rows, $days));
            } catch (Throwable $e) {
                $errors++;
                $this->error("Failed for [{$integration}]: {$e->getMessage()}");
            }
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
