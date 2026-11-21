<?php

namespace App\Console\Commands\Core;

use App\Services\Core\ApiRequestRetention;
use Illuminate\Console\Command;

class ApiRequestsPrune extends Command
{
    protected $signature = 'app:api-requests-prune {--days=} {--error-days=} {--chunk=500} {--dry-run}';

    protected $description = 'Delete old api_requests records by retention period in days';

    public function handle(ApiRequestRetention $retention): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('retention.api_requests_days', 3)));
        $errorDays = max($days, (int) ($this->option('error-days') ?: config('retention.api_errors_days', 30)));
        $dryRun = (bool) $this->option('dry-run');
        $deleted = $dryRun ? $retention->count($days, $errorDays)
            : $retention->prune($days, $errorDays, (int) $this->option('chunk'));

        $this->info(
            sprintf(
                'api_requests %s: %d rows. Threshold: before %s. Unresolved exports retained.',
                $dryRun ? 'dry-run' : 'pruned',
                $deleted,
                now()->subDays($days)->toDateTimeString(),
            )
        );

        return self::SUCCESS;
    }
}
