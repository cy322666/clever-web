<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use App\Services\Workflows\WorkflowExpressionCatalog;
use App\Services\Workflows\WorkflowGraph;
use App\Services\Workflows\WorkflowStartNodes;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Illuminate\Validation\ValidationException;

trait HasWorkflowNodeNames
{
    protected function loadFromDefinition(): void
    {
        $this->definition = WorkflowExpressionCatalog::normalizeNames($this->definition);
        $this->trigger = $this->definition['trigger'] ?? null;
        $this->workflowActions = $this->definition['actions'] ?? [];
    }

    private function availableWorkflowNodeName(string $name, ?string $except = null): string
    {
        $names = WorkflowExpressionCatalog::referenceNames($this->workflowActions, array_merge($this->definition, ['trigger' => $this->trigger]));
        unset($names[$except]);
        return WorkflowExpressionCatalog::uniqueName($name, array_values($names));
    }

    public function renameWorkflowNodeAction(): Action
    {
        return Action::make('renameWorkflowNode')
            ->label('Переименовать ноду')->modalHeading('Название ноды')->modalWidth('sm')
            ->modalSubmitActionLabel('Переименовать')
            ->fillForm(fn (array $arguments): array => ['name' => WorkflowExpressionCatalog::referenceNames($this->workflowActions, $this->definition)[$arguments['id'] ?? ''] ?? ''])
            ->schema([TextInput::make('name')->label('Название')->required()->maxLength(100)->autofocus()])
            ->action(fn (array $arguments, array $data) => $this->renameWorkflowNode((string) ($arguments['id'] ?? ''), $data['name']));
    }

    public function renameWorkflowNode(string $nodeId, string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) throw ValidationException::withMessages(['name' => 'Введите название от 1 до 100 символов.']);
        $definition = array_merge($this->definition, ['trigger' => $this->trigger, 'actions' => $this->workflowActions]);
        $before = WorkflowExpressionCatalog::referenceNames($this->workflowActions, $definition);
        if ($this->availableWorkflowNodeName($name, $nodeId) !== $name) {
            throw ValidationException::withMessages(['name' => 'Нода с таким названием уже есть. Укажите другое название.']);
        }
        if ($nodeId === 'trigger' && $this->trigger) {
            $definition['trigger']['name'] = $name;
        } elseif (isset(WorkflowStartNodes::all($definition)[$nodeId])) {
            foreach ($definition['additional_triggers'] as &$start) if ($start['id'] === $nodeId) $start['name'] = $name;
            unset($start);
        } elseif ($node = WorkflowGraph::nodes($this->workflowActions)['action:'.$nodeId] ?? null) {
            data_set($definition['actions'], $node['path'].'.name', $name);
        } else {
            throw ValidationException::withMessages(['name' => 'Нода уже удалена. Обновите схему.']);
        }
        $after = WorkflowExpressionCatalog::referenceNames($definition['actions'], $definition);
        $definition['actions'] = WorkflowExpressionCatalog::remapReferences($definition['actions'], $before, $after);
        foreach ($this->mountedActions as &$mounted) {
            if (isset($mounted['data']) && in_array($mounted['name'], ['configureWorkflowAction', 'configureTrigger'], true)) {
                $mounted['data'] = WorkflowExpressionCatalog::remapReferences($mounted['data'], $before, $after);
            }
        }
        unset($mounted);
        foreach ($this->workflowInspectorDrafts as &$draft) {
            $draft['data'] = WorkflowExpressionCatalog::remapReferences($draft['data'], $before, $after);
            $draft['initial'] = WorkflowExpressionCatalog::remapReferences($draft['initial'] ?? [], $before, $after);
        }
        unset($draft);
        $definition = WorkflowExpressionCatalog::normalizeNames($definition);
        $this->trigger = $definition['trigger'];
        $this->workflowActions = $definition['actions'];
        $this->definition = $definition;
    }
}
