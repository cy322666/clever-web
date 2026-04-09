<?php

namespace App\Jobs\AmoData;

use App\Models\Integrations\AmoData\Setting;
use App\Models\Integrations\AmoData\SyncRun;
use App\Services\AmoData\AmoDataSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncReferences implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $settingId,
        public int $runId,
    ) {
        $this->onQueue('amo_data');
    }

    /**
     * @throws \Exception
     */
    public function handle(AmoDataSyncService $service): void
    {
        $setting = Setting::query()->find($this->settingId);
        $run = SyncRun::query()->find($this->runId);
        $account = $setting?->amoAccount(false, 'amo-data');

        if (!$setting || !$run || !$account?->active || $run->status !== 'running') {
            return;
        }

        $service->processReferences($setting, $run);
    }

    public function failed(?Throwable $exception): void
    {
        $setting = Setting::query()->find($this->settingId);
        $run = SyncRun::query()->find($this->runId);

        if (!$setting || !$run) {
            return;
        }

        app(AmoDataSyncService::class)->failRun(
            $setting,
            $run,
            $exception?->getMessage() ?? 'Синхронизация справочников завершилась с ошибкой.',
        );
    }
}
