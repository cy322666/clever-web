@php
    $options = $getValueOptions();
    $hasOptions = $hasValueOptions();
    $path = $getStatePath();
    $state = $getState();
    $optionState = is_bool($state) ? ($state ? '1' : '0') : (is_scalar($state) ? trim((string) $state) : '');
    $initialMode = is_string($state) && str_contains($state, '{{') ? 'expression' : 'value';
    $expressionPlaceholder = '{{ $json.id }}';
@endphp
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field" :label="''">
    <div class="workflow-value-field" wire:key="value-{{ md5($path.json_encode($options)) }}"
        x-data="workflowValueField($wire.{{ $applyStateBindingModifiers('$entangle(\'' . $path . '\')') }})"
        x-on:workflow-expression-field="mode = 'expression'; $nextTick(() => $refs.value.focus())"
        x-on:workflow-expression-suggestions="sources = $event.detail.sources"
        x-on:input="suggest($event)" x-on:keydown="suggestionKey($event)"
        x-on:click.outside="suggestions = []">
        <div class="workflow-value-field__header">
        <label :for="mode === 'value' && @js($hasOptions) ? @js($getId()) : @js($getId().'-value')">{{ $getLabel() }}@if($isRequired())<span aria-hidden="true"> *</span>@endif</label>
        <div class="workflow-value-field__modes" role="group" aria-label="Способ задания значения">
            <button type="button" :aria-pressed="mode === 'value'" x-on:click="mode = 'value'; if (String(state ?? '').includes('{' + '{')) state = ''">Значение</button>
            <button type="button" :aria-pressed="mode === 'expression'" x-on:click="mode = 'expression'; $nextTick(() => $refs.value.focus())">Переменная</button>
        </div>
        </div>
        @if ($hasOptions)
            <x-filament::input.wrapper x-show.important="mode === 'value'" :valid="! $errors->has($path)">
                <select class="fi-input workflow-value-field__select" x-model="state" id="{{ $getId() }}" @disabled($isDisabled()) aria-label="{{ $getLabel() }}">
                    <option value="">{{ $options ? ($getPlaceholder() ?: 'Выберите значение') : 'Нет доступных вариантов — обновите справочник' }}</option>
                    @if($optionState !== '' && !str_contains($optionState, '{{') && !array_key_exists($optionState, $options))
                        <option value="{{ $optionState }}">{{ $optionState }}</option>
                    @endif
                    @foreach ($options as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </x-filament::input.wrapper>
        @endif
        <x-filament::input.wrapper :x-show.important="'mode === \'expression\' || ' . ($hasOptions ? 'false' : 'true')" :valid="! $errors->has($path)">
            @if($getTextareaRows())
            <textarea x-ref="value" x-model="state" data-workflow-value-input class="fi-input" rows="{{ $getTextareaRows() }}" id="{{ $getId() }}-value" @disabled($isDisabled()) aria-label="{{ $getLabel() }}" placeholder="{{ $getPlaceholder() }}" x-on:dragover.prevent x-on:drop.prevent="const raw = $event.dataTransfer.getData('application/workflow-expression'); if (raw) { state = JSON.parse(raw).expression; mode = 'expression'; }"></textarea>
            @else
            <input x-ref="value" x-model="state" data-workflow-value-input class="fi-input"
                id="{{ $getId() }}-value" :type="mode === 'expression' ? 'text' : @js($getType())" @disabled($isDisabled())
                :inputmode="mode === 'expression' ? 'text' : @js($getInputMode())" step="{{ $getStep() }}"
                {{ $getExtraInputAttributeBag() }}
                list="{{ $getId() }}-suggestions"
                :placeholder="mode === 'expression' ? @js($expressionPlaceholder) : @js($getPlaceholder())"
                aria-label="{{ $getLabel() }}"
                x-on:dragover.prevent
                x-on:drop.prevent="const raw = $event.dataTransfer.getData('application/workflow-expression'); if (raw) { state = JSON.parse(raw).expression; mode = 'expression'; $refs.value.focus(); }"
            />
            <datalist id="{{ $getId() }}-suggestions">@foreach($getValueSuggestions() as $suggestion)<option value="{{ $suggestion }}"></option>@endforeach</datalist>
            @endif
        </x-filament::input.wrapper>
        <div class="workflow-value-suggestions" x-show="suggestions.length" x-cloak role="listbox" aria-label="Подсказки переменных">
            <template x-for="(item, index) in suggestions" :key="item.expression">
                <button type="button" role="option" :aria-selected="index === suggestionIndex"
                    x-on:mousedown.prevent x-on:click="chooseSuggestion(item)">
                    <span x-text="item.source + ' · ' + (item.label || item.path)"></span>
                    <code x-text="item.expression"></code>
                </button>
            </template>
        </div>
    </div>
</x-dynamic-component>
