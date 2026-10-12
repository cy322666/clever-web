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
    data-workflow-node-disabled="{{ $disabled ? 'true' : 'false' }}"
    @if($readOnly && $executionCount !== null)
        x-bind:class="{ 'workflow-node-card--selected': selectedNodeId() === 'action:' + @js($actionId) }"
    @endif
    @unless($readOnly)
        x-bind:style="canvasAction(@js($actionId)) ? '' : 'visibility: hidden; pointer-events: none'"
        x-bind:data-workflow-node-disabled="canvasAction(@js($actionId))?.disabled ? 'true' : 'false'"
        x-bind:class="{ 'workflow-node-card--disabled': !!canvasAction(@js($actionId))?.disabled }"
        x-bind:data-node-running="workflowNodeIsRunning($wire, @js($actionId))"
        x-bind:aria-busy="workflowNodeIsRunning($wire, @js($actionId))"
        x-on:pointerup.capture="if (connecting && !$event.target.closest('.workflow-node-port')) finishConnection('action:' + @js($actionId), $event)"
        x-on:click.capture="if (connecting && !$event.target.closest('.workflow-node-port')) { $event.stopImmediatePropagation(); completeConnection('action:' + @js($actionId)); }"
        wire:click="selectWorkflowCanvasNode('{{ $actionId }}')"
        tabindex="0"
        x-on:keydown.enter.self.prevent="$wire.selectWorkflowCanvasNode(@js($actionId))"
        aria-label="Настроить: {{ $actionName }}"
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
    <button type="button" class="workflow-node-port workflow-node-port--input" x-on:pointerdown.stop="startIncomingConnection('action:' + @js($actionId), $event)" x-on:pointerup.stop="if (connecting?.replaceTarget !== 'action:' + @js($actionId)) finishConnection('action:' + @js($actionId), $event)" x-on:click.stop="if (connecting?.replaceTarget !== 'action:' + @js($actionId)) completeConnection('action:' + @js($actionId))" aria-label="Вход: {{ $actionName }}"></button>
    @if($isCondition || $isLoop)
        @foreach($branchPorts as $port => $label)
            <button type="button" class="workflow-node-port workflow-node-port--output workflow-node-port--output-{{ $port }}" @unless($readOnly) x-on:pointerdown.stop="startConnection('action:' + @js($actionId), @js($port), $event)" x-on:click.stop @endunless aria-label="Выход {{ $label }}: {{ $actionName }}"><span class="workflow-node-port__label">{{ $label }}</span></button>
        @endforeach
    @else
        <button type="button" class="workflow-node-port workflow-node-port--output" @unless($readOnly) x-on:pointerdown.stop="startConnection('action:' + @js($actionId), 'output', $event)" x-on:click.stop @endunless aria-label="Выход: {{ $actionName }}"></button>
    @endif
    @endif

    <span class="workflow-node-card__icon">
        <x-workflow-icon :icon="$icon" :type="$actionType" :amo="str_starts_with($actionType, 'amocrm_')" class="h-6 w-6"/>
    </span>
    <span class="workflow-node-result" data-workflow-node-result hidden></span>
    @include('filament.workflow-builder.workflow-node-status')
    @if($readOnly && $executionCount !== null)
        <span class="workflow-execution-order" title="{{ $isLoop ? 'Итераций цикла' : 'Сущностей' }}">{{ $executionCount }}</span>
    @endif
    <div class="workflow-node-card__body workflow-node-card__caption">
        <h4 class="workflow-node-card__title" title="{{ $actionName }}">{{ $actionName }}</h4>
        <p class="workflow-node-card__subtitle" @unless($readOnly) x-text="canvasAction(@js($actionId))?.disabled ? @js($isCondition ? 'Выключено → «Да»' : 'Выключено') : @js($enabledSubtitle)" @endunless>{{ $subtitle }}</p>
    </div>

    @unless($readOnly)
        <div class="workflow-node-card__tools" x-data="workflowNodeMenu()" x-bind:class="{ 'is-menu-open': menuOpen }"
            x-on:click.stop x-on:pointerdown.stop x-on:keydown.stop
            x-on:keydown.escape.prevent.stop="closeMenu(true)"
            x-on:click.outside="closeMenu()"
            x-on:focusout="if (!$el.contains($event.relatedTarget)) closeMenu()"
            x-on:workflow-node-menu-open.window="if ($event.detail !== $el) closeMenu()">
            <button type="button" wire:click.stop="runWorkflowCanvasNode(@js($actionId))" x-bind:disabled="!!canvasAction(@js($actionId))?.disabled || workflowNodeIsRunning($wire)"
                @disabled($disabled) aria-label="Выполнить ноду: {{ $actionName }}" title="{{ $isLoop ? 'Запустить ветку цикла с выбранными настройками' : 'Реально выполнить ноду в текущем контексте' }}">
                <x-filament::icon icon="heroicon-o-play" class="h-4 w-4"/>
            </button>
            <button type="button" x-on:click.stop="removeCanvasAction(@js($actionId))"
                aria-label="Удалить ноду: {{ $actionName }}" title="Удалить ноду">
                <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4"/>
            </button>
            <button
                type="button"
                x-on:click.stop="toggleCanvasAction(@js($actionId))"
                x-bind:aria-label="(canvasAction(@js($actionId))?.disabled ? 'Включить' : 'Выключить') + @js(' ноду: '.$actionName)"
                x-bind:title="(canvasAction(@js($actionId))?.disabled ? 'Включить' : 'Выключить') + ' ноду'"
                x-bind:aria-pressed="!!canvasAction(@js($actionId))?.disabled"
            >
                <x-filament::icon icon="heroicon-o-power" class="h-4 w-4"/>
            </button>
            <button type="button" x-ref="more" x-on:click="toggleMenu()" x-on:keydown.arrow-down.prevent="if (!menuOpen) toggleMenu(); else focusMenuItem(0)"
                aria-haspopup="menu" x-bind:aria-expanded="menuOpen" aria-controls="workflow-node-menu-{{ $actionId }}"
                aria-label="Другие действия: {{ $actionName }}" title="Другие действия">
                <x-filament::icon icon="heroicon-o-ellipsis-horizontal" class="h-4 w-4"/>
            </button>
            <div class="workflow-node-card__menu" id="workflow-node-menu-{{ $actionId }}" x-ref="menu" x-show="menuOpen" x-cloak role="menu" aria-label="Действия с нодой: {{ $actionName }}"
                x-on:keydown.arrow-down.prevent="moveMenuFocus($event, 1)" x-on:keydown.arrow-up.prevent="moveMenuFocus($event, -1)"
                x-on:keydown.home.prevent="focusMenuItem(0)" x-on:keydown.end.prevent="focusMenuItem(-1)">
                <button type="button" role="menuitem" tabindex="-1" x-on:click="closeMenu()"
                    wire:click.stop="mountAction('renameWorkflowNode', { id: @js($actionId) })">
                    <x-filament::icon icon="heroicon-o-pencil" class="h-4 w-4"/> Переименовать
                </button>
                <button type="button" role="menuitem" tabindex="-1" x-on:click="closeMenu(true)"
                    wire:click.stop="duplicateWorkflowActionStep('{{ $actionId }}')" wire:loading.attr="disabled" wire:target="duplicateWorkflowActionStep('{{ $actionId }}')">
                    <x-filament::icon icon="heroicon-o-document-duplicate" class="h-4 w-4"/> Скопировать
                </button>
            </div>
        </div>
    @endunless
</div>
