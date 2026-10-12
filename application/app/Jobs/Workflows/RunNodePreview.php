<?php

namespace App\Jobs\Workflows;

use App\Services\Workflows\WorkflowNodePreview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class RunNodePreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // A preview may write to CRM. Never replay it after a worker crash or timeout.
    public int $tries = 1;
    public int $timeout = 120;
    public bool $failOnTimeout = true;

    public function __construct(public int $userId, public string $previewId)
    {
        $this->onConnection(config('filament-workflows.queue.connection') ?: config('queue.default'));
        $this->onQueue(config('filament-workflows.queue.name', 'workflows'));
    }

    public function handle(WorkflowNodePreview $previews): void
    {
        $previews->execute($this->userId, $this->previewId);
    }

    public function failed(?Throwable $exception): void
    {
        app(WorkflowNodePreview::class)->fail($this->userId, $this->previewId,
            'Обработчик пробного запуска остановлен. Автоматического повтора не было. Часть действий могла выполниться; проверьте результат перед новым запуском.');
    }
}
