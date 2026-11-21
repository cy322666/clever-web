<div class="workflow-trigger-reference" x-show="local?.triggerReference()" x-data="{collapsed:{}, activeId:null, activeType:null}"
    x-effect="if (activeId !== local?.id || activeType !== local?.type) { activeId=local?.id; activeType=local?.type; collapsed={}; }">
    <div class="workflow-trigger-reference__label">Событие
        <button type="button" class="workflow-trigger-reference__event"
            x-on:click="workflowEditorCall($wire, 'beginTriggerReplace', local.id)" aria-label="Сменить тип события">
            <span x-text="local?.triggerReference()?.label || local?.node()?.name || 'Данные запуска'"></span>
            <x-filament::icon icon="heroicon-o-chevron-down" class="h-4 w-4"/>
        </button>
    </div>
    <div class="workflow-trigger-reference__label">Структура тела события</div>
    <ul class="workflow-trigger-reference__body" aria-label="Структура тела события">
        <template x-for="row in (local?.triggerReferenceRows(collapsed) || [])" :key="row.key">
            <li :style="{paddingInlineStart: (row.depth * 15) + 'px'}">
                <template x-if="row.branch">
                    <button type="button" :aria-expanded="row.expanded" x-on:click="collapsed={...collapsed,[row.key]:row.expanded}">
                        <span aria-hidden="true" x-text="row.expanded ? '⌄' : '›'"></span><code x-text="row.label"></code>
                    </button>
                </template>
                <template x-if="!row.branch"><code class="workflow-trigger-reference__leaf" x-text="row.label"></code></template>
            </li>
        </template>
    </ul>
</div>
