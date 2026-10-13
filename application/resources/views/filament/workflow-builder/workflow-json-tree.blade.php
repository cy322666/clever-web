@php
    $usesExpression = isset($expression) && is_string($expression) && $expression !== '';
@endphp

<div
    class="workflow-json-tree"
    @if($usesExpression)
        x-data="workflowJsonViewer(null)"
        x-effect="setValue({!! $expression !!})"
    @else
        x-data="workflowJsonViewer(@js($value ?? null))"
    @endif
>
    @if(!empty($titleExpression) || !empty($title))
    <div class="workflow-json-tree__header">
        @if(!empty($titleExpression))
            <h3 class="workflow-json-tree__title" x-text="{!! $titleExpression !!}"></h3>
        @elseif(!empty($title))
            <h3 class="workflow-json-tree__title">{{ $title }}</h3>
        @endif
    </div>
    @endif
    <div class="workflow-json-tree__viewport" tabindex="0" role="region" aria-label="Данные JSON">
        <div class="workflow-input-json" aria-label="JSON">
            <template x-for="row in rows" :key="row.id">
                <div class="workflow-input-json__line" :style="'padding-left:' + row.depth * 16 + 'px'">
                    <button type="button" class="workflow-input-json__value" x-show="!row.closing"
                        x-on:click="row.branch ? toggle(row.path) : copyValue(row.value, row.id)"
                        :aria-expanded="row.branch ? !row.collapsed : null" :class="{ 'is-copied': copiedRow === row.id }"
                        :title="row.branch ? (row.collapsed ? 'Развернуть' : 'Свернуть') : (copiedRow === row.id ? 'Скопировано' : 'Скопировать значение')"><span class="workflow-json-tree__caret" aria-hidden="true" x-show="row.branch" x-text="row.collapsed ? '›' : '⌄'"></span><span class="workflow-input-json__key" x-text="row.label"></span><span :class="'workflow-input-json__' + row.type" x-text="row.text"></span></button>
                    <span class="workflow-input-json__closing" x-show="row.closing" x-text="row.text"></span>
                </div>
            </template>
        </div>
    </div>
    <small role="status" x-show="copyError" x-text="copyError" x-cloak></small>
</div>
