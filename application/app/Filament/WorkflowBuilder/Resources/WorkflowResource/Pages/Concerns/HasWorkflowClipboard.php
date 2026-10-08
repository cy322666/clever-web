<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use App\Services\Workflows\WorkflowExpressionCatalog;
use App\Services\Workflows\WorkflowGraph;
use App\Services\Workflows\WorkflowStartNodes;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

trait HasWorkflowClipboard
{
    #[Locked]
    public ?array $workflowClipboard = null;

    public function copyWorkflowNodes(array $ids): bool
    {
        $definition = array_merge($this->definition, ['actions' => $this->workflowActions, 'trigger' => $this->trigger]);
        $selected = array_fill_keys(array_filter($ids, 'is_string'), true);
        $nodes = [];
        foreach (WorkflowStartNodes::all($definition) as $id => $start) {
            if (isset($selected[$id])) $nodes[$id] = $start;
        }
        foreach (WorkflowGraph::nodes($this->workflowActions) as $id => $entry) {
            if (!isset($selected[$id])) continue;
            $step = $entry['step'];
            unset($step['config']['true_actions'], $step['config']['false_actions']);
            $nodes[$id] = $step;
        }
        if (!$nodes) return false;
        $this->workflowClipboard = [
            'nodes' => $nodes,
            'names' => WorkflowExpressionCatalog::referenceNames($this->workflowActions, $definition),
            'edges' => array_values(array_filter(WorkflowGraph::connections($definition),
                fn ($edge) => isset($nodes[$edge['sourceId']], $nodes[$edge['targetId']]))),
        ];
        return true;
    }

    public function pasteWorkflowNodes(): array
    {
        if (!$this->workflowClipboard) return [];
        $this->enableExplicitConnections();
        $definition = $this->definition;
        $actions = $this->workflowActions;
        $names = WorkflowExpressionCatalog::referenceNames($actions, $definition);
        $used = array_fill_keys(array_values($names), true);
        $map = $after = $copies = [];
        foreach ($this->workflowClipboard['nodes'] as $oldId => $node) {
            $isAction = str_starts_with($oldId, 'action:');
            $oldKey = $isAction ? substr($oldId, 7) : $oldId;
            $newKey = $isAction ? 'step_'.Str::lower(Str::ulid()->toBase32()) : 'trigger:'.Str::uuid();
            $map[$oldId] = $isAction ? 'action:'.$newKey : $newKey;
            $base = ($this->workflowClipboard['names'][$oldKey] ?? 'Нода').' (копия)';
            $name = $base;
            for ($n = 2; isset($used[$name]); $n++) $name = $base.' '.$n;
            $used[$name] = true;
            $after[$oldKey] = $name;
            $node['id'] = $newKey;
            $node['name'] = $name;
            $copies[$oldId] = $node;
        }
        foreach ($copies as $oldId => $node) {
            // Internal references follow the pasted nodes; external references stay intact.
            $before = array_intersect_key($this->workflowClipboard['names'], $after);
            $node = WorkflowExpressionCatalog::remapReferences($node, $before, $after);
            $oldKeys = $newKeys = [];
            foreach ($map as $source => $target) {
                $key = str_starts_with($source, 'action:') ? substr($source, 7) : $source;
                $oldKeys[$key] = $key;
                $newKeys[$key] = str_starts_with($target, 'action:') ? substr($target, 7) : $target;
            }
            $node = WorkflowExpressionCatalog::remapReferences($node, $oldKeys, $newKeys);
            if (str_starts_with($oldId, 'action:')) $actions[] = $node;
            else $definition['additional_triggers'][] = $node;
        }
        foreach ($this->workflowClipboard['edges'] as $edge) {
            $definition['connections'][] = ['sourceId' => $map[$edge['sourceId']], 'sourcePort' => $edge['sourcePort'], 'targetId' => $map[$edge['targetId']]];
        }
        $definition['actions'] = $actions;
        try {
            WorkflowGraph::ordered($definition);
        } catch (\InvalidArgumentException $exception) {
            Notification::make()->warning()->title('Не удалось вставить ноды')->body($exception->getMessage())->send();
            return [];
        }
        $this->workflowActions = $actions;
        $this->definition = $definition;
        $this->syncDefinition();
        return $map;
    }
}
