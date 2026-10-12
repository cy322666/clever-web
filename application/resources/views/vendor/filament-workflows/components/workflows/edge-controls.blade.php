@props(['actions' => [], 'connections' => null, 'startIds' => ['trigger']])

@php($edgeControls = \App\Services\Workflows\WorkflowCanvasGraph::edges($actions, $connections, false, $startIds))

<div x-ref="edgeControls" class="workflow-node-edge-controls">
    <template x-for="edge in canvasEdges(@js($edgeControls), @js($startIds))" :key="edgeRouteKey(edge)">
        <div style="display: contents">
            <button type="button" class="workflow-node-edge-add"
                x-on:pointerdown.stop="startEdgeControlDrag(edge.sourceId, edge.sourcePort, edge.targetId, $event)"
                x-on:click.stop="openEdgePalette(edge.sourceId, edge.sourcePort, edge.targetId)"
                x-on:contextmenu.prevent.stop="if (edge.targetId) selectedEdge = edge"
                x-on:pointerenter="if (edge.targetId) showEdgeControls($el)"
                x-on:pointerleave="if (edge.targetId) hideEdgeControls($el)"
                x-bind:data-workflow-edge-source="edge.sourceId"
                x-bind:data-workflow-edge-port="edge.sourcePort"
                x-bind:data-workflow-edge-target="edge.targetId"
                x-bind:data-workflow-loop-return="edge.loopReturn ? 'true' : 'false'"
                x-bind:aria-label="edge.targetId ? 'Добавить промежуточную ноду' : 'Добавить действие'"
                x-bind:title="edge.targetId ? 'Добавить промежуточную ноду · Перетащите, чтобы изменить связь' : 'Добавить действие'">
                <x-filament::icon icon="heroicon-o-plus" class="h-3.5 w-3.5"/>
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
        </div>
    </template>
</div>
