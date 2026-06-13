@php
    $groupOptions = \App\Models\Workflows\Workflow::groupOptions();
    $triggerOptions = app(\Leek\FilamentWorkflows\Triggers\TriggerRegistry::class)->getSelectOptions();
    $createUrl = \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl('create');
@endphp

<div class="workflow-list-controls">
    <label class="workflow-list-controls__field">
        <x-filament::input.wrapper prefix="Группировка">
            <x-filament::input.select wire:model.live="tableGrouping">
                <option value="">Без группировки</option>
                <option value="__without_group__:asc">Без группы</option>

                @foreach ($groupOptions as $groupName)
                    <option value="{{ $groupName }}:asc">{{ $groupName }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </label>

    <label class="workflow-list-controls__field workflow-list-controls__field--compact">
        <x-filament::input.wrapper prefix="Активность">
            <x-filament::input.select wire:model.live="tableFilters.is_active.value">
                <option value="">Все</option>
                <option value="1">Включены</option>
                <option value="0">Выключены</option>
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </label>

    <label class="workflow-list-controls__field workflow-list-controls__field--wide">
        <x-filament::input.wrapper prefix="Триггер">
            <x-filament::input.select wire:model.live="tableFilters.workflow_trigger.value">
                <option value="">Все триггеры</option>

                @foreach ($triggerOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
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
