@props([
    'actions',
    'parentPath' => null,
    'readOnly' => false,
    'executionNodes' => [],
])

<div class="workflow-sortable-list workflow-node-sequence">
    @foreach($actions as $index => $action)
        @php
            $currentPath = $parentPath !== null && $parentPath !== '' ? "{$parentPath}.{$index}" : "{$index}";
            $actionId = (string) ($action['id'] ?? $currentPath);
            $nodeId = 'action:' . $actionId;
            $isCondition = in_array($action['type'] ?? '', ['condition', 'control-condition'], true);
            $config = $action['config'] ?? [];
        @endphp

        <div
            class="workflow-sortable-item"
            data-workflow-node-id="{{ $nodeId }}"
            data-workflow-detached="{{ array_key_exists('connections', $this->definition ?? []) && ! collect($this->definition['connections'])->contains('targetId', $nodeId) ? 'true' : 'false' }}"
            wire:key="workflow-sortable-{{ $actionId }}"
            @if($readOnly) x-on:click.stop="selectNode(@js($nodeId))" x-on:keydown.enter="selectNode(@js($nodeId))" @endif
        >
            <x-filament-workflows::workflows.action-card
                :action="$action"
                :metadata="$readOnly ? ['name' => $executionNodes[$nodeId]['name'] ?? '', 'icon' => $executionNodes[$nodeId]['icon'] ?? 'heroicon-o-cube', 'category' => ''] : $this->getWorkflowActionMetadata($action['type'], $config)"
                :read-only="$readOnly"
                :execution-count="$readOnly && ($executionNodes[$nodeId]['result'] ?? null) ? $executionNodes[$nodeId]['item_count'] : null"
                :data-execution-status="$readOnly ? ($executionNodes[$nodeId]['status'] ?? 'pending') : null"
                :tabindex="$readOnly ? 0 : null"
                :aria-label="$readOnly ? 'Данные ноды: '.($executionNodes[$nodeId]['name'] ?? '') : null"
                wire:key="action-{{ $actionId }}"
            />

            @if($isCondition)
                <div class="workflow-condition-branches workflow-condition-branches--split workflow-condition-split" x-on:click.stop>
                    <section class="condition-branch condition-branch--yes" aria-label="Ветка Да">
                        <x-filament-workflows::workflows.action-list
                            :actions="$config['true_actions'] ?? []"
                            :parent-path="$currentPath . '.config.true_actions'"
                            :read-only="$readOnly"
                            :execution-nodes="$executionNodes"
                        />
                    </section>
                    <section class="condition-branch condition-branch--no" aria-label="Ветка Нет">
                        @if(! array_key_exists('connections', $this->definition ?? []) && ! ($config['has_false_branch'] ?? false) && ! empty($config['false_actions'] ?? []))
                            <div class="workflow-node-branch-warning">Ветка выключена, сохранено шагов: {{ count($config['false_actions']) }}</div>
                        @endif
                        <x-filament-workflows::workflows.action-list
                            :actions="$config['false_actions'] ?? []"
                            :parent-path="$currentPath . '.config.false_actions'"
                            :read-only="$readOnly"
                            :execution-nodes="$executionNodes"
                        />
                    </section>
                </div>
            @endif
        </div>
    @endforeach
</div>
