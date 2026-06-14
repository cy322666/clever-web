<?php

namespace App\Jobs\Workflows;

use App\Jobs\Concerns\BuildsHorizonTags;
use App\Services\Workflows\WorkflowAmoCrmWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class SynchronizeAmoCrmWebhooks implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use BuildsHorizonTags, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 60;

    public function __construct(public int $userId)
    {
        $this->onConnection(config('workflow-webhooks.queue.connection'));
        $this->onQueue((string)config('workflow-webhooks.queue.name', 'default'));
    }

    public function uniqueId(): string
    {
        return "workflow-amocrm-webhooks:{$this->userId}";
    }

    public function tags(): array
    {
        return $this->horizonTags([
            'workflow',
            'widget:workflows',
            'integration:amoCRM',
            'queue:' . (string)config('workflow-webhooks.queue.name', 'workflow-webhooks'),
            $this->modelHorizonTag('user', $this->userId),
        ]);
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(WorkflowAmoCrmWebhookService $webhooks): void
    {
        $result = $webhooks->synchronizeUser($this->userId);

        if (($result['state'] ?? null) === 'error') {
            throw new RuntimeException((string)($result['message'] ?? 'Не удалось синхронизировать вебхуки amoCRM.'));
        }
    }
}
