<div class="workflow-value-field__multiple">
    <div class="workflow-value-field__selected" x-show="selectedOptionValues().length">
        <template x-for="value in selectedOptionValues()" :key="String(value)">
            <span class="workflow-value-field__selected-value">
                <span x-text="options[value] ?? value"></span>
                <button type="button" x-on:click="removeSelectedOption(value)" :aria-label="'Убрать ' + (options[value] ?? value)" @disabled($disabled ?? false)>
                    <x-filament::icon icon="heroicon-o-x-mark" class="h-3 w-3"/>
                </button>
            </span>
        </template>
    </div>
    <x-filament::input.wrapper>
        <select class="fi-input workflow-value-field__select"
            @if(isset($selectId)) id="{{ $selectId }}" aria-label="{{ $selectLabel }}" @else :id="$id('local-value')" :aria-label="label" @endif
            x-on:change="addSelectedOption($event.target.value); $event.target.value = ''" @disabled($disabled ?? false)>
            <option value="">Выберите значение</option>
            <template x-for="(option, value) in options" :key="value">
                <option :value="value" :disabled="hasSelectedOption(value)" x-text="option"></option>
            </template>
        </select>
    </x-filament::input.wrapper>
</div>
