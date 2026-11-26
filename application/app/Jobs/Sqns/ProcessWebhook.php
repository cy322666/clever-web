<?php

namespace App\Jobs\Sqns;

use App\Jobs\Concerns\BuildsHorizonTags;
use App\Models\Integrations\Sqns\Setting;
use App\Models\Integrations\Sqns\Visit;
use App\Services\Sqns\VisitPayload;
use App\Services\Sqns\WebhookLifecycle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessWebhook implements ShouldQueue
{
    use BuildsHorizonTags, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3; //попыток

    public int $maxExceptions = 3; //макс ошибок

    public int $timeout = 60; //лимит времени на 1

    public array $backoff = [30, 60, 90]; //секунд для повтора

    public function __construct(
        public int $settingId,
        public int $accountId,
        public array $payload,
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
            'amo_account:'.$this->accountId,
            'sqns_visit:'.(VisitPayload::id($this->payload) ?? 'unknown'),
        ]);
    }

    //защита от параллельного запуска 1 транзакции
    //снимает блокировку через 150 сек
    public function middleware(): array
    {
        $visitId = VisitPayload::id($this->payload);

        if (!$visitId) {
            return [];
        }

        return [
            (new WithoutOverlapping('sqns-webhook:'.$this->settingId.':'.$visitId))
                ->releaseAfter(5)
                ->expireAfter(150),
        ];
    }

    /**
     * @throws Throwable
     */
    public function handle(WebhookLifecycle $lifecycle): void
    {
        $setting = Setting::query()->with('user')->findOrFail($this->settingId);

        if (! $lifecycle->isOperational($setting)) {
            return;
        }

        $account = $setting->amoAccount(false, 'sqns');

        if (! $account || (int) $account->id !== $this->accountId) {
            return;
        }

        try {
            $visit = VisitPayload::extract($this->payload);

            if ($visit === null) {
                return;
            }

            $model = Visit::saveFromSqns($setting, $account, $visit);

            SyncVisitToAmo::dispatch($model->id, $model->wasRecentlyCreated);
        } catch (Throwable $exception) {
            $setting->forceFill(['last_error' => $exception->getMessage()])->save();

            throw $exception;
        }
    }
}
