<?php

namespace App\Jobs\Sqns;

use App\Jobs\Concerns\BuildsHorizonTags;
use App\Models\Core\Account;
use App\Models\Integrations\Sqns\Setting;
use App\Models\Integrations\Sqns\Visit;
use App\Services\Sqns\Client;
use App\Services\Sqns\VisitIngestor;
use App\Services\Sqns\VisitPayload;
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

    public int $tries = 12;

    public int $maxExceptions = 3;

    public int $timeout = 120;

    public array $backoff = [30, 120, 300];

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

    public function middleware(): array
    {
        $visitId = VisitPayload::id($this->payload);

        if (! $visitId) {
            return [];
        }

        return [
            (new WithoutOverlapping('sqns-webhook:'.$this->settingId.':'.$visitId))
                ->releaseAfter(5)
                ->expireAfter(150),
        ];
    }

    public function handle(VisitIngestor $ingestor): void
    {
        $setting = Setting::query()->findOrFail($this->settingId);
        $account = Account::query()
            ->where('user_id', $setting->user_id)
            ->findOrFail($this->accountId);

        try {
            $webhookVisit = VisitPayload::extract($this->payload) ?? VisitPayload::partial($this->payload);
            $visitId = VisitPayload::id($this->payload);

            if (! $visitId) {
                throw new \RuntimeException('SQNS webhook does not contain visit id.');
            }

            $visit = $webhookVisit;

            if (VisitPayload::needsHydration($visit)) {
                $storedVisit = Visit::query()
                    ->where('setting_id', $setting->id)
                    ->where('visit_id', $visitId)
                    ->first();
                $storedPayload = is_array($storedVisit?->body) ? $storedVisit->body : [];

                try {
                    $remotePayload = (new Client($setting))->getVisit($visitId);
                } catch (Throwable $exception) {
                    if ($storedPayload === []) {
                        throw $exception;
                    }

                    $remotePayload = $storedPayload;
                }

                $visit = VisitPayload::merge($remotePayload, $visit);
            }

            $model = $ingestor->ingest($setting, $account, $visit);

            SyncVisitToAmo::dispatch($model->id, $model->wasRecentlyCreated);
        } catch (Throwable $exception) {
            $setting->forceFill(['last_error' => $exception->getMessage()])->save();

            throw $exception;
        }
    }
}
