<label class="workflow-list-header-filter" x-on:click.stop>
    <span class="workflow-list-header-filter__label">Группа</span>
    <select
        class="workflow-list-header-filter__select"
        wire:model.live="tableFilters.group_name.value"
        aria-label="Фильтр по группе процессов"
    >
        @foreach($options as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
    </select>
</label>
