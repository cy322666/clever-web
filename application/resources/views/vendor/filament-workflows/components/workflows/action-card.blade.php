@props([
    'action',
    'index' => 0,
    'total' => 1,
    'metadata' => [],
    'readOnly' => false,
])

@php
    $actionId = (string) ($action['id'] ?? '');
    $actionType = $action['type'] ?? '';
    $isCondition = in_array($actionType, ['condition', 'control-condition'], true);
    $disabled = (bool) ($action['disabled'] ?? false);
    $actionName = trim((string) ($action['name'] ?? '')) ?: ($isCondition ? 'Условие' : ($metadata['name'] ?? 'Действие'));
    $icon = $metadata['icon'] ?? ($isCondition ? 'heroicon-o-arrows-right-left' : 'heroicon-o-cog-6-tooth');
    $config = $action['config'] ?? [];
    $icon = \App\Services\Workflows\WorkflowAmoIcons::action($actionType, $config, $icon);
    $entity = $config['target_entity'] ?? $config['entity'] ?? (str_contains($actionType, 'lead') ? 'lead' : '');
    $entityLabel = ['lead' => 'Сделка', 'contact' => 'Контакт', 'company' => 'Компания', 'customer' => 'Покупатель', 'task' => 'Задача'][$entity] ?? null;
    $subtitle = $isCondition ? 'Если / иначе' : ($entityLabel ? 'amoCRM · ' . $entityLabel : ($metadata['category'] ?? 'Действие'));
    if ($disabled) {
        $subtitle = $isCondition ? 'Выключено → «Да»' : 'Выключено';
    }
@endphp

<div
    data-workflow-node-card
    data-workflow-node-disabled="{{ $disabled ? 'true' : 'false' }}"
    @unless($readOnly)
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
        'workflow-node-card--disabled' => $disabled,
    ]) }}
>
    @if($readOnly)
        <span class="workflow-node-port workflow-node-port--input" aria-hidden="true"></span>
        @if($isCondition)
            @foreach(['yes' => 'Да', 'no' => 'Нет'] as $port => $label)
                <span class="workflow-node-port workflow-node-port--output workflow-node-port--output-{{ $port }}" aria-hidden="true"><span class="workflow-node-port__label">{{ $label }}</span></span>
            @endforeach
        @else
            <span class="workflow-node-port workflow-node-port--output" aria-hidden="true"></span>
        @endif
    @else
    <button type="button" class="workflow-node-port workflow-node-port--input" x-on:pointerdown.stop="startIncomingConnection('action:' + @js($actionId), $event)" x-on:pointerup.stop="if (connecting?.replaceTarget !== 'action:' + @js($actionId)) finishConnection('action:' + @js($actionId), $event)" x-on:click.stop="if (connecting?.replaceTarget !== 'action:' + @js($actionId)) completeConnection('action:' + @js($actionId))" aria-label="Вход: {{ $actionName }}"></button>
    @if($isCondition)
        @foreach(['yes' => 'Да', 'no' => 'Нет'] as $port => $label)
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
    <div class="workflow-node-card__body workflow-node-card__caption">
        <h4 class="workflow-node-card__title" title="{{ $actionName }}">{{ $actionName }}</h4>
        <p class="workflow-node-card__subtitle" title="{{ $subtitle }}">{{ $subtitle }}</p>
    </div>

    @unless($readOnly)
        <div class="workflow-node-card__tools" x-on:click.stop x-on:pointerdown.stop>
            <button type="button" wire:click.stop="runWorkflowCanvasNode(@js($actionId))" wire:loading.attr="disabled" wire:target="runWorkflowCanvasNode,runEditingWorkflowNode"
                @disabled($disabled) aria-label="Выполнить ноду: {{ $actionName }}" title="Реально выполнить ноду в текущем контексте">
                <x-filament::icon icon="heroicon-o-play" class="h-4 w-4"/>
            </button>
            <button type="button" wire:click.stop="mountAction('renameWorkflowNode', { id: @js($actionId) })" aria-label="Переименовать ноду: {{ $actionName }}" title="Переименовать ноду">
                <x-filament::icon icon="heroicon-o-pencil" class="h-4 w-4"/>
            </button>
            <button
                type="button"
                wire:click.stop="toggleWorkflowActionDisabled('{{ $actionId }}')"
                wire:loading.attr="disabled"
                wire:target="toggleWorkflowActionDisabled('{{ $actionId }}')"
                aria-label="{{ $disabled ? 'Включить' : 'Выключить' }} ноду: {{ $actionName }}"
                title="{{ $disabled ? 'Включить' : 'Выключить' }} ноду"
                aria-pressed="{{ $disabled ? 'true' : 'false' }}"
            >
                <x-filament::icon icon="heroicon-o-power" class="h-4 w-4"/>
            </button>
            <button
                type="button"
                wire:click.stop="duplicateWorkflowActionStep('{{ $actionId }}')"
                wire:loading.attr="disabled"
                wire:target="duplicateWorkflowActionStep('{{ $actionId }}')"
                aria-label="Копировать ноду: {{ $actionName }}"
                title="Копировать ноду"
            >
                <x-filament::icon icon="heroicon-o-document-duplicate" class="h-4 w-4"/>
            </button>
            <button
                type="button"
                wire:click.stop="removeWorkflowAction('{{ $actionId }}')"
                aria-label="Удалить ноду: {{ $actionName }}"
                title="Удалить ноду"
            >
                <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4"/>
            </button>
        </div>
    @endunless
</div>
