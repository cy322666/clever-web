<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use App\Services\Workflows\WorkflowGraph;
use Filament\Notifications\Notification;
use InvalidArgumentException;

trait HasWorkflowConnections
{
    public ?array $insertConnection = null;

    public function selectReadOperation(string $operation): void
    {
        $item = \App\Services\Workflows\WorkflowAmoReadCatalog::availableOperations()[$operation] ?? null;
        if (!$item) return;
        $before = WorkflowGraph::nodes($this->workflowActions);
        $this->selectActionType('amocrm_read');
        foreach (array_diff_key(WorkflowGraph::nodes($this->workflowActions), $before) as $node) {
            data_set($this->workflowActions, $node['path'].'.config.operation', $operation);
            data_set($this->workflowActions, $node['path'].'.config.body_mode', \App\Services\Workflows\WorkflowEntityQuery::supports($operation) ? 'builder' : 'fields');
            data_set($this->workflowActions, $node['path'].'.name', $item['node_name'] ?? (($item['entity_group'] ?? $item['group']).' · '.$item['name']));
        }
        $this->syncDefinition();
    }

    protected function syncDefinition(): void
    {
        $oldNames = \App\Services\Workflows\WorkflowExpressionCatalog::referenceNames($this->definition['actions'] ?? [], $this->definition);
        $newNames = \App\Services\Workflows\WorkflowExpressionCatalog::referenceNames($this->workflowActions, array_merge($this->definition, ['trigger' => $this->trigger]));
        $this->workflowActions = \App\Services\Workflows\WorkflowExpressionCatalog::remapReferences($this->workflowActions, $oldNames, $newNames);
        $this->definition = array_merge($this->definition, [
            'version' => 2, 'trigger' => $this->trigger, 'actions' => $this->workflowActions,
        ]);
    }

    private function enableExplicitConnections(): void
    {
        $this->syncDefinition();
        $this->definition['connections'] = WorkflowGraph::connections($this->definition);
    }

    public function openDetachedActionPalette(string $mode = 'action'): void
    {
        $this->cancelActionInsertion();
        $this->switchActionPalette($mode);
    }

    public function cancelActionInsertion(): void
    {
        $this->insertConnection = null;
        $this->insertActionPath = null;
        $this->insertActionIndex = null;
        $this->targetPath = null;
    }

    public function switchActionPalette(string $mode = 'action'): void
    {
        // Selecting a catalogue category must not forget the edge opened with +.
        $this->dispatch('workflow-node-library-open', mode: in_array($mode, ['action', 'query', 'service'], true) ? $mode : 'action');
    }

    public function openAddActionOnConnection(string $source, string $port, ?string $target = null): void
    {
        // Opening a picker is not an edit; materialize connections only on insertion.
        $nodes = WorkflowGraph::nodes($this->workflowActions);
        if (!isset(\App\Services\Workflows\WorkflowStartNodes::all($this->definition)[$source]) && !isset($nodes[$source])) return;
        $this->insertConnection = ['sourceId' => $source, 'sourcePort' => $port, 'targetId' => $target];
        $this->insertActionPath = null;
        $this->insertActionIndex = null;
        $this->targetPath = null;
        $this->dispatch('workflow-node-library-open', mode: 'action');
    }

    public function connectWorkflowNodes(string $source, string $port, string $target, ?string $replaceTarget = null): void
    {
        $this->enableExplicitConnections();
        $definition = $this->definition;
        $definition['connections'] = array_values(array_filter($definition['connections'], fn ($edge) => !(
            $edge['sourceId'] === $source && $edge['sourcePort'] === $port && in_array($edge['targetId'], [$target, $replaceTarget], true)
        )));
        $definition['connections'][] = ['sourceId' => $source, 'sourcePort' => $port, 'targetId' => $target];
        try {
            WorkflowGraph::ordered($definition);
        } catch (InvalidArgumentException $exception) {
            Notification::make()->warning()->title($exception->getMessage())->send();
            return;
        }
        $this->definition = $definition;
        $this->dispatch('workflow-connections-updated');
    }

    public function disconnectWorkflowNodes(string $source, string $port, string $target): void
    {
        $this->enableExplicitConnections();
        $this->definition['connections'] = array_values(array_filter($this->definition['connections'], fn ($edge) => !(
            $edge['sourceId'] === $source && $edge['sourcePort'] === $port && $edge['targetId'] === $target
        )));
        $this->dispatch('workflow-connections-updated');
    }

    public function removeWorkflowAction(string $actionId): void
    {
        $this->enableExplicitConnections();
        $nodes = WorkflowGraph::nodes($this->workflowActions);
        if (!isset($nodes['action:'.$actionId])) return;
        $nodeId = 'action:'.$actionId;
        $edges = $this->definition['connections'];
        $remaining = array_values(array_filter($edges, fn ($edge) =>
            $edge['sourceId'] !== $nodeId && $edge['targetId'] !== $nodeId));
        // Only ordinary nodes have an unambiguous continuation. Removing a
        // condition must not silently execute both its mutually exclusive arms.
        if (!WorkflowGraph::condition($nodes[$nodeId]['step'])) {
            foreach ($edges as $incoming) {
                if ($incoming['targetId'] !== $nodeId) continue;
                foreach ($edges as $outgoing) {
                    if ($outgoing['sourceId'] !== $nodeId || $outgoing['sourcePort'] !== 'output') continue;
                    $edge = array_merge($incoming, ['targetId' => $outgoing['targetId']]);
                    if (!in_array($edge, $remaining, true)) $remaining[] = $edge;
                }
            }
        }
        $actions = array_values(array_map(function ($entry) {
            $step = $entry['step'];
            unset($step['config']['true_actions'], $step['config']['false_actions']);
            return $step;
        }, array_diff_key($nodes, ['action:'.$actionId => true])));
        try {
            WorkflowGraph::ordered(array_merge($this->definition, ['actions' => $actions, 'connections' => $remaining]));
        } catch (InvalidArgumentException $exception) {
            Notification::make()->warning()->title('Нельзя соединить продолжение ветки')->body($exception->getMessage())->send();
            return;
        }
        $this->workflowActions = $actions;
        $this->definition['connections'] = $remaining;
        unset($this->definition['canvas_layout'][$nodeId]);
        $this->syncDefinition();
    }
}
