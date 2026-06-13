<?php

namespace App\Services\Workflows;

use App\Models\Workflows\WorkflowRun;
use App\Models\Workflows\WorkflowRunEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Throwable;

class WorkflowRunEntityIndexService
{
    /**
     * @param WorkflowRun|Model $run
     */
    public function indexRun(Model $run): void
    {
        $triggerData = (array)data_get($run->context_data, 'trigger_data', []);

        if ($triggerData === []) {
            return;
        }

        $entities = $this->entitiesFromTriggerData($triggerData);
        $this->store($run, null, $entities, 'trigger');
    }

    /**
     * @param Model $step Leek\FilamentWorkflows\Models\WorkflowRunStep compatible model.
     */
    public function indexStep(Model $step): void
    {
        $run = $step->relationLoaded('workflowRun')
            ? $step->getRelation('workflowRun')
            : (method_exists($step, 'workflowRun') ? $step->workflowRun()->first() : null);

        if (!$run instanceof Model) {
            return;
        }

        $output = (array)($step->getAttribute('output_data') ?? []);
        $input = (array)($step->getAttribute('input_data') ?? []);
        $triggerData = (array)data_get($run->getAttribute('context_data'), 'trigger_data', []);
        $entities = [
            ...$this->entitiesFromStepOutput($output),
            ...$this->entitiesFromStepInput($input, $triggerData),
        ];

        $this->store($run, $step, $entities, 'step');
    }

    /**
     * @param array<string, mixed> $triggerData
     * @return array<int, array{entity_type: string, entity_id: int, url?: string|null}>
     */
    private function entitiesFromTriggerData(array $triggerData): array
    {
        $entities = [];
        $entity = (string)($triggerData['entity'] ?? '');
        $action = (string)($triggerData['action'] ?? '');

        $this->append($entities, $entity, data_get($triggerData, $entity . '.id'));
        $this->append($entities, $entity, data_get($triggerData, 'item.id'));

        if ($action === 'note') {
            $this->append($entities, $entity, data_get($triggerData, 'item.element_id'));
            $this->append($entities, $entity, data_get($triggerData, 'note.element_id'));
        }

        if ($entity === 'task') {
            $this->append($entities, data_get($triggerData, 'item.element_type'), data_get($triggerData, 'item.element_id'));
        }

        return array_values($entities);
    }

    /**
     * @param array<string, mixed> $output
     * @return array<int, array{entity_type: string, entity_id: int, url?: string|null}>
     */
    private function entitiesFromStepOutput(array $output): array
    {
        $entities = [];

        $this->append($entities, $output['entity_type'] ?? $output['entity'] ?? null, $output['entity_id'] ?? $output['id'] ?? null, $output['url'] ?? null);
        $this->append($entities, $output['parent_entity'] ?? null, $output['parent_id'] ?? null);
        $this->append($entities, $output['linked_entity'] ?? null, $output['linked_entity_id'] ?? null);

        foreach (['affected_entities', 'created_entities', 'linked_entities', 'entities'] as $key) {
            foreach ((array)($output[$key] ?? []) as $entity) {
                if (!is_array($entity)) {
                    continue;
                }

                $this->append(
                    $entities,
                    $entity['entity_type'] ?? $entity['type'] ?? null,
                    $entity['entity_id'] ?? $entity['id'] ?? null,
                    $entity['url'] ?? null,
                );
            }
        }

        return array_values($entities);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $triggerData
     * @return array<int, array{entity_type: string, entity_id: int, url?: string|null}>
     */
    private function entitiesFromStepInput(array $input, array $triggerData): array
    {
        $entities = [];
        $targetEntity = (string)($input['target_entity'] ?? $input['entity'] ?? $triggerData['entity'] ?? '');
        $targetId = $this->numericId($input['target_entity_id'] ?? $input['entity_id'] ?? null)
            ?: $this->triggerEntityId($triggerData, $targetEntity);

        $this->append($entities, $targetEntity, $targetId);
        $this->append($entities, $input['linked_entity'] ?? null, $input['linked_entity_id'] ?? null);

        return array_values($entities);
    }

    /**
     * @param array<string, mixed> $triggerData
     */
    private function triggerEntityId(array $triggerData, string $entity): ?int
    {
        if ($entity === '') {
            return null;
        }

        $action = (string)($triggerData['action'] ?? '');

        return $this->numericId(data_get($triggerData, $entity . '.id'))
            ?: $this->numericId(data_get($triggerData, $entity . '.' . $action . '.element_id'))
            ?: $this->numericId(data_get($triggerData, 'item.id'))
            ?: $this->numericId(data_get($triggerData, 'item.' . $action . '.element_id'))
            ?: $this->firstElementId((array)($triggerData[$entity] ?? []));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function firstElementId(array $data): ?int
    {
        foreach (Arr::dot($data) as $key => $value) {
            if (str_ends_with((string)$key, 'element_id') && ($id = $this->numericId($value)) !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param array<string, array{entity_type: string, entity_id: int, url?: string|null}> $entities
     */
    private function append(array &$entities, mixed $type, mixed $id, mixed $url = null): void
    {
        $type = $this->normalizeType((string)$type);
        $id = $this->numericId($id);

        if ($type === null || $id === null) {
            return;
        }

        $entities[$type . ':' . $id] = [
            'entity_type' => $type,
            'entity_id' => $id,
            'url' => is_string($url) && str_starts_with($url, 'http') ? $url : null,
        ];
    }

    /**
     * @param Model $run WorkflowRun compatible model.
     * @param Model|null $step WorkflowRunStep compatible model.
     * @param array<int, array{entity_type: string, entity_id: int, url?: string|null}> $entities
     */
    private function store(Model $run, ?Model $step, array $entities, string $source): void
    {
        if ($entities === [] || !$this->entityIndexTableReady()) {
            return;
        }

        $userId = (int)($run->getAttribute(config('filament-workflows.tenancy.column', 'user_id')) ?? 0);
        $workflowId = (int)$run->getAttribute('workflow_id');
        $runId = (int)$run->getKey();
        $stepId = $step ? (int)$step->getKey() : null;

        if ($userId <= 0 || $workflowId <= 0 || $runId <= 0) {
            return;
        }

        foreach ($entities as $entity) {
            WorkflowRunEntity::query()->updateOrCreate([
                'workflow_run_id' => $runId,
                'workflow_run_step_id' => $stepId,
                'entity_type' => $entity['entity_type'],
                'entity_id' => $entity['entity_id'],
                'source' => $source,
            ], [
                'user_id' => $userId,
                'workflow_id' => $workflowId,
                'url' => $entity['url'] ?? null,
            ]);
        }
    }

    private function normalizeType(string $type): ?string
    {
        $type = trim($type);

        return [
            'lead' => 'lead',
            'leads' => 'lead',
            'contact' => 'contact',
            'contacts' => 'contact',
            'company' => 'company',
            'companies' => 'company',
            'customer' => 'customer',
            'customers' => 'customer',
            'task' => 'task',
            'tasks' => 'task',
            'note' => 'note',
            'notes' => 'note',
        ][$type] ?? null;
    }

    private function numericId(mixed $value): ?int
    {
        return is_numeric($value) && (int)$value > 0 ? (int)$value : null;
    }

    private function entityIndexTableReady(): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        try {
            return $ready = Schema::hasTable('workflow_run_entities');
        } catch (Throwable) {
            return $ready = false;
        }
    }
}
