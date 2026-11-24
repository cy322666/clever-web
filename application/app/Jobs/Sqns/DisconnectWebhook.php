<?php

namespace App\Jobs\Sqns;

use App\Jobs\Concerns\BuildsHorizonTags;
use App\Models\Integrations\Sqns\Setting;
use App\Services\Sqns\WebhookLifecycle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DisconnectWebhook implements ShouldBeUniqueUntilProcessing, ShouldQueueAfterCommit
{
    use BuildsHorizonTags, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 280;

    public int $uniqueFor = 900;

    public function __construct(
        public int $settingId,
        public string $reason = 'inactive',
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'sqns-disconnect-webhook:'.$this->settingId;
    }

    public function tags(): array
    {
        return $this->horizonTags([
            'widget:sqns',
            'operation:disconnect-webhook',
            'sqns_setting:'.$this->settingId,
            'reason:'.$this->reason,
        ]);
    }

    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function handle(WebhookLifecycle $lifecycle): void
    {
        try {
            if (! $lifecycle->disconnectIfNeeded($this->settingId)) {
                return;
            }

            Log::info('SQNS webhook disconnected.', [
                'setting_id' => $this->settingId,
                'reason' => $this->reason,
            ]);
        } catch (Throwable $exception) {
            $setting = Setting::query()->find($this->settingId);
            $setting?->forceFill([
                'last_error' => 'Не удалось удалить webhook SQNS: '.$exception->getMessage(),
            ])->save();

            Log::error('SQNS webhook disconnection failed.', [
                'setting_id' => $this->settingId,
                'user_id' => $setting?->user_id,
                'reason' => $this->reason,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
