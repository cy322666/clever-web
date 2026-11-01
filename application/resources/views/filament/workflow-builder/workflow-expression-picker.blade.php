@php($customFieldSuggestions = \App\Services\Workflows\WorkflowExpressionCatalog::customFieldSuggestions())
<div class="workflow-expression-picker" wire:key="workflow-expression-sources-{{ md5(json_encode([$sources, $customFieldSuggestions])) }}" x-data="workflowExpressionPicker({!! $sourcesExpression ?? \Illuminate\Support\Js::from($sources)->toHtml() !!}, @js($customFieldSuggestions))"
    x-on:focusin.window="rememberField($event)" x-on:input.window="updatePreview($event)">
    <div class="workflow-expression-picker__row">
    <select class="workflow-expression-picker__select" style="flex: 1 1 auto; width: 0" x-model="selectedId" x-on:change="limit = 30; expanded = []; collapsedJson = []; preview = null" aria-label="Шаг с данными">
        @isset($sourcesExpression)
            <template x-for="source in sources" :key="source.id"><option :value="source.id" x-text="source.name"></option></template>
        @else
            @foreach($sources as $source)<option value="{{ $source['id'] }}">{{ $source['name'] }}</option>@endforeach
        @endisset
    </select>
    <button class="workflow-expression-picker__insert" type="button" x-show="selectedSource?.fields.length" x-on:mousedown.prevent x-on:click="insert(selectedSource.fields[0])" aria-label="Подставить весь результат шага" title="Подставить весь результат шага"><x-filament::icon icon="heroicon-m-plus" class="h-3 w-3"/></button>
    </div>
    <div class="workflow-expression-picker__content">
        <div class="workflow-input-json" aria-label="JSON данных шага">
            <template x-for="row in jsonRows" :key="row.id">
                <div class="workflow-input-json__line" :style="'padding-left:' + row.depth * 14 + 'px'">
                    <button type="button" class="workflow-input-json__value" x-show="row.item" x-on:mousedown.prevent
                        x-on:click="if (row.branch) toggleJson(row.item); else if (row.copyable) copyValue(row.value, row.id, $event.currentTarget); else if (row.insertable) insert(row.item)"
                        :aria-expanded="row.branch ? !row.collapsed : null" :class="{ 'is-copied': copiedRow === row.id }"
                        :disabled="!row.branch && !row.copyable && !row.insertable" :title="row.branch ? (row.collapsed ? 'Развернуть' : 'Свернуть') : row.insertable ? 'Подставить переменную' : (copiedRow === row.id ? 'Скопировано' : 'Скопировать значение')"
                        x-bind:draggable="!!row.item?.expression" x-on:dragstart="if(row.item?.expression) $event.dataTransfer.setData('application/workflow-expression', JSON.stringify(row.item))"><span class="workflow-json-tree__caret" aria-hidden="true" x-show="row.branch" x-text="row.collapsed ? '›' : '⌄'"></span><span class="workflow-input-json__key" x-text="row.label"></span><span :class="'workflow-input-json__' + row.type" x-text="row.text"></span></button>
                    <span class="workflow-input-json__closing" x-show="!row.item" x-text="row.text"></span>
                </div>
            </template>
        </div>
    </div>
    <small class="workflow-expression-picker__hint" x-show="!selectedSource?.available">Нет результата запуска. Показана структура данных.</small>
    <small class="workflow-expression-picker__hint" x-show="selectedSource?.truncated">Показана часть данных. Подстановка передаст значение целиком.</small>
    <small class="workflow-expression-picker__hint" role="status" x-show="preview === 'Скопировано'">Скопировано</small>
    <small role="status" x-show="copyError" x-text="copyError" x-cloak></small>
    @include('filament.workflow-builder.workflow-copy-feedback')
</div>
