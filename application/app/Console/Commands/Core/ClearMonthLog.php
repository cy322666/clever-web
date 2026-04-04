<?php

namespace App\Console\Commands\Core;

use App\Models\App;
use Illuminate\Console\Command;
use Throwable;

class ClearMonthLog extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clear-month-log {--days=14 : Delete logs older than N days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete app logs older than the specified number of days';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = max(1, (int)$this->option('days'));

        $resourceClasses = App::query()
            ->select('resource_name')
            ->distinct()
            ->pluck('resource_name');

        $resourceClasses->each(function (string $resourceClass) use ($days): void {
            if (!class_exists($resourceClass) || !method_exists($resourceClass, 'clearTransactions')) {
                $this->warn("Skipped [{$resourceClass}]: clearTransactions() method not found.");

                return;
            }

            try {
                $resourceClass::clearTransactions($days);
                $this->line("Cleaned [{$resourceClass}] logs older than {$days} days.");
            } catch (Throwable $e) {
                $this->error("Failed for [{$resourceClass}]: {$e->getMessage()}");
            }
        });

        return self::SUCCESS;
    }
}
