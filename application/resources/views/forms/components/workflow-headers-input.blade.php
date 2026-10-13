<x-dynamic-component :component="$getFieldWrapperView()" :field="$field" :label="''">
    <div class="workflow-headers-field" x-data="workflowHeadersField($wire.{{ $applyStateBindingModifiers('$entangle(\'' . $getStatePath() . '\')') }})">
        <div class="workflow-value-field__header">
            <span>{{ $getLabel() }}</span>
            <div class="workflow-value-field__modes" role="group" aria-label="Формат заголовков">
                <button type="button" :aria-pressed="mode === 'fields'" x-on:click="setMode('fields')">Поля</button>
                <button type="button" :aria-pressed="mode === 'json'" x-on:click="setMode('json')">JSON</button>
            </div>
        </div>
        <div x-show="mode === 'fields'" class="workflow-headers-field__rows">
            <template x-for="row in rows" :key="row.id">
                <div class="workflow-headers-field__row">
                    <label>Название<x-filament::input.wrapper><input class="fi-input" x-model="row.name" x-on:input="syncRows()" placeholder="Authorization" aria-label="Название заголовка" /></x-filament::input.wrapper></label>
                    <label>Значение<x-filament::input.wrapper><input class="fi-input" x-model="row.value" x-on:input="syncRows()" data-workflow-value-input placeholder="Значение или переменная" aria-label="Значение заголовка" x-on:dragover.prevent x-on:drop.prevent="const raw = $event.dataTransfer.getData('application/workflow-expression'); if (raw) { row.value = JSON.parse(raw).expression; syncRows(); }" /></x-filament::input.wrapper></label>
                    <button type="button" class="workflow-headers-field__remove" aria-label="Удалить заголовок" x-on:click="removeRow(row.id)"><x-filament::icon icon="heroicon-o-trash" class="h-4 w-4" /></button>
                </div>
            </template>
            <button type="button" class="workflow-headers-field__add" x-on:click="addRow()">Добавить заголовок</button>
        </div>
        <div x-show="mode === 'json'" x-cloak>
            <x-filament::input.wrapper><textarea class="fi-input" rows="4" x-model="raw" x-on:input="syncJson()" data-workflow-value-input aria-label="Заголовки JSON" placeholder='{"Accept": "application/json"}'></textarea></x-filament::input.wrapper>
        </div>
        <small role="status" x-show="error" x-text="error"></small>
    </div>
</x-dynamic-component>
