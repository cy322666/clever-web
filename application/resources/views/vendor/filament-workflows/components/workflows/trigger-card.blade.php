@props([
    'type',
    'config' => [],
    'metadata' => [],
    'readOnly' => false,
    'nodeId' => 'trigger',
    'nodeName' => null,
    'disabled' => false,
])

@php
    $name = $nodeName ?: ($metadata['name'] ?? 'Событие запуска');
    $icon = $metadata['icon'] ?? 'heroicon-o-bolt';
    $icon = \App\Services\Workflows\WorkflowAmoIcons::trigger($type, $icon);
    $appearance = \App\Services\Workflows\WorkflowNodeAppearance::trigger($type, $icon);
    $subtitle = match ($type) {
        'manual' => 'Запуск вручную',
        'amo-button' => 'amoCRM · Кнопка в сделке',
        'amo-bulk' => 'amoCRM · Массовое действие',
        'schedule' => 'По расписанию',
        'date-condition' => 'По дате',
        'generic-webhook' => 'Входящий вебхук',
        'workflow-completed' => 'После другого сценария',
        default => 'Событие amoCRM',
    };
@endphp

<div
    data-workflow-node-card
    data-node-family="{{ $appearance['family'] }}"
    data-node-tone="{{ $appearance['tone'] }}"
    data-workflow-node-disabled="{{ $disabled ? 'true' : 'false' }}"
    @if(! $readOnly)
        x-bind:data-workflow-node-disabled="canvasTrigger(@js($nodeId))?.disabled ? 'true' : 'false'"
        x-bind:class="{ 'workflow-node-card--disabled': !!canvasTrigger(@js($nodeId))?.disabled }"
        x-data="workflowInlineNodeName(@js($nodeId), @js($name))"
        x-on:click="if (!renaming) { if ($wire.debugOpen) selectDebugNode(@js($nodeId)); else window.dispatchEvent(new CustomEvent('workflow-inspector-open', { detail: { componentId: $wire.$id, nodeId: @js($nodeId), name: nodeName } })); }"
        tabindex="0"
        x-on:keydown.enter.self.prevent="$el.click()"
        aria-label="Событие: {{ $name }}"
    @else
        aria-label="{{ $name }}"
    @endif
    {{ $attributes->class(['workflow-card workflow-node-card workflow-node-card--compact workflow-node-card--trigger workflow-trigger-card', 'workflow-node-card--disabled' => $readOnly && $disabled]) }}
>
    @if($readOnly)
        <span class="workflow-node-port workflow-node-port--output" aria-hidden="true"></span>
    @else
        <button type="button" class="workflow-node-port workflow-node-port--output" x-on:pointerdown.stop="startConnection(@js($nodeId), 'output', $event)" x-on:keydown.enter.prevent.stop="startConnection(@js($nodeId), 'output', $event)" x-on:click.stop x-bind:data-connection-source="connecting?.sourceId === @js($nodeId) && connecting?.sourcePort === 'output'" aria-label="Выход запуска"></button>
    @endif
    <span class="workflow-node-card__icon">
        <x-workflow-node-icon :appearance="$appearance"/>
    </span>
    @include('filament.workflow-builder.workflow-node-start-marker')
    <span class="workflow-node-result" data-workflow-node-result hidden></span>
    @include('filament.workflow-builder.workflow-node-status')
    <div class="workflow-node-card__body workflow-node-card__caption">
        @if($readOnly)
            <h4 class="workflow-node-card__title" title="{{ $name }}">{{ $name }}</h4>
        @else
            @include('filament.workflow-builder.workflow-inline-node-name')
        @endif
    </div>

    @unless($readOnly)
        <div class="workflow-node-card__tools" x-on:click.stop x-on:pointerdown.stop>
            <button type="button" x-on:click.stop="toggleCanvasTrigger(@js($nodeId))"
                x-bind:aria-label="(canvasTrigger(@js($nodeId))?.disabled ? 'Включить' : 'Выключить') + ' ноду: ' + nodeName"
                x-bind:title="(canvasTrigger(@js($nodeId))?.disabled ? 'Включить' : 'Выключить') + ' ноду'"
                x-bind:aria-pressed="!!canvasTrigger(@js($nodeId))?.disabled"><x-filament::icon icon="heroicon-o-power" class="h-4 w-4"/></button>
            <button type="button" x-on:click.stop="workflowEditorCall($wire, 'removeTriggerNode', @js($nodeId))" aria-label="Удалить запуск" title="Удалить запуск"><x-filament::icon icon="heroicon-o-trash" class="h-4 w-4"/></button>
        </div>
    @endunless
</div>
