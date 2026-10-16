@props(['actions' => [], 'connections' => null, 'startIds' => ['trigger']])

@php($edgeControls = \App\Services\Workflows\WorkflowCanvasGraph::edges($actions, $connections, false, $startIds))

{{-- Alpine owns the repeated controls. Do not morph its generated x-for children. --}}
<div x-ref="edgeControls" class="workflow-node-edge-controls" wire:ignore wire:key="workflow-edge-controls-{{ md5(json_encode($edgeControls)) }}">
    <template x-for="edge in canvasEdges(@js($edgeControls), @js($startIds))" :key="edgeRouteKey(edge)">
        <div style="display: contents">
            <button type="button" class="workflow-node-edge-add"
                x-bind:class="{'workflow-loop-replace': edge.sourcePort === 'each' && !edge.targetId}"
                x-on:pointerdown.stop="startEdgeControlDrag(edge.sourceId, edge.sourcePort, edge.targetId, $event)"
                x-on:click.stop="openEdgePalette(edge.sourceId, edge.sourcePort, edge.targetId)"
                x-on:contextmenu.prevent.stop="if (edge.targetId) selectedEdge = edge"
                x-on:pointerenter="if (edge.targetId) showEdgeControls($el)"
                x-on:pointerleave="if (edge.targetId) hideEdgeControls($el)"
                x-bind:data-workflow-edge-source="edge.sourceId"
                x-bind:data-workflow-edge-port="edge.sourcePort"
                x-bind:data-workflow-edge-target="edge.targetId"
                x-bind:data-workflow-loop-return="edge.loopReturn ? 'true' : 'false'"
                x-bind:data-workflow-loop-feedback="edge.loopFeedback ? 'true' : 'false'"
                x-bind:data-workflow-loop-placeholder="edge.sourcePort === 'each' && !edge.targetId ? '' : null"
                x-bind:aria-label="edge.sourcePort === 'each' && !edge.targetId ? 'Заменить заглушку действием цикла' : edge.targetId ? 'Добавить промежуточную ноду' : 'Добавить действие'"
                x-bind:title="edge.sourcePort === 'each' && !edge.targetId ? 'Выберите действие для каждого элемента' : edge.targetId ? 'Добавить промежуточную ноду · Перетащите, чтобы изменить связь' : 'Добавить действие'">
                <template x-if="edge.sourcePort === 'each' && !edge.targetId">
                    <span class="workflow-loop-replace__content"><x-filament::icon icon="heroicon-o-plus" class="h-5 w-5"/><strong>Замените меня</strong><small>Действие цикла</small></span>
                </template>
                <template x-if="edge.sourcePort !== 'each' || edge.targetId"><x-filament::icon icon="heroicon-o-plus" class="h-3.5 w-3.5"/></template>
            </button>
            <button type="button" class="workflow-node-edge-add" x-show="edge.targetId"
                x-bind:data-workflow-edge-delete="edge.targetId ? '' : null"
                x-on:pointerdown.stop
                x-on:pointerenter="showEdgeControls($el.previousElementSibling)"
                x-on:pointerleave="hideEdgeControls($el.previousElementSibling)"
                x-on:click.stop="selectedEdge = edge; removeSelectedEdge()"
                aria-label="Удалить связь" title="Удалить связь">
                <x-filament::icon icon="heroicon-o-trash" class="h-3.5 w-3.5"/>
            </button>
            <div class="workflow-loop-frame" x-show="edge.sourcePort === 'each' && !edge.targetId" aria-hidden="true"><span>Каждый элемент</span></div>
        </div>
    </template>
</div>
