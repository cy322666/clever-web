<?php

namespace App\Console\Commands\AmoData;

use App\Models\Integrations\AmoData\Setting;
use App\Services\AmoData\AmoDataSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncPeriodic extends Command
{
    protected $signature = 'app:amo-data-sync-periodic';

    protected $description = 'Run periodic amoCRM operational sync for active tenants';

    /**
     * @throws \Exception
     */
    public function handle(AmoDataSyncService $service): int
    {
        $settings = Setting::query()
            ->where('active', true)
            ->with('user.account')
            ->get();

        foreach ($settings as $setting) {
            if (!$setting->user?->account?->active || !$service->isDue($setting)) {
                continue;
            }

            try {
                $service->periodic($setting);
            } catch (Throwable $e) {
                $this->error('amo-data periodic sync failed for user ' . $setting->user_id . ': ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
