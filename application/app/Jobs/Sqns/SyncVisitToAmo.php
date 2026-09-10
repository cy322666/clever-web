<?php

namespace App\Jobs\Sqns;

use App\Jobs\Concerns\BuildsHorizonTags;
use App\Models\Integrations\Sqns\Visit;
use App\Services\Sqns\AmoSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncVisitToAmo implements ShouldQueue
{
    use BuildsHorizonTags, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [30, 120, 300];

    public function __construct(public int $visitId, public bool $createNote = true)
    {
        $this->onConnection('redis-sqns');
        $this->onQueue('sqns_visit');
    }

    public function tags(): array
    {
        return $this->horizonTags([
            'widget:sqns',
            'queue:sqns_visit',
            'sqns_visit_db:'.$this->visitId,
        ]);
    }

    public function handle(AmoSync $sync): void
    {
        $visit = Visit::query()->findOrFail($this->visitId);
        $lockKey = $visit->client_id
            ? 'sqns:sync-client:'.$visit->setting_id.':'.$visit->client_id
            : 'sqns:sync-visit:'.$this->visitId;

        Cache::store(config('cache.yclients_lock_store', 'database'))
            ->lock($lockKey, 330)
            ->block(60, function () use ($sync): void {
                $visit = Visit::query()->findOrFail($this->visitId);

                try {
                    $sync->sync($visit, $this->createNote);
                } catch (Throwable $exception) {
                    $visit->forceFill([
                        'status' => Visit::STATUS_FAILED,
                        'error_message' => $exception->getMessage(),
                    ])->save();

                    throw $exception;
                }
            });
    }
}
