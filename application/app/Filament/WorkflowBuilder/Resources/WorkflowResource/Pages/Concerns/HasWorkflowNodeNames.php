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
        $this->trigger = $definition['trigger'];
        $this->workflowActions = $definition['actions'];
        $this->definition = $definition;
    }
}
