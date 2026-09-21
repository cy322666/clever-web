@props(['actions' => [], 'connections' => null, 'startIds' => ['trigger']])

@php
    $edgeControls = \App\Services\Workflows\WorkflowCanvasGraph::edges($actions, $connections, false, $startIds);
@endphp

<div x-ref="edgeControls" class="workflow-node-edge-controls">
    @foreach($edgeControls as $edge)
        @php
            $label = match (true) {
                ($edge['branch'] ?? false) && $edge['sourcePort'] === 'yes' => 'Добавить ещё одну ветку «Да»',
                ($edge['branch'] ?? false) && $edge['sourcePort'] === 'no' => 'Добавить ещё одну ветку «Нет»',
                $edge['sourcePort'] === 'yes' => 'Добавить действие в ветку «Да»',
                $edge['sourcePort'] === 'no' => 'Добавить действие в ветку «Нет»',
                default => 'Добавить действие',
            };
        @endphp
        <button
            type="button"
            wire:key="workflow-edge-add-{{ $edge['sourceId'] }}-{{ $edge['sourcePort'] }}-{{ $edge['targetId'] ?? (($edge['branch'] ?? false) ? 'branch' : 'end') }}"
            x-on:pointerdown.stop="startEdgeControlDrag(@js($edge['sourceId']), @js($edge['sourcePort']), @js($edge['targetId']), $event)"
            x-on:click.stop="openEdgePalette(@js($edge['sourceId']), @js($edge['sourcePort']), @js($edge['targetId']))"
            @if($edge['targetId']) x-on:contextmenu.prevent.stop="selectedEdge = @js(array_intersect_key($edge, array_flip(['sourceId', 'sourcePort', 'targetId'])))" @endif
            data-workflow-edge-source="{{ $edge['sourceId'] }}"
            data-workflow-edge-port="{{ $edge['sourcePort'] }}"
            @if($edge['branch'] ?? false) data-workflow-edge-branch="true" @endif
            @if($edge['targetId']) data-workflow-edge-target="{{ $edge['targetId'] }}" @endif
            class="workflow-node-edge-add"
            @if($edge['targetId']) x-on:pointerenter="showEdgeControls($el)" x-on:pointerleave="hideEdgeControls($el)" @endif
            aria-label="{{ $label }}"
            title="{{ $label }} · Перетащите к ноде, чтобы соединить{{ $edge['targetId'] ? ' · Правый клик: изменить связь' : '' }}"
        >
            <x-filament::icon icon="heroicon-o-plus" class="h-3.5 w-3.5"/>
        </button>
        @if($edge['targetId'])
            <button
                type="button"
                wire:key="workflow-edge-delete-{{ $edge['sourceId'] }}-{{ $edge['sourcePort'] }}-{{ $edge['targetId'] }}"
                data-workflow-edge-delete
                class="workflow-node-edge-add"
                x-on:pointerdown.stop
                x-on:pointerenter="showEdgeControls($el.previousElementSibling)"
                x-on:pointerleave="hideEdgeControls($el.previousElementSibling)"
                x-on:click.stop="selectedEdge = @js(array_intersect_key($edge, array_flip(['sourceId', 'sourcePort', 'targetId']))); removeSelectedEdge()"
                aria-label="Удалить связь"
                title="Удалить связь"
            >
                <x-filament::icon icon="heroicon-o-trash" class="h-3.5 w-3.5"/>
            </button>
        @endif
    @endforeach
</div>
