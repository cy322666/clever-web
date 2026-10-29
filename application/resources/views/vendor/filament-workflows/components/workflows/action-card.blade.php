@props([
    'action',
    'index' => 0,
    'total' => 1,
    'metadata' => [],
    'readOnly' => false,
    'executionCount' => null,
])

@php
    $actionId = (string) ($action['id'] ?? '');
    $actionType = $action['type'] ?? '';
    $isCondition = in_array($actionType, ['condition', 'control-condition'], true);
    $isLoop = $actionType === 'workflow_loop';
    $branchPorts = $isLoop ? ['done' => 'Готово', 'each' => 'Каждый элемент'] : ['yes' => 'Да', 'no' => 'Нет'];
    $disabled = (bool) ($action['disabled'] ?? false);
    $actionName = trim((string) ($action['name'] ?? '')) ?: ($isCondition ? 'Условие' : ($metadata['name'] ?? 'Действие'));
    $icon = $metadata['icon'] ?? ($isCondition ? 'heroicon-o-arrows-right-left' : 'heroicon-o-cog-6-tooth');
    $config = $action['config'] ?? [];
    $icon = \App\Services\Workflows\WorkflowAmoIcons::action($actionType, $config, $icon);
    $appearance = \App\Services\Workflows\WorkflowNodeAppearance::action($actionType, $config, $icon);
    $entity = $config['target_entity'] ?? $config['entity'] ?? (str_contains($actionType, 'lead') ? 'lead' : '');
    $entityLabel = ['lead' => 'Сделка', 'contact' => 'Контакт', 'company' => 'Компания', 'customer' => 'Покупатель', 'task' => 'Задача'][$entity] ?? null;
    $subtitle = $isCondition ? 'Если / иначе' : ($entityLabel ? 'amoCRM · ' . $entityLabel : ($metadata['category'] ?? 'Действие'));
    $enabledSubtitle = $subtitle;
    if ($disabled) {
        $subtitle = $isCondition ? 'Выключено → «Да»' : 'Выключено';
    }
@endphp

<div
    data-workflow-node-card
    data-node-family="{{ $appearance['family'] }}"
    data-node-tone="{{ $appearance['tone'] }}"
    data-workflow-node-disabled="{{ $disabled ? 'true' : 'false' }}"
    @if($readOnly && $executionCount !== null)
        x-bind:class="{ 'workflow-node-card--selected': selectedNodeId() === 'action:' + @js($actionId) }"
    @endif
    @unless($readOnly)
        x-bind:style="canvasAction(@js($actionId)) ? '' : 'visibility: hidden; pointer-events: none'"
        x-bind:data-workflow-node-disabled="canvasAction(@js($actionId))?.disabled ? 'true' : 'false'"
        x-bind:class="{ 'workflow-node-card--disabled': !!canvasAction(@js($actionId))?.disabled }"
        x-bind:data-node-running="workflowNodeIsRunning($wire, @js($actionId))"
        x-bind:data-debug-selected="$wire.debugOpen && selectedDebugId === @js($actionId)"
        x-bind:data-connection-target="connectionTargetId === 'action:' + @js($actionId)"
        x-bind:aria-busy="workflowNodeIsRunning($wire, @js($actionId))"
        x-on:pointerup.capture="if (connecting && !$event.target.closest('.workflow-node-port')) finishConnection('action:' + @js($actionId), $event)"
        x-on:click.capture="if (connecting && !$event.target.closest('.workflow-node-port')) { $event.stopImmediatePropagation(); completeConnection('action:' + @js($actionId)); }"
        x-data="workflowInlineNodeName(@js($actionId), @js($actionName))"
        x-on:click="if ($wire.debugOpen) selectDebugNode(@js($actionId)); else if (!renaming) window.dispatchEvent(new CustomEvent('workflow-inspector-open', {detail: {componentId: $wire.$id, nodeId: 'action:' + @js($actionId), name: nodeName}}))"
        tabindex="0"
        x-on:keydown.enter.self.prevent="$el.click()"
        aria-label="Настроить: {{ $actionName }}" x-bind:aria-label="'Настроить: ' + nodeName"
    @endunless
    {{ $attributes->class([
        'workflow-card workflow-node-card workflow-node-card--compact',
        'workflow-action-card' => ! $isCondition,
        'workflow-condition-node workflow-node-card--condition' => $isCondition,
        'workflow-node-card--disabled' => $readOnly && $disabled,
    ]) }}
>
    @if($readOnly)
        <span class="workflow-node-port workflow-node-port--input" aria-hidden="true"></span>
        @if($isCondition || $isLoop)
            @foreach($branchPorts as $port => $label)
                <span class="workflow-node-port workflow-node-port--output workflow-node-port--output-{{ $port }}" aria-hidden="true"><span class="workflow-node-port__label">{{ $label }}</span></span>
            @endforeach
        @else
            <span class="workflow-node-port workflow-node-port--output" aria-hidden="true"></span>
        @endif
    @else
    <button type="button" class="workflow-node-port workflow-node-port--input" x-on:pointerdown.stop="startIncomingConnection('action:' + @js($actionId), $event)" x-on:pointerup.stop="if (connecting?.replaceTarget !== 'action:' + @js($actionId)) finishConnection('action:' + @js($actionId), $event)" x-on:click.stop="if (connecting?.replaceTarget !== 'action:' + @js($actionId)) completeConnection('action:' + @js($actionId))" aria-label="Вход: {{ $actionName }}" x-bind:aria-label="'Вход: ' + nodeName"></button>
    @if($isCondition || $isLoop)
        @foreach($branchPorts as $port => $label)
            <button type="button" class="workflow-node-port workflow-node-port--output workflow-node-port--output-{{ $port }}" @unless($readOnly) x-on:pointerdown.stop="startConnection('action:' + @js($actionId), @js($port), $event)" x-on:click.stop @endunless aria-label="Выход {{ $label }}: {{ $actionName }}" x-bind:aria-label="@js('Выход '.$label.': ') + nodeName"><span class="workflow-node-port__label">{{ $label }}</span></button>
        @endforeach
    @else
        <button type="button" class="workflow-node-port workflow-node-port--output" @unless($readOnly) x-on:pointerdown.stop="startConnection('action:' + @js($actionId), 'output', $event)" x-on:click.stop @endunless aria-label="Выход: {{ $actionName }}" x-bind:aria-label="'Выход: ' + nodeName"></button>
    @endif
    @endif

    <span class="workflow-node-card__icon">
        <x-workflow-node-icon :appearance="$appearance"/>
    </span>
    <span class="workflow-node-result" data-workflow-node-result hidden></span>
    @include('filament.workflow-builder.workflow-node-status')
    @if($readOnly && $executionCount !== null)
        <span class="workflow-execution-order" title="{{ $isLoop ? 'Итераций цикла' : 'Сущностей' }}">{{ $executionCount }}</span>
    @endif
    <div class="workflow-node-card__body workflow-node-card__caption">
        @if($readOnly)
            <h4 class="workflow-node-card__title" title="{{ $actionName }}">{{ $actionName }}</h4>
        @else
            @include('filament.workflow-builder.workflow-inline-node-name')
        @endif
        <p class="workflow-node-card__subtitle" @unless($readOnly) x-text="canvasAction(@js($actionId))?.disabled ? @js($isCondition ? 'Выключено → «Да»' : 'Выключено') : @js($enabledSubtitle)" @endunless>{{ $subtitle }}</p>
    </div>

    @unless($readOnly)
        <div class="workflow-node-card__tools" x-on:click.stop x-on:pointerdown.stop x-on:keydown.stop>
            <button type="button" wire:click.stop="runWorkflowCanvasNode(@js($actionId))" x-bind:disabled="!!canvasAction(@js($actionId))?.disabled || workflowNodeIsRunning($wire)"
                @disabled($disabled) aria-label="Выполнить ноду: {{ $actionName }}" x-bind:aria-label="'Выполнить ноду: ' + nodeName" title="{{ $isLoop ? 'Взять первый элемент, не выполняя следующие ноды' : 'Реально выполнить ноду в текущем контексте' }}">
                <x-filament::icon icon="heroicon-o-play" class="h-4 w-4"/>
            </button>
            <button type="button" x-on:click.stop="removeCanvasAction(@js($actionId))"
                aria-label="Удалить ноду: {{ $actionName }}" x-bind:aria-label="'Удалить ноду: ' + nodeName" title="Удалить ноду">
                <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4"/>
            </button>
            <button
                type="button"
                x-on:click.stop="toggleCanvasAction(@js($actionId))"
                x-bind:aria-label="(canvasAction(@js($actionId))?.disabled ? 'Включить' : 'Выключить') + ' ноду: ' + nodeName"
                x-bind:title="(canvasAction(@js($actionId))?.disabled ? 'Включить' : 'Выключить') + ' ноду'"
                x-bind:aria-pressed="!!canvasAction(@js($actionId))?.disabled"
            >
                <x-filament::icon icon="heroicon-o-power" class="h-4 w-4"/>
            </button>
            <button type="button" x-on:click.stop="workflowEditorCall($wire, 'duplicateWorkflowActionStep', @js($actionId))"
                aria-label="Скопировать ноду: {{ $actionName }}" x-bind:aria-label="'Скопировать ноду: ' + nodeName" title="Скопировать ноду">
                <x-filament::icon icon="heroicon-o-document-duplicate" class="h-4 w-4"/>
            </button>
        </div>
    @endunless
</div>
