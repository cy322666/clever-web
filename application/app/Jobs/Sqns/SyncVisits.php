<?php

namespace App\Jobs\Sqns;

use App\Jobs\Concerns\BuildsHorizonTags;
use App\Models\Integrations\Sqns\Setting;
use App\Services\Sqns\Client;
use App\Services\Sqns\VisitIngestor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncVisits implements ShouldQueue
{
    use BuildsHorizonTags, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 840;

    public function __construct(
        public int $settingId,
        public string $dateFrom,
        public string $dateTill,
    ) {
        $this->onConnection('redis-sqns');
        $this->onQueue('sqns_visit');
    }

    public function tags(): array
    {
        return $this->horizonTags([
            'widget:sqns',
            'queue:sqns_visit',
            'sqns_setting:'.$this->settingId,
            'operation:initial-sync',
        ]);
    }

    public function handle(VisitIngestor $ingestor): void
    {
        $setting = Setting::query()->findOrFail($this->settingId);
        $account = $setting->amoAccount(false, 'sqns');

        if (! $account) {
            throw new \RuntimeException('amoCRM account is not configured for SQNS.');
        }

        if (! $setting->isReadyToSync()) {
            throw new \RuntimeException('Настройте подключение SQNS и все этапы amoCRM перед загрузкой визитов.');
        }

        try {
            $client = new Client($setting);
            $page = 1;

            do {
                $response = $client->listVisits($page, $this->dateFrom, $this->dateTill);
                $items = data_get($response, 'data', []);

                foreach (is_array($items) ? $items : [] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $visit = $ingestor->ingest($setting, $account, $item);
                    SyncVisitToAmo::dispatch($visit->id, $visit->wasRecentlyCreated);
                }

                $lastPage = max(1, (int) data_get($response, 'meta.lastPage', 1));
                $page++;
            } while ($page <= $lastPage && $page <= 1000);

            $setting->forceFill([
                'last_synced_at' => now(),
                'last_error' => null,
            ])->save();
        } catch (Throwable $exception) {
            $setting->forceFill(['last_error' => $exception->getMessage()])->save();

            throw $exception;
        }
    }
}
