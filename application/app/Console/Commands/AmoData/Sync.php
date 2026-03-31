<?php

namespace App\Console\Commands\AmoData;

use App\Models\Integrations\AmoData\Setting;
use App\Services\AmoData\AmoDataSyncService;
use Illuminate\Console\Command;

class Sync extends Command
{
    protected $signature = 'app:amo-data-sync {user_id} {--initial}';

    protected $description = 'Sync amoCRM operational data into local storage';

    /**
     * @throws \Exception
     */
    public function handle(AmoDataSyncService $service): int
    {
        $setting = Setting::query()
            ->where('user_id', $this->argument('user_id'))
            ->first();

        if (!$setting) {
            $this->error('amo-data setting not found');

            return self::FAILURE;
        }

        if (!$setting->user?->account?->active) {
            $this->error('amoCRM account is not active');

            return self::FAILURE;
        }

        $run = $this->option('initial')
            ? $service->initial($setting)
            : $service->periodic($setting);

        $this->info(
            sprintf(
                'amo-data sync finished: status=%s leads=%d tasks=%d events=%d',
                $run->status,
                $run->leads_synced,
                $run->tasks_synced,
                $run->events_created,
            )
        );

        return self::SUCCESS;
    }
}
