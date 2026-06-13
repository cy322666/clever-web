<?php

namespace App\Services\Workflows;

use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use Throwable;

class WorkflowAmoCrmLoopGuard
{
    private const FALLBACK_TTL_SECONDS = 120;

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
            'entity_type' => $entity,
            'entity_id' => $entityId,
            'account_id' => (int)$account->id,
            'user_id' => (int)$account->user_id,
            'chain_id' => $this->chainId($context),
            'created_at' => now()->toIso8601String(),
        ];

        foreach ($events as $event) {
            $eventPayload = [
                ...$payload,
                'event' => $event,
            ];

            $this->rememberPersistentMutation($eventPayload);
            $this->safePut($this->key($account, $workflowId, $event, $entityId), $eventPayload);
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

        $persistentMutation = $this->matchingPersistentMutation($account, $eventCode, $this->eventEntity($event), $entityId);

        if ($persistentMutation !== null) {
            return $persistentMutation;
        }

        $mutation = $this->safeGet($this->key($account, (int)$workflow->id, $eventCode, $entityId));

        return is_array($mutation) ? $mutation : null;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function eventEntity(array $event): string
    {
        $entity = trim((string)($event['entity'] ?? ''));

        return $entity !== '' ? $entity : 'unknown';
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
            Cache::put($key, $payload, now()->addSeconds(self::FALLBACK_TTL_SECONDS));
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

    /**
     * @param array<string, mixed> $payload
     */
    private function rememberPersistentMutation(array $payload): void
    {
        if (!Schema::hasTable('workflow_amo_crm_mutations')) {
            return;
        }

        try {
            DB::table('workflow_amo_crm_mutations')->insert([
                'user_id' => (int)$payload['user_id'],
                'account_id' => (int)$payload['account_id'],
                'workflow_id' => (int)$payload['workflow_id'],
                'workflow_run_id' => $payload['workflow_run_id'] ?: null,
                'action_type' => $payload['action_type'] ?: null,
                'entity_type' => (string)$payload['entity_type'],
                'entity_id' => (int)$payload['entity_id'],
                'event' => (string)$payload['event'],
                'chain_id' => $payload['chain_id'] ?: null,
                'expires_at' => now()->addSeconds($this->ttlSeconds()),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Workflow amoCRM loop guard DB write failed', [
                'account_id' => $payload['account_id'] ?? null,
                'workflow_id' => $payload['workflow_id'] ?? null,
                'event' => $payload['event'] ?? null,
                'entity_id' => $payload['entity_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matchingPersistentMutation(Account $account, string $event, string $entity, int $entityId): ?array
    {
        if (!Schema::hasTable('workflow_amo_crm_mutations')) {
            return null;
        }

        try {
            $row = DB::table('workflow_amo_crm_mutations')
                ->where('account_id', (int)$account->id)
                ->where('user_id', (int)$account->user_id)
                ->where('event', $event)
                ->where('entity_type', $entity)
                ->where('entity_id', $entityId)
                ->where('expires_at', '>=', now())
                ->orderByDesc('id')
                ->first();

            return $row ? (array)$row : null;
        } catch (Throwable $e) {
            Log::warning('Workflow amoCRM loop guard DB read failed', [
                'account_id' => $account->id,
                'event' => $event,
                'entity' => $entity,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function ttlSeconds(): int
    {
        return max(120, (int)config('filament-workflows.execution.loop_guard_ttl_seconds', 900));
    }

    private function chainId(?WorkflowContext $context): ?string
    {
        $chainId = $context?->getVariable('_chain_id');

        return is_scalar($chainId) && trim((string)$chainId) !== '' ? (string)$chainId : null;
    }
}
