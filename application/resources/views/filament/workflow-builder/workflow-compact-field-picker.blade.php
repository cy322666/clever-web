<div x-data="workflowCompactFieldPicker()" x-on:workflow-compact-picker-open.window="if ($el.closest('.workflow-inspector')?.contains($event.detail.anchor)) openCompactPicker($event.detail)"
    x-on:workflow-compact-picker-position.window="if (pickerOpen && $el.closest('.workflow-inspector') === $event.target) positionCompactPicker()"
    x-on:workflow-compact-picker-dismiss.window="if (pickerOpen && $el.closest('.workflow-inspector') === $event.target) closeCompactPicker()"
    x-on:workflow-inspector-state.window="if (pickerOpen) closeCompactPicker()">
    <template x-teleport="body">
        <section x-ref="compactPicker" class="workflow-compact-picker" role="dialog" aria-label="Вставить поле"
            x-show="pickerOpen" x-cloak :style="pickerPosition" x-on:keydown="compactPickerKey($event)">
            <header class="workflow-compact-picker__head">
                <strong>Вставить поле</strong>
                <button type="button" data-workflow-picker-close aria-label="Закрыть выбор поля" x-on:click="closeCompactPicker(true)">
                    <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4"/>
                </button>
            </header>
            <label class="workflow-compact-picker__search">
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="h-4 w-4"/>
                <input type="search" x-ref="pickerSearch" x-model="pickerQuery" x-on:input="pickerIndex = 0"
                    placeholder="Найти поле…" aria-label="Найти поле" autocomplete="off">
            </label>
            <label class="workflow-compact-picker__source">
                <span>Источник:</span>
                <select x-model="pickerSourceId" x-on:change="pickerIndex = 0" aria-label="Источник данных">
                    <template x-for="source in pickerSources" :key="source.id">
                        <option :value="source.id" x-text="source.name"></option>
                    </template>
                </select>
            </label>
            <ul class="workflow-compact-picker__list" aria-label="Доступные поля">
                <template x-for="(item, index) in pickerFields" :key="item.expression">
                    <li>
                        <button type="button" :data-workflow-picker-row="index" :class="{'is-current': index === pickerIndex}"
                            :title="item.displayPath || item.expression" x-on:focus="pickerIndex = index" x-on:click="chooseCompactField(item)">
                            <span x-text="item.label"></span>
                            <span class="workflow-compact-picker__path" x-show="item.displayPath?.includes('.') || item.displayPath?.includes('[')" x-text="item.displayPath"></span>
                        </button>
                    </li>
                </template>
            </ul>
            <p x-show="!pickerFields.length" class="workflow-compact-picker__empty" role="status">Поля не найдены</p>
            <button type="button" class="workflow-compact-picker__edit" data-workflow-picker-edit
                x-show="Boolean(pickerEditExpression)" x-on:click="editCompactExpression()">Ввести выражение</button>
        </section>
    </template>
</div>
