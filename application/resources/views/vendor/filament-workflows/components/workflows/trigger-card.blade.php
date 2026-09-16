@props([
    'type',
    'config' => [],
    'metadata' => [],
    'readOnly' => false,
    'nodeId' => 'trigger',
    'nodeName' => null,
])

@php
    $name = $nodeName ?: ($metadata['name'] ?? 'Событие запуска');
    $icon = $metadata['icon'] ?? 'heroicon-o-bolt';
    $icon = \App\Services\Workflows\WorkflowAmoIcons::trigger($type, $icon);
    $hasSettings = in_array($type, ['schedule', 'generic-webhook'], true);
    $subtitle = match ($type) {
        'manual' => 'Запуск вручную',
        'amo-button' => 'amoCRM · Кнопка в сделке',
        'schedule' => 'По расписанию',
        'date-condition' => 'По дате',
        'generic-webhook' => 'Входящий вебхук',
        'workflow-completed' => 'После другого сценария',
        default => 'Событие amoCRM',
    };
@endphp

<div
    data-workflow-node-card
    @if(! $readOnly && $hasSettings)
        wire:click="editTriggerNode(@js($nodeId))"
        tabindex="0"
        x-on:keydown.enter.self.prevent="$wire.editTriggerNode(@js($nodeId))"
        aria-label="Настроить запуск: {{ $name }}"
    @else
        aria-label="{{ $name }}"
    @endif
    {{ $attributes->class(['workflow-card workflow-node-card workflow-node-card--compact workflow-node-card--trigger workflow-trigger-card']) }}
>
    @if($readOnly)
        <span class="workflow-node-port workflow-node-port--output" aria-hidden="true"></span>
    @else
        <button type="button" class="workflow-node-port workflow-node-port--output" x-on:pointerdown.stop="startConnection(@js($nodeId), 'output', $event)" x-on:keydown.enter.prevent.stop="startConnection(@js($nodeId), 'output', $event)" x-on:click.stop aria-label="Выход запуска"></button>
    @endif
    <span class="workflow-node-card__icon">
        <x-workflow-icon :icon="$icon" :amo="str_starts_with($type, 'amocrm-') || $type === 'amo-button'" class="h-6 w-6"/>
    </span>
    <div class="workflow-node-card__body workflow-node-card__caption">
        <h4 class="workflow-node-card__title" title="{{ $name }}">{{ $name }}</h4>
        <p class="workflow-node-card__subtitle">{{ $subtitle }}</p>
    </div>

    @unless($readOnly)
        <div class="workflow-node-card__tools" x-on:click.stop x-on:pointerdown.stop>
            <button type="button" wire:click.stop="mountAction('renameWorkflowNode', { id: @js($nodeId) })" aria-label="Переименовать ноду: {{ $name }}" title="Переименовать ноду"><x-filament::icon icon="heroicon-o-pencil" class="h-4 w-4"/></button>
            <button
                type="button"
                wire:click="beginTriggerReplace(@js($nodeId))"
                aria-label="Заменить событие запуска"
                title="Заменить событие запуска"
            >
                <x-filament::icon icon="heroicon-o-arrows-right-left" class="h-4 w-4"/>
            </button>
            <button type="button" wire:click="removeTriggerNode(@js($nodeId))" aria-label="Удалить запуск" title="Удалить запуск"><x-filament::icon icon="heroicon-o-trash" class="h-4 w-4"/></button>
        </div>
    @endunless
</div>
