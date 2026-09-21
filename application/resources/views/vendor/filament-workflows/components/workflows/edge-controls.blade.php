@props(['actions' => [], 'connections' => null, 'startIds' => ['trigger']])

@php
    $edgeControls = \App\Services\Workflows\WorkflowCanvasGraph::edges($actions, $connections, false, $startIds);
@endphp

<div x-ref="edgeControls" class="workflow-node-edge-controls">
    @foreach($edgeControls as $edge)
        @if($edge['targetId'])
        <span
            hidden
            wire:key="workflow-edge-{{ $edge['sourceId'] }}-{{ $edge['sourcePort'] }}-{{ $edge['targetId'] }}"
            data-workflow-edge-source="{{ $edge['sourceId'] }}"
            data-workflow-edge-port="{{ $edge['sourcePort'] }}"
            data-workflow-edge-target="{{ $edge['targetId'] }}"
        ></span>
        @endif
    @endforeach
</div>
