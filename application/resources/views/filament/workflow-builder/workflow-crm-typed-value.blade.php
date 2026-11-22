<template x-if="mode === 'value' && crmControl">
    <x-filament::input.wrapper>
        <template x-if="crmControl.kind === 'boolean'">
            <select class="fi-input workflow-value-field__select" :id="$id('local-value')" :aria-label="label" :value="crmLiteral" x-on:change="writeCrmLiteral($event.target.value)">
                <option value="">Не задано</option>
                <option value="no" :selected="crmLiteral === 'no'">Нет</option>
                <option value="yes" :selected="crmLiteral === 'yes'">Да</option>
            </select>
        </template>
        <template x-if="crmControl.kind === 'choice'">
            <select class="fi-input workflow-value-field__select" :id="$id('local-value')" :aria-label="label" :value="crmLiteral" x-on:change="writeCrmLiteral($event.target.value)">
                <option value="">Выберите значение</option>
                <template x-if="crmLiteral !== '' && !crmControl.options.includes(crmLiteral)">
                    <option :value="crmLiteral" x-text="crmLiteral" selected></option>
                </template>
                <template x-for="option in crmControl.options" :key="option">
                    <option :value="option" :selected="option === crmLiteral" x-text="option"></option>
                </template>
            </select>
        </template>
        <template x-if="crmControl.kind === 'dictionary'">
            <select class="fi-input workflow-value-field__select" :id="$id('local-value')" :aria-label="label" :value="crmLiteral" x-on:change="writeCrmLiteral($event.target.value)">
                <option value="">Выберите значение</option>
                <template x-if="crmLiteral !== '' && !Object.hasOwn(crmControl.options, crmLiteral)">
                    <option :value="crmLiteral" x-text="crmLiteral" selected></option>
                </template>
                <template x-for="(name, id) in crmControl.options" :key="id">
                    <option :value="id" :selected="String(id) === String(crmLiteral)" x-text="name"></option>
                </template>
            </select>
        </template>
        <template x-if="crmControl.kind === 'multiple'">
            <select class="fi-input" multiple :size="Math.min(4, Math.max(2, crmControl.options.length))" :id="$id('local-value')" :aria-label="label"
                x-on:change="writeCrmLiteral(Array.from($event.target.selectedOptions, option => option.value))">
                <template x-for="option in [...new Set([...crmControl.options, ...crmLiteral])]" :key="option">
                    <option :value="option" :selected="crmLiteral.includes(option)" x-text="option"></option>
                </template>
            </select>
        </template>
        <template x-if="crmControl.kind === 'textarea'">
            <textarea class="fi-input" rows="3" :id="$id('local-value')" :aria-label="label" :value="crmLiteral"
                x-on:input="writeCrmLiteral($event.target.value)"></textarea>
        </template>
        <template x-if="['number','date','datetime-local','url'].includes(crmControl.kind)">
            <input class="fi-input" :type="crmControl.kind" step="any" :id="$id('local-value')" :aria-label="crmControl.kind === 'datetime-local' ? label + ' (UTC)' : label" :value="crmLiteral" :placeholder="placeholder"
                :title="crmControl.kind === 'datetime-local' ? 'Время UTC' : null"
                x-on:input="if (!$event.target.validity.badInput) writeCrmLiteral($event.target.value)" />
        </template>
    </x-filament::input.wrapper>
</template>
