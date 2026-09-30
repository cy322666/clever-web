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
    <div class="workflow-json-tree__header">
        @if(!empty($titleExpression))
            <h3 class="workflow-json-tree__title" x-text="{!! $titleExpression !!}"></h3>
        @elseif(!empty($title))
            <h3 class="workflow-json-tree__title">{{ $title }}</h3>
        @endif
        <div class="workflow-json-tree__actions" role="group" aria-label="Управление уровнями JSON">
            <button type="button" x-on:click="collapseAll()">Свернуть всё</button>
            <button type="button" x-on:click="expandAll()">Развернуть всё</button>
        </div>
    </div>
    <div class="workflow-json-tree__viewport" tabindex="0" role="region" aria-label="Данные JSON">
        <div class="workflow-input-json" aria-label="JSON">
            <template x-for="row in rows" :key="row.id">
                <div class="workflow-input-json__line" :style="'padding-left:' + row.depth * 16 + 'px'">
                    <span class="workflow-json-tree__toggle">
                        <button
                            type="button"
                            class="workflow-input-json__fold"
                            x-show="row.branch"
                            x-on:click="toggle(row.path)"
                            :aria-expanded="!row.collapsed"
                            :aria-label="row.collapsed ? 'Развернуть уровень' : 'Свернуть уровень'"
                        ><svg viewBox="0 0 16 16" aria-hidden="true" :class="{ 'is-expanded': !row.collapsed }"><path d="m6 4 4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                    </span>
                    <span class="workflow-json-tree__content"><span class="workflow-input-json__key" x-text="row.label"></span><span :class="'workflow-input-json__' + row.type" x-text="row.text"></span></span>
                </div>
            </template>
        </div>
    </div>
</div>
