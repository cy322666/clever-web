<?php

namespace App\Console\Commands\AmoData;

use App\Jobs\AmoData\RunSync;
use App\Models\Integrations\AmoData\Setting;
use App\Services\AmoData\AmoDataSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class SyncPeriodic extends Command
{
    protected $signature = 'app:amo-data-sync-periodic';

    protected $description = 'Run periodic amoCRM operational sync for active tenants';

    /**
     * @throws \Exception
     */
    public function handle(AmoDataSyncService $service): int
    {
        if (Config::get('queue.default') === 'sync') {
            $this->warn('QUEUE_CONNECTION=sync. Periodic amo-data sync dispatch skipped.');

            return self::SUCCESS;
        }

        $settings = Setting::query()
            ->where('active', true)
            ->with('user.account')
            ->get();

        foreach ($settings as $setting) {
            if (!$setting->user?->account?->active || !$service->isDue($setting)) {
                continue;
            }

            RunSync::dispatch($setting->id, 'periodic');
        }

        return self::SUCCESS;
    }
}
