@php
    $selectedKeys = array_values($record->yc_user_keys ?? []);
    $options = collect($groupedOptions)
        ->map(fn (array $users, string $company): array => [
            'label' => $company,
            'options' => collect($users)
                ->map(fn (string $label, string $value): array => [
                    'label' => $label,
                    'value' => $value,
                    'isDisabled' => false,
                ])
                ->values()
                ->all(),
        ])
        ->values()
        ->all();
    $flatOptions = collect($groupedOptions)->flatMap(fn (array $users): array => $users);
    $initialOptionLabels = collect($selectedKeys)
        ->map(fn (string $value): array => [
            'label' => $flatOptions[$value] ?? $value,
            'value' => $value,
        ])
        ->all();
@endphp

<div
    x-load
    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('select', 'filament/forms') }}"
    x-data="selectFormComponent({
        canOptionLabelsWrap: true,
        canSelectPlaceholder: true,
        getOptionLabelUsing: async () => null,
        getOptionLabelsUsing: async () => @js($initialOptionLabels),
        getOptionsUsing: async () => @js($options),
        getSearchResultsUsing: async () => [],
        hasDynamicOptions: false,
        hasDynamicSearchResults: false,
        hasInitialNoOptionsMessage: true,
        initialOptionLabel: null,
        initialOptionLabels: @js($initialOptionLabels),
        initialState: @js($selectedKeys),
        isAutofocused: false,
        isDisabled: false,
        isHtmlAllowed: false,
        isMultiple: true,
        isReorderable: false,
        isSearchable: true,
        livewireId: @js($this->getId()),
        loadingMessage: 'Загрузка...',
        maxItems: null,
        maxItemsMessage: '',
        noOptionsMessage: 'Пользователи не найдены',
        noSearchResultsMessage: 'Пользователи не найдены',
        options: @js($options),
        optionsLimit: 1000,
        placeholder: 'Выберите пользователей',
        position: null,
        searchDebounce: 300,
        searchingMessage: 'Поиск...',
        searchPrompt: 'Введите имя пользователя',
        searchableOptionFields: ['label'],
        state: @js($selectedKeys),
        statePath: 'responsibleMappingUsers.{{ $record->getKey() }}',
    })"
    x-init="$watch('state', (value) => $wire.updateResponsibleMappingUsers(@js($record->getKey()), value ?? []))"
    wire:ignore
    x-on:keydown.esc="select.dropdown.isActive && $event.stopPropagation()"
    class="fi-select-input min-w-80"
>
    <div x-ref="select"></div>
</div>
