<?php

namespace App\Services\Workflows;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Leek\FilamentWorkflows\Models\Workflow;
use Leek\FilamentWorkflows\Triggers\TriggerRegistry;

class WorkflowDependencyMap
{
    /**
     * @return array{incoming: array<int, array<string, mixed>>, outgoing: array<int, array<string, mixed>>}
     */
    public function forWorkflow(Model $workflow): array
    {
        $workflows = $this->workflowsForTenant($workflow);

        return [
            'incoming' => $this->incoming($workflow, $workflows),
            'outgoing' => $this->outgoing($workflow, $workflows),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function incomingLabels(Model $workflow, int $limit = 3): array
    {
        return collect($this->forWorkflow($workflow)['incoming'])
            ->filter(fn(array $link): bool => (int)($link['workflow_id'] ?? 0) > 0)
            ->map(fn(array $link): string => (string)($link['workflow_name'] ?? $link['label'] ?? 'Процесс'))
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function outgoingLabels(Model $workflow, int $limit = 3): array
    {
        return collect($this->forWorkflow($workflow)['outgoing'])
            ->map(fn(array $link): string => (string)($link['workflow_name'] ?? $link['label'] ?? 'Процесс'))
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Model>
     */
    private function workflowsForTenant(Model $workflow): Collection
    {
        $workflowModel = config('filament-workflows.models.workflow', Workflow::class);
        $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');

        /** @var EloquentCollection<int, Model> $records */
        $records = $workflowModel::query()
            ->when(
                config('filament-workflows.tenancy.enabled', false),
                fn($query) => $query->where($tenantColumn, $workflow->{$tenantColumn})
            )
            ->get(['id', 'name', 'is_active', 'definition', $tenantColumn]);

        return $records->toBase();
    }

    /**
     * @param Collection<int, Model> $workflows
     * @return array<int, array<string, mixed>>
     */
    private function incoming(Model $workflow, Collection $workflows): array
    {
        $links = [];
        $workflowId = (int)$workflow->getKey();
        $trigger = (array)data_get($workflow, 'definition.trigger', []);

        if (($trigger['type'] ?? null) !== 'workflow-completed' && filled($trigger['type'] ?? null)) {
            $links[] = [
                'workflow_id' => 0,
                'workflow_name' => $this->triggerName((string)$trigger['type']),
                'is_active' => true,
                'type' => 'trigger',
                'label' => 'Триггер процесса',
            ];
        }

        foreach ($workflows as $candidate) {
            if ((int)$candidate->getKey() === $workflowId) {
                continue;
            }

            foreach ($this->runWorkflowLinks($candidate) as $link) {
                if ((int)$link['workflow_id'] !== $workflowId) {
                    continue;
                }

                $links[] = $this->link(
                    workflow: $candidate,
                    fallbackId: (int)$candidate->getKey(),
                    type: 'action',
                    label: $link['path'] !== '' ? 'Действие: ' . $link['path'] : 'Действие: Запустить процесс'
                );
            }
        }

        return $this->uniqueLinks($links);
    }

    /**
     * @param Collection<int, Model> $workflows
     * @return array<int, array<string, mixed>>
     */
    private function outgoing(Model $workflow, Collection $workflows): array
    {
        $links = [];
        $workflowId = (int)$workflow->getKey();

        foreach ($this->runWorkflowLinks($workflow) as $link) {
            $targetWorkflowId = (int)$link['workflow_id'];

            if ($targetWorkflowId <= 0 || $targetWorkflowId === $workflowId) {
                continue;
            }

            $links[] = $this->link(
                workflow: $workflows->first(fn(Model $item): bool => (int)$item->getKey() === $targetWorkflowId),
                fallbackId: $targetWorkflowId,
                type: 'action',
                label: $link['path'] !== '' ? 'Действие: ' . $link['path'] : 'Действие: Запустить процесс'
            );
        }

        return $this->uniqueLinks($links);
    }

    /**
     * @return array<int, array{workflow_id: int, path: string}>
     */
    private function runWorkflowLinks(Model $workflow): array
    {
        return $this->extractRunWorkflowLinks((array)data_get($workflow, 'definition.actions', []));
    }

    /**
     * @param array<int, array<string, mixed>> $actions
     * @return array<int, array{workflow_id: int, path: string}>
     */
    private function extractRunWorkflowLinks(array $actions, string $prefix = ''): array
    {
        $links = [];

        foreach ($actions as $index => $action) {
            $type = (string)($action['type'] ?? '');
            $config = (array)($action['config'] ?? []);
            $name = trim((string)($action['name'] ?? ''));
            $path = trim($prefix . ($name !== '' ? $name : $this->actionLabel($type, $index)));

            if ($type === 'run_workflow') {
                $workflowId = (int)($config['workflow_id'] ?? 0);

                if ($workflowId > 0) {
                    $links[] = [
                        'workflow_id' => $workflowId,
                        'path' => $path,
                    ];
                }
            }

            if (in_array(
                    $type,
                    ['condition', 'control-condition'],
                    true
                ) || ($action['componentType'] ?? null) === 'control-condition') {
                $links = array_merge(
                    $links,
                    $this->extractRunWorkflowLinks((array)($config['true_actions'] ?? []), $path . ' / Да / '),
                    $this->extractRunWorkflowLinks((array)($config['false_actions'] ?? []), $path . ' / Нет / '),
                );
            }
        }

        return $links;
    }

    private function actionLabel(string $type, int $index): string
    {
        return match ($type) {
            'run_workflow' => 'Запустить процесс',
            'condition', 'control-condition' => 'Условие',
            default => 'Шаг ' . ($index + 1),
        };
    }

    private function triggerName(string $type): string
    {
        try {
            /** @var TriggerRegistry $registry */
            $registry = app(TriggerRegistry::class);
            $triggerClass = $registry->get($type);

            if ($triggerClass) {
                return str($triggerClass::name())
                    ->replaceStart('amoCRM: ', 'amoCRM: ')
                    ->ucfirst()
                    ->toString();
            }
        } catch (\Throwable) {
            return $type;
        }

        return $type;
    }

    /**
     * @return array<string, mixed>
     */
    private function link(?Model $workflow, int $fallbackId, string $type, string $label): array
    {
        return [
            'workflow_id' => $workflow?->getKey() ?? $fallbackId,
            'workflow_name' => $workflow?->getAttribute('name') ?? ('Процесс #' . $fallbackId),
            'is_active' => (bool)($workflow?->getAttribute('is_active') ?? false),
            'type' => $type,
            'label' => $label,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $links
     * @return array<int, array<string, mixed>>
     */
    private function uniqueLinks(array $links): array
    {
        return collect($links)
            ->unique(fn(array $link): string => implode(':', [
                $link['workflow_id'] ?? '',
                $link['type'] ?? '',
                $link['label'] ?? '',
            ]))
            ->values()
            ->all();
    }
}
