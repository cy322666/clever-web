<?php

namespace App\Jobs\Integrations;

use App\Models\Integrations\Finder\Setting;
use App\Services\Finder\WebhookConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SynchronizeFinderWebhooks implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 90;

    public int $uniqueFor = 900;

    public function __construct(public int $settingId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'finder-webhooks:'.$this->settingId;
    }

    public function backoff(): array
    {
        return [15, 60, 300];
    }

    public function handle(WebhookConnection $connection): void
    {
        $setting = Setting::find($this->settingId);
        if ($setting && $setting->amoAccount()?->active) {
            $connection->connect($setting);
        }
    }
}
