<?php

namespace App\Services\Workflows;

use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use Throwable;

class WorkflowAmoCrmLoopGuard
{
    private const TTL_SECONDS = 120;

    /**
     * @param array<int, string> $events
     */
    public function rememberMutation(
        Account $account,
        ?WorkflowContext $context,
        string $actionType,
        string $entity,
        int $entityId,
        array $events
    ): void {
        $workflowId = (int)($context?->getWorkflowId() ?? 0);

        if ($workflowId <= 0 || $entityId <= 0) {
            return;
        }

        $events = array_values(array_unique(array_filter(array_map(
            static fn(mixed $event): string => trim((string)$event),
            $events,
        ))));

        if ($events === []) {
            return;
        }

        $payload = [
            'workflow_id' => $workflowId,
            'workflow_run_id' => (int)($context?->getWorkflowRunId() ?? 0) ?: null,
            'action_type' => $actionType,
            'entity' => $entity,
            'entity_id' => $entityId,
            'account_id' => (int)$account->id,
            'user_id' => (int)$account->user_id,
            'created_at' => now()->toIso8601String(),
        ];

        foreach ($events as $event) {
            $this->safePut($this->key($account, $workflowId, $event, $entityId), [
                ...$payload,
                'event' => $event,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    public function matchingRecentMutation(Workflow $workflow, Account $account, array $event): ?array
    {
        $eventCode = trim((string)($event['event'] ?? ''));
        $entityId = $this->eventEntityId($event);

        if ($eventCode === '' || $entityId <= 0) {
            return null;
        }

        $mutation = $this->safeGet($this->key($account, (int)$workflow->id, $eventCode, $entityId));

        return is_array($mutation) ? $mutation : null;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function eventEntityId(array $event): int
    {
        $item = is_array($event['item'] ?? null) ? $event['item'] : [];

        foreach (['id', 'entity_id', 'element_id', 'lead_id', 'contact_id', 'company_id', 'customer_id'] as $key) {
            $id = (int)($item[$key] ?? 0);

            if ($id > 0) {
                return $id;
            }
        }

        foreach (['lead.id', 'contact.id', 'company.id', 'customer.id'] as $path) {
            $id = (int)Arr::get($item, $path, 0);

            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    private function key(Account $account, int $workflowId, string $event, int $entityId): string
    {
        return implode(':', [
            'workflow',
            'amocrm-loop',
            (int)$account->id,
            (int)$account->user_id,
            $workflowId,
            $event,
            $entityId,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function safePut(string $key, array $payload): void
    {
        try {
            Cache::put($key, $payload, now()->addSeconds(self::TTL_SECONDS));
        } catch (Throwable $e) {
            Log::warning('Workflow amoCRM loop guard cache write failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function safeGet(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (Throwable $e) {
            Log::warning('Workflow amoCRM loop guard cache read failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
