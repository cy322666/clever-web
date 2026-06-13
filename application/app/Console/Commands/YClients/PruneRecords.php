<?php

namespace App\Console\Commands\YClients;

use App\Models\Integrations\YClients\Record;
use Illuminate\Console\Command;

class PruneRecords extends Command
{
    protected $signature = 'yc:prune-records {--days=5} {--chunk=1000}';

    protected $description = 'Delete old YClients records from local database by retention period in days';

    public function handle(): int
    {
        $days = max(1, (int)$this->option('days'));
        $chunk = max(1, (int)$this->option('chunk'));
        $threshold = now()->subDays($days);
        $deleted = 0;

        do {
            $ids = Record::query()
                ->where('created_at', '<', $threshold)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += Record::query()
                ->whereIn('id', $ids)
                ->delete();
        } while ($ids->count() === $chunk);

        $this->info(sprintf(
            'yclients_records prune complete. Deleted: %d. Threshold: before %s',
            $deleted,
            $threshold->toDateTimeString(),
        ));

        return self::SUCCESS;
    }
}
