<?php

namespace App\Jobs\AmoData;

use App\Models\Integrations\AmoData\Setting;
use App\Models\Integrations\AmoData\SyncRun;
use App\Services\AmoData\AmoApiService;
use App\Services\AmoData\AmoDataSyncService;
use App\Services\amoCRM\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

class SyncTaskPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $settingId,
        public int $runId,
        public int $page = 1,
        public ?int $updatedFrom = null,
    ) {
        $this->onQueue('amo_data');
    }

    public function handle(AmoDataSyncService $service): void
    {
        $setting = Setting::query()->find($this->settingId);
        $run = SyncRun::query()->find($this->runId);
        $account = $setting?->amoAccount(false, 'amo-data');

        if (!$setting || !$run || !$account?->active || $run->status !== 'running') {
            return;
        }

        $api = new AmoApiService(new Client($account));
        $limit = AmoApiService::PAGE_LIMIT;
        $items = $api->getTasksPage(
            $this->updatedFrom ? Carbon::createFromTimestamp($this->updatedFrom) : null,
            $this->page,
            $limit,
        );

        $service->processTaskPage($setting, $run, $items, $this->page, $limit);

        if (count($items) === $limit) {
            self::dispatch($this->settingId, $this->runId, $this->page + 1, $this->updatedFrom);
        }
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
            $exception?->getMessage() ?? 'Выгрузка задач завершилась с ошибкой.',
        );
    }
}
