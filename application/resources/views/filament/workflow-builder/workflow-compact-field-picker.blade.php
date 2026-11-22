<div x-data="workflowCompactFieldPicker()" x-id="['picker-source']" x-on:workflow-compact-picker-open.window="if ($el.closest('.workflow-inspector')?.contains($event.detail.anchor)) openCompactPicker($event.detail)"
    x-on:workflow-compact-picker-position.window="if (pickerOpen && $el.closest('.workflow-inspector') === $event.target) positionCompactPicker()"
    x-on:workflow-compact-picker-dismiss.window="if (pickerOpen && $el.closest('.workflow-inspector') === $event.target) closeCompactPicker()"
    x-on:workflow-inspector-state.window="if (pickerOpen) closeCompactPicker()">
    <template x-teleport="body">
        <section x-ref="compactPicker" class="workflow-compact-picker" role="dialog" :aria-label="'Данные для поля '+(pickerTarget.label || 'значение')"
            x-show="pickerOpen" x-cloak :style="pickerPosition" x-on:keydown="compactPickerKey($event)">
            <header class="workflow-compact-picker__head">
                <label class="workflow-compact-picker__search">
                    <x-filament::icon icon="heroicon-m-magnifying-glass" class="h-4 w-4"/>
                    <input type="search" x-ref="pickerSearch" x-model="pickerQuery" x-on:input="resetPickerSearch()"
                        placeholder="Найти поле или значение" aria-label="Найти поле" autocomplete="off">
                </label>
                <button type="button" data-workflow-picker-close aria-label="Закрыть выбор поля" x-on:click="closeCompactPicker(true)">
                    <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4"/>
                </button>
            </header>
            <ul class="workflow-compact-picker__groups" aria-label="Поля предыдущих шагов">
                <template x-for="group in pickerGroups" :key="group.id">
                    <li class="workflow-compact-picker__group">
                        <button type="button" class="workflow-compact-picker__group-toggle" data-workflow-picker-group
                            :aria-expanded="group.expanded" :aria-controls="$id('picker-source')+'-'+group.id" x-on:click="togglePickerSource(group.id)">
                            <x-filament::icon icon="heroicon-m-chevron-right" class="workflow-compact-picker__chevron" x-bind:class="{'is-expanded':group.expanded}"/>
                            <span x-text="group.name"></span>
                        </button>
                        <div x-show="group.expanded" :id="$id('picker-source')+'-'+group.id">
                            <p class="workflow-compact-picker__hint" x-show="!group.hasExamples && group.available === false">Примеры появятся после теста шага</p>
                            <ul class="workflow-compact-picker__list">
                                <template x-for="item in group.fields" :key="item.expression">
                                    <li>
                                        <button type="button" :data-workflow-picker-row="item.index" :class="{'is-current':item.index === pickerIndex}"
                                            :title="item.preview || item.label" x-on:focus="pickerIndex=item.index" x-on:click="chooseCompactField(item)">
                                            <span class="workflow-compact-picker__field-copy"><span x-text="item.label"></span><small x-show="item.detail" x-text="item.detail"></small></span>
                                            <span class="workflow-compact-picker__preview" x-show="item.preview !== ''" x-text="item.preview"></span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                            <button type="button" class="workflow-compact-picker__more" data-workflow-picker-more x-show="group.hasMore"
                                x-on:click="showAllPickerFields(group.id)" x-text="'Все поля ('+group.count+')'"></button>
                        </div>
                    </li>
                </template>
            </ul>
            <div class="workflow-compact-picker__empty" x-show="!pickerGroups.length" role="status">
                <strong x-text="pickerQuery.trim() ? 'Ничего не найдено' : pickerTarget.choices ? 'Нет подходящих полей' : 'Пока нет доступных полей'"></strong>
                <span x-text="pickerQuery.trim() ? 'Попробуйте другое название или значение.' : pickerTarget.choices ? 'Выберите тип в самом поле или протестируйте предыдущий шаг.' : pickerEmptyHint"></span>
            </div>
            <button type="button" class="workflow-compact-picker__edit" data-workflow-picker-edit
                x-show="Boolean(pickerEditExpression)" x-on:click="editCompactExpression()">Ввести свою формулу</button>
        </section>
    </template>
</div>
