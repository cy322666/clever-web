@php
    $options = $getValueOptions();
    $hasOptions = $hasValueOptions();
    $path = $getStatePath();
    $state = $getState();
    $optionState = is_bool($state) ? ($state ? '1' : '0') : (is_scalar($state) ? trim((string) $state) : '');
    $initialMode = is_string($state) && str_contains($state, '{{') ? 'expression' : 'value';
    $expressionPlaceholder = '{{ $json.id }}';
    $filterItemsPath = $isRelativePath() && preg_match('/^(.*)\.rules\.[^.]+\.field$/', $path, $matches) ? $matches[1].'.items' : null;
    $collectionRulesPath = $isCollectionPath() && preg_match('/^(.*)\.items$/', $path, $matches) ? $matches[1].'.rules' : null;
@endphp
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field" :label="''">
    <div class="workflow-value-field" wire:key="value-{{ md5($path.json_encode($options)) }}"
        x-data="workflowValueField($wire.{{ $applyStateBindingModifiers('$entangle(\'' . $path . '\')') }}, @js($filterItemsPath), @js($collectionRulesPath), @js($options))"
        x-on:workflow-expression-field="if (!@js($isRelativePath())) { mode = 'expression'; $nextTick(() => $refs.value.focus()); }"
        x-on:workflow-expression-suggestions="sources = $event.detail.sources; customFields = $event.detail.customFields || []; suggest({target: $refs.value})"
        x-on:resize.window="if (suggestions.length) positionSuggestions()"
            x-on:scroll.window.capture="if (suggestions.length) positionSuggestions()"
            x-on:workflow-compact-picker-position.window="if (suggestions.length) positionSuggestions()"
            x-on:workflow-inspector-state.window="suggestions=[]"
            x-on:input="suggest($event)" x-on:keydown="suggestionKey($event)"
        x-on:click="if ($event.target === $refs.value) suggest($event)"
        x-on:keyup="if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes($event.key)) suggest($event)"
        x-on:click.outside="suggestions = []">
        <div @class(['workflow-value-field__header', 'workflow-value-field__header--unlabeled' => $isLabelHidden()])>
        @unless($isLabelHidden())
        <label :for="mode === 'value' && @js($hasOptions) ? @js($getId()) : @js($getId().'-value')">{{ $getLabel() }}@if($isRequired())<span aria-hidden="true"> *</span>@endif</label>
        @endunless
        @unless($isRelativePath() || $isMultiple())<div class="workflow-value-field__modes" role="group" aria-label="Способ задания значения">
            <button type="button" :aria-pressed="mode === 'expression'" aria-label="Вставить поле" title="Вставить поле"
                x-on:click="mode = mode === 'expression' ? 'value' : 'expression'; $nextTick(() => { if (mode === 'expression') $refs.value.focus() })">{}</button>
        </div>@endunless
        </div>
        @if ($isMultiple())
            @include('forms.components.workflow-multiple-options', ['selectId' => $getId(), 'selectLabel' => $getLabel(), 'disabled' => $isDisabled()])
        @elseif ($hasOptions)
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
        <x-filament::input.wrapper :x-show.important="'!'.($isMultiple() ? 'true' : 'false').' && (mode === \'expression\' || ' . ($hasOptions ? 'false' : 'true').')'" :valid="! $errors->has($path)">
            @if($getTextareaRows())
            <textarea x-ref="value" x-model="state" data-workflow-value-input class="fi-input" rows="{{ $getTextareaRows() }}" id="{{ $getId() }}-value" @disabled($isDisabled()) aria-label="{{ $getLabel() }}" placeholder="{{ $getPlaceholder() }}" x-on:dragover.prevent x-on:drop.prevent="const raw = $event.dataTransfer.getData('application/workflow-expression'); if (raw) { state = JSON.parse(raw).expression; mode = 'expression'; }"></textarea>
            @else
            <input x-ref="value" x-model="state" data-workflow-value-input @if($isRelativePath()) data-workflow-filter-item-field @endif class="fi-input"
                id="{{ $getId() }}-value" :type="mode === 'expression' ? 'text' : @js($getType())" @disabled($isDisabled())
                :inputmode="mode === 'expression' ? 'text' : @js($getInputMode())" step="{{ $getStep() }}"
                {{ $getExtraInputAttributeBag() }}
                x-on:input="clampNumber($event)"
                list="{{ $getId() }}-suggestions"
                :placeholder="mode === 'expression' ? @js($expressionPlaceholder) : @js($getPlaceholder())"
                aria-label="{{ $getLabel() }}"
                x-on:dragover.prevent
                x-on:drop.prevent="const raw = $event.dataTransfer.getData('application/workflow-expression'); if (raw && !@js($isRelativePath())) { state = JSON.parse(raw).expression; mode = 'expression'; $refs.value.focus(); }"
            />
            <datalist id="{{ $getId() }}-suggestions">@foreach($getValueSuggestions() as $suggestion)<option value="{{ $suggestion }}"></option>@endforeach</datalist>
            @endif
        </x-filament::input.wrapper>
        <template x-teleport="body">
            <div class="workflow-value-suggestions" x-ref="suggestionList" :style="suggestionPosition" x-on:pointerdown.stop x-on:click.stop x-on:keydown="suggestionKey($event)" x-show="suggestions.length" x-cloak role="listbox" aria-label="Подсказки переменных">
            <template x-for="(item, index) in suggestions" :key="item.expression">
                <button type="button" role="option" :aria-selected="index === suggestionIndex"
                    x-on:mousedown.prevent x-on:click="chooseSuggestion(item)">
                    <span x-text="item.source + ' · ' + (item.label || item.path)"></span>
                    <code x-text="item.expression"></code>
                </button>
            </template>
        </div>
            </template>
    </div>
</x-dynamic-component>
