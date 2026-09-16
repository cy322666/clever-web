<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use App\Services\Workflows\WorkflowStartNodes;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

trait HasWorkflowStartNodes
{
    #[Locked]
    public string $editingTriggerNodeId = 'trigger';
    #[Locked]
    public ?string $replacingTriggerNodeId = null;

    public function beginTriggerAdd(): void
    {
        $this->replacingTriggerNodeId = null;
        $this->dispatch('workflow-node-library-open', mode: 'trigger');
    }

    public function beginTriggerReplace(string $id): void
    {
        if (!isset(WorkflowStartNodes::all($this->definition)[$id])) return;
        $this->replacingTriggerNodeId = $id;
        $this->dispatch('workflow-node-library-open', mode: 'trigger');
    }

    public function editTriggerNode(string $id): void
    {
        $start = WorkflowStartNodes::all($this->definition)[$id] ?? null;
        if (!$start || !in_array($start['type'], ['schedule', 'generic-webhook'], true)) return;
        $this->editingTriggerNodeId = $id;
        $this->mountAction('configureTrigger');
    }

    public function editingTriggerNode(): array
    {
        return WorkflowStartNodes::all($this->definition)[$this->editingTriggerNodeId] ?? $this->trigger ?? [];
    }

    protected function storeTriggerNode(array $start): void
    {
        $id = $this->replacingTriggerNodeId;
        if (!$this->trigger || $id === 'trigger') {
            $this->trigger = $start;
            $id = 'trigger';
        } else {
            $this->enableExplicitConnections();
            $starts = $this->definition['additional_triggers'] ?? [];
            if ($id === null && count($starts) >= 20) {
                throw \Illuminate\Validation\ValidationException::withMessages(['trigger' => 'Максимум 21 запуск в сценарии.']);
            }
            $id ??= 'trigger:'.Str::uuid();
            $starts = array_values(array_filter($starts, fn ($item) => $item['id'] !== $id));
            $starts[] = ['id' => $id] + $start;
            $this->definition['additional_triggers'] = $starts;
        }
        $this->editingTriggerNodeId = $id;
        $this->replacingTriggerNodeId = null;
        $this->syncDefinition();
    }

    public function removeTriggerNode(string $id): void
    {
        if (!isset(WorkflowStartNodes::all($this->definition)[$id])) return;
        $this->enableExplicitConnections();
        $this->definition['connections'] = array_values(array_filter($this->definition['connections'], fn ($edge) => $edge['sourceId'] !== $id));
        $starts = array_values(array_filter($this->definition['additional_triggers'] ?? [], fn ($start) => $start['id'] !== $id));
        if ($id === 'trigger') {
            $promoted = array_shift($starts);
            $this->trigger = $promoted ? array_diff_key($promoted, ['id' => true]) : null;
            if ($promoted) foreach ($this->definition['connections'] as &$edge) {
                if ($edge['sourceId'] === $promoted['id']) $edge['sourceId'] = 'trigger';
            }
            unset($edge);
        }
        $this->definition['additional_triggers'] = $starts;
        $this->syncDefinition();
        $this->dispatch('workflow-connections-updated');
    }

    public function saveTriggerNodeConfig(array $data): void
    {
        $this->replacingTriggerNodeId = $this->editingTriggerNodeId;
        $this->storeTriggerNode(array_replace($this->editingTriggerNode(), ['config' => $data]));
    }

    public function updateWorkflowDescription(string $description): void
    {
        $this->definition['description'] = mb_substr($description, 0, 10000);
    }
}
