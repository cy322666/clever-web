@props([
    'actions',
    'parentPath' => null,
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
        >
            <x-filament-workflows::workflows.action-card
                :action="$action"
                :metadata="$this->getWorkflowActionMetadata($action['type'], $config)"
                wire:key="action-{{ $actionId }}"
            />

            @if($isCondition)
                <div class="workflow-condition-branches workflow-condition-branches--split workflow-condition-split" x-on:click.stop>
                    <section class="condition-branch condition-branch--yes" aria-label="Ветка Да">
                        <x-filament-workflows::workflows.action-list
                            :actions="$config['true_actions'] ?? []"
                            :parent-path="$currentPath . '.config.true_actions'"
                        />
                    </section>
                    <section class="condition-branch condition-branch--no" aria-label="Ветка Нет">
                        @if(! array_key_exists('connections', $this->definition ?? []) && ! ($config['has_false_branch'] ?? false) && ! empty($config['false_actions'] ?? []))
                            <div class="workflow-node-branch-warning">Ветка выключена, сохранено шагов: {{ count($config['false_actions']) }}</div>
                        @endif
                        <x-filament-workflows::workflows.action-list
                            :actions="$config['false_actions'] ?? []"
                            :parent-path="$currentPath . '.config.false_actions'"
                        />
                    </section>
                </div>
            @endif
        </div>
    @endforeach
</div>
