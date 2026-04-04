<?php

namespace App\Jobs\AmoData;

use App\Models\Integrations\AmoData\Setting;
use App\Services\AmoData\AmoDataSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
        $setting = Setting::query()->with('user.account')->find($this->settingId);

        if (!$setting?->user?->account?->subdomain) {
            return ['amo-data'];
        }

        return [
            'amo-data',
            'client:' . $setting->user->account->subdomain,
            'type:' . $this->type,
        ];
    }

    /**
     * @throws \Exception
     */
    public function handle(AmoDataSyncService $service): void
    {
        $setting = Setting::query()
            ->with('user.account')
            ->find($this->settingId);

        if (!$setting || !$setting->user?->account?->active) {
            return;
        }

        if ($this->type === 'initial') {
            $service->initial($setting);

            return;
        }

        $service->periodic($setting);
    }
}
