<div class="workflow-expression-picker" wire:key="workflow-expression-sources-{{ md5(json_encode($sources)) }}" x-data="workflowExpressionPicker(@js($sources))"
    x-on:focusin.window="rememberField($event)" x-on:input.window="updatePreview($event)">
    <div class="workflow-expression-picker__row">
    <select class="workflow-expression-picker__select" style="flex: 1 1 auto; width: 0" x-model="selectedId" x-on:change="limit = 30; expanded = []; collapsedJson = []; preview = null" aria-label="Шаг с данными">
        @foreach($sources as $source)<option value="{{ $source['id'] }}">{{ $source['name'] }}</option>@endforeach
    </select>
    <button class="workflow-expression-picker__insert" type="button" x-show="selectedSource?.fields.length" x-on:mousedown.prevent x-on:click="insert(selectedSource.fields[0])" aria-label="Подставить весь результат шага" title="Подставить весь результат шага"><x-filament::icon icon="heroicon-m-plus" class="h-3 w-3"/></button>
    </div>
    <div class="workflow-expression-picker__content">
        <div class="workflow-input-json" aria-label="JSON данных шага">
            <template x-for="row in jsonRows" :key="row.id">
                <div class="workflow-input-json__line" :style="'padding-left:' + row.depth * 14 + 'px'">
                    <button type="button" class="workflow-input-json__fold" x-show="row.branch" x-on:mousedown.prevent x-on:click="toggleJson(row.item)" :aria-expanded="!row.collapsed" :aria-label="row.collapsed ? 'Развернуть' : 'Свернуть'" x-text="row.collapsed ? '›' : '⌄'"></button>
                    <button type="button" class="workflow-input-json__value" x-show="row.item" x-on:mousedown.prevent x-on:click="insert(row.item)" :title="row.item ? 'Подставить: ' + row.item.expression : ''" draggable="true" x-on:dragstart="if(row.item) $event.dataTransfer.setData('application/workflow-expression', JSON.stringify(row.item))"><span class="workflow-input-json__key" x-text="row.label"></span><span :class="'workflow-input-json__' + row.type" x-text="row.text"></span></button>
                    <span class="workflow-input-json__closing" x-show="!row.item" x-text="row.text"></span>
                </div>
            </template>
        </div>
    </div>
    <small class="workflow-expression-picker__hint" x-show="!selectedSource?.available">Нет результата запуска. Показана структура данных.</small>
    <small class="workflow-expression-picker__hint" x-show="selectedSource?.truncated">Показана часть данных. Подстановка передаст значение целиком.</small>
    <small class="workflow-expression-picker__hint" role="status" x-show="preview === 'Скопировано'">Скопировано</small>
</div>
