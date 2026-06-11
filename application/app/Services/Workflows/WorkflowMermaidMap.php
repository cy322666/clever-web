<?php

declare(strict_types=1);

namespace App\Services\Workflows;

use Illuminate\Database\Eloquent\Model;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Models\Workflow;

class WorkflowMermaidMap
{
    private const MAX_CHILD_DEPTH = 2;

    /** @var array<int, bool> */
    private array $renderedProcesses = [];

    /** @var array<int, bool> */
    private array $visitedProcessActions = [];

    /** @var array<int, bool> */
    private array $childProcessesLinkedFromActions = [];

    /** @var array<int, Model> */
    private array $workflowCache = [];

    /** @var array<int, string> */
    private array $lines = [];

    public function __construct(private readonly ActionRegistry $actionRegistry)
    {
    }

    public function render(Model $workflow, array $map = []): string
    {
        $this->renderedProcesses = [];
        $this->visitedProcessActions = [];
        $this->childProcessesLinkedFromActions = [];
        $this->workflowCache = [(int)$workflow->getKey() => $workflow];
        $this->lines = [
            'flowchart LR',
        ];

        $currentId = $this->processNodeId($workflow);

        $this->addProcessNode($workflow, 'currentNode', 'текущий процесс');
        $this->addIncomingNodes($workflow, $map['incoming'] ?? []);
        $this->addActionsForWorkflow($workflow, $currentId);
        $this->addOutgoingProcessesWithoutExplicitAction($workflow, $map['outgoing'] ?? []);
        $this->addClassDefinitions();

        return implode("\n", $this->lines);
    }

    /**
     * @param array<int, array<string, mixed>> $incoming
     */
    private function addIncomingNodes(Model $workflow, array $incoming): void
    {
        $currentId = $this->processNodeId($workflow);

        foreach ($incoming as $index => $link) {
            $workflowId = (int)($link['workflow_id'] ?? 0);

            if ($workflowId > 0) {
                $parent = $this->findWorkflow($workflow, $workflowId);
                $parentId = $parent ? $this->processNodeId($parent) : $this->nodeId('parent', $workflowId);

                if ($parent) {
                    $this->addProcessNode($parent, 'parentNode', $this->statusLabel($parent));
                    $this->addProcessClick($parent);
                } else {
                    $this->addNode(
                        $parentId,
                        (string)($link['workflow_name'] ?? 'Процесс #' . $workflowId),
                        'родительский процесс'
                    );
                    $this->lines[] = '    class ' . $parentId . ' parentNode';
                }

                $this->lines[] = '    ' . $parentId . ' --> ' . $currentId;

                continue;
            }

            $triggerId = $this->nodeId('trigger', $index);
            $this->addNode($triggerId, (string)($link['workflow_name'] ?? 'Триггер'), 'триггер');
            $this->lines[] = '    ' . $triggerId . ' --> ' . $currentId;
            $this->lines[] = '    class ' . $triggerId . ' triggerNode';
        }
    }

    /**
     * @param array<int, array<string, mixed>> $outgoing
     */
    private function addOutgoingProcessesWithoutExplicitAction(Model $workflow, array $outgoing): void
    {
        $currentId = $this->processNodeId($workflow);

        foreach ($outgoing as $index => $link) {
            if (($link['type'] ?? null) !== 'trigger') {
                continue;
            }

            $targetWorkflowId = (int)($link['workflow_id'] ?? 0);

            if (isset($this->childProcessesLinkedFromActions[$targetWorkflowId])) {
                continue;
            }

            $target = $this->findWorkflow($workflow, $targetWorkflowId);

            if (!$target) {
                continue;
            }

            $targetId = $this->processNodeId($target);

            $this->addProcessNode($target, 'childNode', $this->statusLabel($target));
            $this->addProcessClick($target);
            $this->lines[] = '    ' . $currentId . ' --> ' . $targetId;
            $this->addActionsForWorkflow($target, $targetId, 1);
        }
    }

    private function addActionsForWorkflow(Model $workflow, string $previousNodeId, int $depth = 0): ?string
    {
        $workflowId = (int)$workflow->getKey();

        if (isset($this->visitedProcessActions[$workflowId]) || $depth > self::MAX_CHILD_DEPTH) {
            return $previousNodeId;
        }

        $this->visitedProcessActions[$workflowId] = true;

        return $this->addActionChain(
            rootWorkflow: $workflow,
            actions: (array)data_get($workflow, 'definition.actions', []),
            previousNodeId: $previousNodeId,
            path: 'w' . $workflowId,
            depth: $depth,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $actions
     */
    private function addActionChain(
        Model $rootWorkflow,
        array $actions,
        string $previousNodeId,
        string $path,
        int $depth,
        ?string $firstEdgeLabel = null
    ): ?string {
        $lastNodeId = $previousNodeId;

        foreach ($actions as $index => $action) {
            $action = (array)$action;
            $type = (string)($action['type'] ?? '');
            $config = (array)($action['config'] ?? []);
            $actionNodeId = $this->nodeId('action', $path . '_' . $index . '_' . ($action['id'] ?? $type));
            $actionName = $this->actionName($action, $type, $config);

            if ($this->isCondition($action)) {
                $this->addDiamondNode($actionNodeId, $actionName);
            } else {
                $this->addActionNode($actionNodeId, $actionName, $this->actionSubLabel($type, $config), $type);
            }

            $edge = $firstEdgeLabel !== null && $index === 0
                ? ' -- "' . $this->label($firstEdgeLabel) . '" --> '
                : ' --> ';

            $this->lines[] = '    ' . $lastNodeId . $edge . $actionNodeId;
            $this->lines[] = '    class ' . $actionNodeId . ' ' . $this->actionClass($type);

            if ($this->isCondition($action)) {
                $this->addConditionBranches($rootWorkflow, $actionNodeId, $config, $path . '_' . $index, $depth);
            }

            if ($type === 'run_workflow') {
                $this->addChildWorkflowFromAction($rootWorkflow, $actionNodeId, $config, $depth);
            }

            $lastNodeId = $actionNodeId;
        }

        return $lastNodeId;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function addConditionBranches(
        Model $rootWorkflow,
        string $conditionNodeId,
        array $config,
        string $path,
        int $depth
    ): void {
        $trueActions = (array)($config['true_actions'] ?? []);
        $falseActions = (array)($config['false_actions'] ?? []);

        if ($trueActions !== []) {
            $this->addActionChain($rootWorkflow, $trueActions, $conditionNodeId, $path . '_true', $depth, 'Да');
        }

        if ($falseActions !== []) {
            $this->addActionChain($rootWorkflow, $falseActions, $conditionNodeId, $path . '_false', $depth, 'Нет');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function addChildWorkflowFromAction(
        Model $rootWorkflow,
        string $actionNodeId,
        array $config,
        int $depth
    ): void {
        $targetWorkflowId = (int)($config['workflow_id'] ?? 0);
        $targetWorkflow = $this->findWorkflow($rootWorkflow, $targetWorkflowId);

        if (!$targetWorkflow) {
            return;
        }

        $targetNodeId = $this->processNodeId($targetWorkflow);

        $this->addProcessNode($targetWorkflow, 'childNode', $this->statusLabel($targetWorkflow));
        $this->addProcessClick($targetWorkflow);
        $this->lines[] = '    ' . $actionNodeId . ' --> ' . $targetNodeId;
        $this->childProcessesLinkedFromActions[(int)$targetWorkflow->getKey()] = true;
        $this->addActionsForWorkflow($targetWorkflow, $targetNodeId, $depth + 1);
    }

    private function addProcessNode(Model $workflow, string $class, string $subLabel): void
    {
        $workflowId = (int)$workflow->getKey();

        if (isset($this->renderedProcesses[$workflowId])) {
            return;
        }

        $this->renderedProcesses[$workflowId] = true;

        if ($class === 'childNode') {
            $this->addSubprocessNode(
                $this->processNodeId($workflow),
                (string)$workflow->getAttribute('name'),
                $subLabel
            );
        } else {
            $this->addNode($this->processNodeId($workflow), (string)$workflow->getAttribute('name'), $subLabel);
        }

        $this->lines[] = '    class ' . $this->processNodeId($workflow) . ' ' . $class;
    }

    private function addProcessClick(Model $workflow): void
    {
        $this->lines[] = '    click ' . $this->processNodeId(
                $workflow
            ) . ' "' . \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl(
                'edit',
                ['record' => $workflow->getKey()]
            ) . '" "Открыть процесс"';
    }

    private function addNode(string $id, string $title, string $subLabel): void
    {
        $this->lines[] = '    ' . $id . '["' . $this->label($title) . '<br/><small>' . $this->label(
                $subLabel
            ) . '</small>"]';
    }

    private function addActionNode(string $id, string $title, string $subLabel, string $type): void
    {
        $label = $this->label($title) . '<br/><small>' . $this->label($subLabel) . '</small>';

        $this->lines[] = match ($this->actionShape($type)) {
            'rounded' => '    ' . $id . '(["' . $label . '"])',
            'hexagon' => '    ' . $id . '{{"' . $label . '"}}',
            'database' => '    ' . $id . '[("' . $label . '")]',
            'subprocess' => '    ' . $id . '[["' . $label . '"]]',
            default => '    ' . $id . '["' . $label . '"]',
        };
    }

    private function addSubprocessNode(string $id, string $title, string $subLabel): void
    {
        $this->lines[] = '    ' . $id . '[["' . $this->label($title) . '<br/><small>' . $this->label(
                $subLabel
            ) . '</small>"]]';
    }

    private function addDiamondNode(string $id, string $title): void
    {
        $this->lines[] = '    ' . $id . '{"' . $this->label($title) . '"}';
    }

    private function actionName(array $action, string $type, array $config): string
    {
        $customName = trim((string)($action['name'] ?? ''));

        if ($customName !== '') {
            return $customName;
        }

        $class = $this->actionRegistry->get($type);

        if ($class && method_exists($class, 'workflowName')) {
            return str($class::workflowName())
                ->replaceStart('amoCRM: ', '')
                ->toString();
        }

        return match ($type) {
            'control-condition', 'condition' => 'Условие',
            'run_workflow' => 'Запустить процесс',
            default => $type !== '' ? $type : 'Действие',
        };
    }

    private function actionSubLabel(string $type, array $config): string
    {
        if ($type === 'run_workflow' && filled($config['workflow_id'] ?? null)) {
            $workflow = $this->findWorkflow(null, (int)$config['workflow_id']);

            return $workflow ? (string)$workflow->getAttribute('name') : 'дочерний процесс';
        }

        return match ($type) {
            'control-condition', 'condition' => 'ветвление',
            default => 'действие',
        };
    }

    private function actionClass(string $type): string
    {
        return match (true) {
            $type === 'run_workflow' => 'runWorkflowNode',
            in_array($type, ['condition', 'control-condition'], true) => 'conditionNode',
            $this->isTaskOrNoteAction($type) => 'taskActionNode',
            $this->isCreateAction($type) => 'createActionNode',
            $this->isUpdateAction($type) => 'updateActionNode',
            $this->isRelationAction($type) => 'relationActionNode',
            $this->isNotificationAction($type) => 'notificationActionNode',
            str_starts_with($type, 'amocrm_') => 'amoActionNode',
            default => 'actionNode',
        };
    }

    private function actionShape(string $type): string
    {
        return match (true) {
            $type === 'run_workflow' => 'subprocess',
            $this->isTaskOrNoteAction($type) => 'rectangle',
            $this->isCreateAction($type) => 'rounded',
            $this->isUpdateAction($type) => 'hexagon',
            $this->isRelationAction($type) => 'database',
            $this->isNotificationAction($type) => 'rounded',
            default => 'rectangle',
        };
    }

    private function isCreateAction(string $type): bool
    {
        return in_array($type, [
            'amocrm_create_lead',
            'amocrm_create_contact',
            'amocrm_create_company',
            'amocrm_create_task',
        ], true);
    }

    private function isUpdateAction(string $type): bool
    {
        return in_array($type, [
            'amocrm_update_lead_fields',
            'amocrm_update_contact_fields',
            'amocrm_update_company_fields',
            'amocrm_update_task',
            'amocrm_change_lead_status',
            'amocrm_change_tags',
            'amocrm_manage_subscription',
            'amocrm_normalize_contact_data',
        ], true);
    }

    private function isTaskOrNoteAction(string $type): bool
    {
        return in_array($type, [
            'amocrm_create_task',
            'amocrm_update_task',
            'amocrm_add_note',
        ], true);
    }

    private function isRelationAction(string $type): bool
    {
        return in_array($type, [
            'amocrm_find_entity',
            'amocrm_link_entity',
            'amocrm_unlink_entity',
            'amocrm_copy_lead',
            'amocrm_add_products',
            'amocrm_remove_products',
        ], true);
    }

    private function isNotificationAction(string $type): bool
    {
        return in_array($type, [
            'send_notification',
            'send_email',
            'multi_channel_notification',
        ], true);
    }

    private function isCondition(array $action): bool
    {
        return in_array((string)($action['type'] ?? ''), ['condition', 'control-condition'], true)
            || ($action['componentType'] ?? null) === 'control-condition';
    }

    private function findWorkflow(?Model $rootWorkflow, int $workflowId): ?Model
    {
        if ($workflowId <= 0) {
            return null;
        }

        if (isset($this->workflowCache[$workflowId])) {
            return $this->workflowCache[$workflowId];
        }

        $workflowModel = config('filament-workflows.models.workflow', Workflow::class);
        $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');

        $workflow = $workflowModel::query()
            ->when(
                $rootWorkflow && config('filament-workflows.tenancy.enabled', false),
                fn($query) => $query->where($tenantColumn, $rootWorkflow->{$tenantColumn})
            )
            ->find($workflowId);

        if ($workflow) {
            $this->workflowCache[$workflowId] = $workflow;
        }

        return $workflow;
    }

    private function statusLabel(Model $workflow): string
    {
        return (bool)$workflow->getAttribute('is_active') ? 'процесс включён' : 'процесс выключен';
    }

    private function processNodeId(Model $workflow): string
    {
        return $this->nodeId('process', (int)$workflow->getKey());
    }

    private function nodeId(string $prefix, int|string $id): string
    {
        return $prefix . '_' . preg_replace('/[^A-Za-z0-9_]/', '_', (string)$id);
    }

    private function label(string $value): string
    {
        $value = trim(strip_tags($value));
        $value = str_replace(["\\", '"', "\r", "\n"], ['\\\\', '\"', ' ', ' '], $value);

        return $value !== '' ? $value : 'Без названия';
    }

    private function addClassDefinitions(): void
    {
        $this->lines[] = '    classDef currentNode fill:#eef5ff,stroke:#2563eb,stroke-width:2.5px,color:#0f172a';
        $this->lines[] = '    classDef triggerNode fill:#fff7ed,stroke:#fb923c,stroke-width:1.8px,color:#7c2d12';
        $this->lines[] = '    classDef parentNode fill:#edfdf6,stroke:#34d399,stroke-width:1.8px,color:#064e3b';
        $this->lines[] = '    classDef childNode fill:#f8fafc,stroke:#475569,stroke-width:2.4px,color:#0f172a,stroke-dasharray:5 5';
        $this->lines[] = '    classDef actionNode fill:#f8fafc,stroke:#cbd5e1,stroke-width:1.5px,color:#334155';
        $this->lines[] = '    classDef amoActionNode fill:#f0fdf4,stroke:#86efac,stroke-width:1.5px,color:#14532d';
        $this->lines[] = '    classDef createActionNode fill:#effaf3,stroke:#22c55e,stroke-width:1.8px,color:#14532d';
        $this->lines[] = '    classDef updateActionNode fill:#eff6ff,stroke:#60a5fa,stroke-width:1.8px,color:#1e3a8a';
        $this->lines[] = '    classDef taskActionNode fill:#fff7ed,stroke:#fdba74,stroke-width:1.8px,color:#7c2d12';
        $this->lines[] = '    classDef relationActionNode fill:#f5f3ff,stroke:#a78bfa,stroke-width:1.8px,color:#4c1d95';
        $this->lines[] = '    classDef notificationActionNode fill:#fdf2f8,stroke:#f9a8d4,stroke-width:1.8px,color:#831843';
        $this->lines[] = '    classDef runWorkflowNode fill:#ecfeff,stroke:#22d3ee,stroke-width:2px,color:#164e63';
        $this->lines[] = '    classDef conditionNode fill:#fffbeb,stroke:#f59e0b,stroke-width:2px,color:#78350f';
    }
}
