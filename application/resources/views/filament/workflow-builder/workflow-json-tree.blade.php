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
    <div class="workflow-json-tree__actions">
        <button type="button" x-on:click="collapseAll()">Свернуть всё</button>
        <button type="button" x-on:click="expandAll()">Развернуть всё</button>
    </div>
    <div class="workflow-input-json" aria-label="JSON">
        <template x-for="row in rows" :key="row.id">
            <div class="workflow-input-json__line" :style="'padding-left:' + row.depth * 14 + 'px'">
                <button
                    type="button"
                    class="workflow-input-json__fold"
                    x-show="row.branch"
                    x-on:click="toggle(row.path)"
                    :aria-expanded="!row.collapsed"
                    :aria-label="row.collapsed ? 'Развернуть уровень' : 'Свернуть уровень'"
                    x-text="row.collapsed ? '›' : '⌄'"
                ></button>
                <span class="workflow-input-json__key" x-text="row.label"></span><span :class="'workflow-input-json__' + row.type" x-text="row.text"></span>
            </div>
        </template>
    </div>
</div>
