<?php

namespace App\Jobs\AmoData;

use App\Models\Integrations\AmoData\Setting;
use App\Services\AmoData\AmoDataSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $settingId,
        public string $type = 'periodic',
    ) {
        $this->onQueue('amo_data');
    }

    public function tags(): array
    {
        $setting = Setting::query()->find($this->settingId);
        $account = $setting?->amoAccount(false, 'amo-data');

        if (!$account?->subdomain) {
            return ['amo-data'];
        }

        return [
            'amo-data',
            'client:' . $account->subdomain,
            'type:' . $this->type,
        ];
    }

    /**
     * @throws \Exception
     */
    public function handle(AmoDataSyncService $service): void
    {
        $setting = Setting::query()
            ->find($this->settingId);
        $account = $setting?->amoAccount(false, 'amo-data');

        if (!$setting || !$account?->active) {
            return;
        }

        if ($this->type === 'periodic' && !$setting->active) {
            return;
        }

        if ($this->type === 'initial') {
            $service->initial($setting);

            return;
        }

        $service->periodic($setting);
    }

    public function failed(?Throwable $exception): void
    {
        $setting = Setting::query()->find($this->settingId);

        if (!$setting) {
            return;
        }

        $message = $exception?->getMessage() ?? 'Выгрузка завершилась с ошибкой.';

        $setting->forceFill([
            'sync_status' => 'failed',
            'last_error' => $message,
        ])->save();

        $setting->runs()
            ->where('status', 'running')
            ->latest('id')
            ->first()
            ?->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => $message,
            ])->save();
    }
}
