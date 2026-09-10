<?php

namespace App\Jobs\Vetmanager;

use App\Jobs\Concerns\BuildsHorizonTags;
use App\Models\Integrations\Vetmanager\Visit;
use App\Services\Vetmanager\VisitSynchronizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncVisit implements ShouldQueueAfterCommit
{
    use BuildsHorizonTags, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @var array<int, int>
     */
    public array $backoff = [15, 60, 180];

    public function __construct(public int $visitId)
    {
        $this->onQueue('vetmanager_visit');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('vetmanager-visit:'.$this->visitId))
                ->releaseAfter(10)
                ->expireAfter(150),
        ];
    }

    public function tags(): array
    {
        return $this->horizonTags([
            'widget:vetmanager',
            'queue:vetmanager_visit',
            $this->modelHorizonTag('vetmanager_visit', $this->visitId),
        ]);
    }

    public function handle(VisitSynchronizer $synchronizer): void
    {
        $visit = Visit::query()->find($this->visitId);

        if (! $visit) {
            return;
        }

        $visit->forceFill([
            'status' => Visit::STATUS_PROCESSING,
            'attempts' => (int) $visit->attempts + 1,
            'error_message' => null,
        ])->save();

        try {
            $synchronizer->synchronize($visit);

            $visit->refresh()->forceFill([
                'status' => Visit::STATUS_SUCCESS,
                'error_message' => null,
                'processed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $visit->refresh()->forceFill([
                'status' => Visit::STATUS_FAILED,
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
            ])->save();

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Visit::query()->whereKey($this->visitId)->update([
            'status' => Visit::STATUS_FAILED,
            'error_message' => mb_substr($exception?->getMessage() ?: 'Queue job failed.', 0, 5000),
        ]);
    }
}
