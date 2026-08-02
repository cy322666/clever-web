@php
    $createUrl = \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl('create');
    $activeFilter = data_get($this->tableFilters, 'is_active.value');
    $showsAll = $activeFilter === null || $activeFilter === '';
    $showsActive = in_array($activeFilter, [true, 1, '1'], true);
    $showsInactive = in_array($activeFilter, [false, 0, '0'], true);
@endphp

<div class="workflow-list-controls">
    <div class="workflow-list-activity" aria-label="Фильтр активности">
        <button
            type="button"
            wire:click="$set('tableFilters.is_active.value', '')"
            @class([
                'workflow-list-activity__button',
                'workflow-list-activity__button--active' => $showsAll,
            ])
        >
            Все
        </button>

        <button
            type="button"
            wire:click="$set('tableFilters.is_active.value', '1')"
            @class([
                'workflow-list-activity__button',
                'workflow-list-activity__button--active' => $showsActive,
            ])
        >
            Активные
        </button>

        <button
            type="button"
            wire:click="$set('tableFilters.is_active.value', '0')"
            @class([
                'workflow-list-activity__button',
                'workflow-list-activity__button--active' => $showsInactive,
            ])
        >
            Неактивные
        </button>
    </div>

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
