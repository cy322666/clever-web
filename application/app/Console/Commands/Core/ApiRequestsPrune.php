<?php

namespace App\Console\Commands\Core;

use App\Models\Core\ApiRequest;
use Illuminate\Console\Command;

class ApiRequestsPrune extends Command
{
    protected $signature = 'app:api-requests-prune {--days=30}';

    protected $description = 'Delete old api_requests records by retention period in days';

    public function handle(): int
    {
        $days = max(1, (int)$this->option('days'));
        $threshold = now()->subDays($days);

        $deleted = ApiRequest::query()
            ->where('created_at', '<', $threshold)
            ->delete();

        $this->info(
            sprintf(
                'api_requests prune complete. Deleted: %d. Threshold: before %s',
                $deleted,
                $threshold->toDateTimeString(),
            )
        );

        return self::SUCCESS;
    }
}
