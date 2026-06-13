@php
    $groupOptions = \App\Models\Workflows\Workflow::groupOptions();
    $triggerOptions = app(\Leek\FilamentWorkflows\Triggers\TriggerRegistry::class)->getSelectOptions();
    $createUrl = \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl('create');
@endphp

<div class="workflow-list-controls">
    <label class="workflow-list-controls__field">
        <span class="workflow-list-controls__label">Группировка</span>
        <span class="workflow-list-controls__select">
            <x-filament::input.select wire:model.live="tableGrouping">
                <option value="">Без группировки</option>
                <option value="__without_group__:asc">Без группы</option>

                @foreach ($groupOptions as $groupName)
                    <option value="{{ $groupName }}:asc">{{ $groupName }}</option>
                @endforeach
            </x-filament::input.select>
        </span>
    </label>

    <label class="workflow-list-controls__field workflow-list-controls__field--compact">
        <span class="workflow-list-controls__label">Активность</span>
        <span class="workflow-list-controls__select">
            <x-filament::input.select wire:model.live="tableFilters.is_active.value">
                <option value="">Все</option>
                <option value="1">Включены</option>
                <option value="0">Выключены</option>
            </x-filament::input.select>
        </span>
    </label>

    <label class="workflow-list-controls__field workflow-list-controls__field--wide">
        <span class="workflow-list-controls__label">Триггер</span>
        <span class="workflow-list-controls__select">
            <x-filament::input.select wire:model.live="tableFilters.workflow_trigger.value">
                <option value="">Все триггеры</option>

                @foreach ($triggerOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </span>
    </label>

    <div class="workflow-list-controls__spacer"></div>

    <x-filament::button
        :href="$createUrl"
        tag="a"
        icon="heroicon-o-plus"
        color="warning"
        class="workflow-list-controls__create"
    >
        Создать
    </x-filament::button>
</div>
