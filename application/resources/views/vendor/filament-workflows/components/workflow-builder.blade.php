@props([
    'submitLabel' => __('filament-workflows::workflows.actions.save_changes.label'),
    'pageMode' => false,
])

@once
    {{-- Fetch the form widget with the editor, not after a node is opened. --}}
    <link rel="modulepreload" href="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('select', 'filament/forms') }}" />
@endonce

@php
    $maskGroups = \App\Workflows\Actions\WorkflowTriggerConditionVariableCatalog::groupedConditionPickerOptions(false);
    $systemIdGroups = \App\Workflows\Actions\WorkflowTriggerConditionVariableCatalog::systemIdGroups();

    try {
        $workflowRecord = method_exists($this, 'getRecord') ? $this->getRecord() : null;
    } catch (\Throwable) {
        $workflowRecord = null;
    }

    $isReplay = method_exists($this, 'isWorkflowReplay') && $this->isWorkflowReplay();
    $workflowActionItems = collect($this->workflowActions)->values();
    $workflowStarts = \App\Services\Workflows\WorkflowStartNodes::all(array_merge($this->definition, ['trigger' => $this->trigger]));
    $workflowActionsCount = $workflowActionItems->count();
    $workflowTriggerOptions = app(\Leek\FilamentWorkflows\Triggers\TriggerRegistry::class)->getAllWithMetadata();
    $workflowActionOptions = method_exists($this, 'getInlineWorkflowActionOptions')
        ? $this->getInlineWorkflowActionOptions()
        : [];
    $workflowTitle = trim((string) ($this->data['name'] ?? $workflowRecord?->name ?? '')) ?: 'Новый процесс';
    $workflowLayoutKey = 'clever.workflow.layout.v2:' . ($workflowRecord?->getKey() ?? ('draft:' . $this->getId()));
    if ($isReplay) $workflowLayoutKey = 'clever.workflow.layout.v2:replay:' . $this->replayRunId;
    $triggerMetadata = $this->trigger
        ? $this->getTriggerMetadata($this->trigger['type'], $this->trigger['config'] ?? [])
        : null;
@endphp

<div
    x-data="workflowWorkbench()"
    x-init="initWorkbench()"
    x-on:resize.window="syncEditorHeight()"
    @class([
        'workflow-workbench',
        'workflow-workbench--page' => $pageMode,
    ])
>
    @unless($isReplay)
        @include('filament.workflow-builder.workflow-draft-templates')
    @endunless
    {{-- Keep the poll directive mounted: Filament may update only the node modal.
         The expression sends no request while there is no active preview. --}}
    <span wire:poll.2s="$wire.nodePreviewTaskId && $wire.pollWorkflowNodePreview()" aria-hidden="true"></span>
    <div class="workflow-workbench__shell" x-data="workflowDebugPanel()" x-init="initDebugPanel()"
        x-on:workflow-debug-updated.window="$nextTick(() => updateDebugSelection())"
        x-on:workflow-debug-select.window="selectDebugNode($event.detail.id)">
        <div class="workflow-workbench__toolbar">
            <div class="workflow-workbench__toolbar-layout">
                <div class="workflow-workbench__identity">
                    <a href="{{ \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl('index') }}" class="workflow-workbench__quick-action" aria-label="Сценарии" title="К списку сценариев"><x-filament::icon icon="heroicon-o-arrow-left" class="h-5 w-5"/></a>
                    <div class="workflow-identity-inline" x-data="workflowInlineWorkflowName()" x-id="['workflow-name-error']"
                        x-on:pointerdown.outside.capture="commitRename()"
                        x-on:focusout="if (editing && !$el.contains($event.relatedTarget)) commitRename()"
                        x-on:submit.window.capture="if ($event.target.contains($el)) finishBeforeSave($event)">
                        <h1 class="workflow-workbench__identity-title" x-show="!editing"><button x-ref="title" type="button" title="Переименовать поток" x-text="workflowName" x-on:click="startRename()">{{ $workflowTitle }}</button></h1>
                        <input x-ref="name" x-model="nameDraft" x-show="editing" x-cloak maxlength="255" aria-label="Название потока"
                            x-bind:aria-invalid="!!renameError" x-bind:aria-describedby="renameError ? $id('workflow-name-error') : null"
                            x-on:input="updateRename($event.target.value)"
                            x-on:keydown.enter.stop="if (!$event.isComposing) { $event.preventDefault(); commitRename(); }"
                            x-on:keydown.escape.prevent.stop="cancelRename()" />
                        <small x-show="renameError" x-cloak x-text="renameError" x-bind:id="$id('workflow-name-error')" role="alert"></small>
                        @error('name')<small>{{ $message }}</small>@enderror
                        <div class="workflow-identity-tags" x-show="($wire.definition.tags || []).length" x-cloak><template x-for="tag in ($wire.definition.tags || []).slice(0,3)" :key="tag"><span x-text="tag"></span></template><span x-show="($wire.definition.tags || []).length > 3" x-text="'+' + (($wire.definition.tags || []).length - 3)"></span></div>
                    </div>
                    <details class="workflow-identity-popover" x-on:click.outside="$el.open=false">
                        <summary title="Теги потока" aria-label="Теги потока"><x-filament::icon icon="heroicon-o-tag" class="h-4 w-4"/></summary>
                        <div x-data="{ tag: '' }">
                            <template x-for="existingTag in ($wire.definition.tags || [])" :key="existingTag"><button type="button" class="workflow-tag-chip" x-on:click="workflowEditorCall($wire, 'setWorkflowTags', ($wire.definition.tags || []).filter(value => value !== existingTag))" title="Удалить тег" x-text="existingTag + ' ×'"></button></template>
                            <input x-model="tag" aria-label="Новый тег" placeholder="Добавить тег · Enter" maxlength="50" x-on:keydown.enter.prevent.stop="if(tag.trim()) { workflowEditorCall($wire, 'setWorkflowTags', [...($wire.definition.tags || []),tag.trim()]); tag=''; }" />
                            @error('tags')<small>{{ $message }}</small>@enderror
                        </div>
                    </details>
                    <details class="workflow-identity-popover" x-on:click.outside="$el.open=false">
                        <summary title="Папка потока" aria-label="Папка потока"><x-filament::icon icon="heroicon-o-folder" class="h-4 w-4"/><span x-text="$wire.data.group_name || ''">{{ $this->data['group_name'] ?? $workflowRecord?->group_name ?? '' }}</span></summary>
                        <div><select aria-label="Папка потока" x-on:change="workflowEditorCall($wire, 'setWorkflowFolder', $event.target.value)">
                            <option value="">Без папки</option>
                            @foreach($this->workflowEditorFolderOptions() as $folder)
                                <option value="{{ $folder }}" @selected(($this->data['group_name'] ?? $workflowRecord?->group_name) === $folder)>{{ $folder }}</option>
                            @endforeach
                        </select></div>
                    </details>
                </div>

                <div class="workflow-workbench__quick-actions">
                    <button type="button" class="workflow-workbench__quick-action" data-workflow-reference-toggle x-on:click="window.dispatchEvent(new CustomEvent('workflow-masks-open', { detail: { anchor: {x: $event.currentTarget.getBoundingClientRect().left - 280, y: $event.currentTarget.getBoundingClientRect().bottom + 8} } }))" aria-label="Справочники" title="Справочники и переменные"><x-filament::icon icon="heroicon-o-book-open" class="h-5 w-5"/></button>
                    @if(($this->nodePreviewTaskId ?? null) && empty($this->mountedActions))
                        @include('filament.workflow-builder.workflow-preview-progress', ['progress' => $this->nodePreviewProgress])
                    @endif
                    @if($workflowRecord && !$isReplay)
                        <button type="button" wire:click="toggleWorkflowActivation" wire:loading.attr="disabled" wire:target="toggleWorkflowActivation" class="workflow-workbench__quick-action workflow-workbench__quick-action--labeled" aria-pressed="{{ $workflowRecord->is_active ? 'true' : 'false' }}" title="{{ $workflowRecord->is_active ? 'Выключить поток' : 'Сохранить и включить поток' }}">
                            <x-filament::icon icon="heroicon-o-power" class="h-4 w-4"/><span>{{ $workflowRecord->is_active ? 'Выключить' : 'Включить' }}</span>
                        </button>
                    @endif
                    @if($isReplay)
                        <button class="workflow-workbench__quick-action" type="button" wire:click="openWorkflowDebugger" aria-label="Данные шагов" title="Данные шагов"><x-filament::icon icon="heroicon-o-beaker" class="h-5 w-5"/></button>
                        <a href="{{ $this->getReplayBackUrl() }}" class="workflow-workbench__quick-action" aria-label="Вернуться к запуску" title="Вернуться к запуску"><x-filament::icon icon="heroicon-o-clock" class="h-5 w-5"/></a>
                    @elseif($workflowRecord)
                        <button class="workflow-workbench__quick-action" type="button" wire:click="openWorkflowDebugger" aria-label="Отладка" title="Отладка"><x-filament::icon icon="heroicon-o-beaker" class="h-5 w-5"/></button>
                        <a href="{{ \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl('history', ['record' => $workflowRecord]) }}" class="workflow-workbench__quick-action" aria-label="История" title="История"><x-filament::icon icon="heroicon-o-clock" class="h-5 w-5"/></a>
                    @else
                        <button class="workflow-workbench__quick-action" type="button" wire:click="openWorkflowDebugger" aria-label="Отладка" title="Отладка"><x-filament::icon icon="heroicon-o-beaker" class="h-5 w-5"/></button>
                    @endif

                    <button
                        type="submit"
                        aria-label="{{ $submitLabel }}"
                        x-bind:aria-label="workflowIsSaving($wire) ? 'Сохраняю…' : @js($submitLabel)"
                        aria-busy="false"
                        x-bind:aria-busy="workflowIsSaving($wire)"
                        x-bind:disabled="workflowIsSaving($wire)"
                        title="{{ $submitLabel }}"
                        class="workflow-workbench__quick-action workflow-workbench__quick-action--primary workflow-workbench__quick-action--labeled workflow-save-button"
                        x-bind:class="{ 'workflow-save-button--clean': !hasUnsavedChanges }"
                        x-bind:data-unsaved="hasUnsavedChanges ? 'true' : 'false'"
                        wire:loading.attr="disabled"
                        wire:target="save,create"
                    >
                        <span class="workflow-save-button__content">
                            <span class="workflow-save-button__label workflow-save-button__idle">{{ $submitLabel }}</span>
                            <span class="workflow-save-button__label workflow-save-button__progress" role="status" aria-live="polite"><span class="workflow-run-spinner" aria-hidden="true"></span> Сохраняю…</span>
                        </span>
                    </button>

                    <details class="workflow-workbench__more" x-on:click.outside="$el.open = false" x-on:keydown.escape.stop="$el.open = false">
                        <summary class="workflow-workbench__quick-action" aria-label="Действия со сценарием" title="Действия со сценарием">
                            <x-filament::icon icon="heroicon-o-ellipsis-horizontal" class="h-5 w-5"/>
                        </summary>
                        <div class="workflow-workbench__menu" x-on:click="$el.closest('details').open = false">
                            <button type="button" x-on:click="window.dispatchEvent(new CustomEvent('workflow-export'))"><x-filament::icon icon="heroicon-o-arrow-down-tray" class="h-4 w-4"/> Экспорт JSON</button>
                            <button type="button" wire:click="mountAction('importWorkflow')"><x-filament::icon icon="heroicon-o-arrow-up-tray" class="h-4 w-4"/> Импорт JSON</button>
                            @if ($workflowRecord && !$isReplay)
                                <button type="button" wire:click="duplicateCurrentWorkflow" wire:loading.attr="disabled" wire:target="duplicateCurrentWorkflow">
                                    <x-filament::icon icon="heroicon-o-document-duplicate" class="h-4 w-4"/> Дублировать
                                </button>
                                <button type="button" wire:click="mountAction('deleteWorkflow')" class="workflow-workbench__menu-danger">
                                    <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4"/> Удалить сценарий
                                </button>
                            @endif
                        </div>
                    </details>
                </div>
            </div>
        </div>

        @include('filament.workflow-builder.workflow-debug-toolbar')
        <div class="workflow-workbench__main">
            <x-filament-workflows::workflows.node-library
                :triggers="$workflowTriggerOptions"
                :actions="$workflowActionOptions"
                :has-trigger="(bool) $this->trigger"
                :mask-groups="$maskGroups"
                :system-id-groups="$systemIdGroups"
            />

            {{-- Keep Alpine directives stable across Livewire morphs. Replacing x-data
                 runs destroy() and disconnects layout observers on the live canvas. --}}
            <section
                wire:key="workflow-canvas-{{ $workflowLayoutKey }}"
                x-data="workflowNodeCanvas(@js($workflowLayoutKey), $wire.definition.canvas_layout ?? null, $wire.definition.canvas_edge_routes ?? null)"
                x-init="$nextTick(() => { initializeCanvas(); setExecutionState(debugCanvasState()); }); $watch('selectedDebugId', id => { if ($wire.debugOpen) { selectedNodeIds = id ? ['action:' + id] : []; applyNodeSelection(); } })"
                x-on:pointermove.window="moveCanvas($event)"
                x-on:pointerup.window="stopCanvasInteraction($event)"
                x-on:pointercancel.window="stopCanvasInteraction($event)"
                x-on:workflow-layout-reset.window="resetNodeLayout()"
                x-on:workflow-debug-updated.window="setExecutionState(debugCanvasState())"
                x-on:workflow-debug-inspection.window="if ($event.detail.componentId === $wire.$id) setExecutionState($event.detail.state)"
                x-on:keydown.escape.window="stopCanvasPan(true); stopAreaDrag(true); cancelConnection(); clearNodeSelection()"
                x-on:blur.window="stopCanvasPan(true)"
                x-on:keydown.window="clipboardShortcut($event)"
                x-on:submit.window.capture="if ($event.target.contains($el)) saveNodeLayout()"
                x-on:workflow-node-inserted.window="placeInsertedNode($event.detail)"
                x-on:workflow-node-created.window="placeCreatedNode($event.detail)"
                x-on:workflow-nodes-copied.window="placeCopiedNodes($event.detail)"
                x-on:workflow-connections-updated.window="scheduleGraphRefresh()"
                x-on:workflow-node-library-closed.window="pendingDropPosition = null; pendingInsertion = null"
                x-on:workflow-export.window="$wire.exportCurrentWorkflow(positions)"
                class="workflow-node-editor"
            >
            <div
                x-ref="viewport"
                x-on:pointerdown="startCanvasInteraction($event)"
                x-on:lostpointercapture="stopCanvasPan(true, $event.pointerId)"
                x-on:wheel="onCanvasWheel($event)"
                x-on:click.capture="suppressNodeClick($event)"
                x-on:dblclick="openCanvasPalette($event)"
                x-on:contextmenu="openCanvasPalette($event)"
                x-bind:class="{
                    'is-node-dragging': draggingNodeId !== null,
                    'is-selecting': selectionBox !== null,
                    'has-free-node-layout': true,
                }"
                class="workflow-node-editor__viewport"
            >
                @include('filament.workflow-builder.workflow-canvas-grid')
                <div class="workflow-selection-box" x-show="selectionBox" x-bind:style="selectionStyle()" x-cloak></div>
                <div class="workflow-canvas-note" x-data="{ open: false, note: @js($this->definition['description'] ?? '') }" x-on:pointerdown.stop>
                    <button type="button" x-on:click="open = !open" :aria-expanded="open" aria-label="Описание сценария" title="Описание сценария"><x-filament::icon icon="heroicon-o-document-text" class="h-4 w-4"/></button>
                    <textarea x-show="open" x-cloak x-model="note" x-on:change="workflowEditorCall($wire, 'updateWorkflowDescription', note)" maxlength="10000" aria-label="Описание сценария" placeholder="Описание сценария…"></textarea>
                </div>
                <div class="workflow-canvas-controls" x-on:pointerdown.stop>
                    <button type="button" class="workflow-canvas-fit" x-on:click.stop="pendingDropPosition = null; pendingInsertion = null; preparePalette(null, {x: $event.currentTarget.getBoundingClientRect().left - 330, y: $event.currentTarget.getBoundingClientRect().top})" aria-label="Добавить ноду" title="Добавить ноду"><x-filament::icon icon="heroicon-o-plus" class="h-4 w-4"/></button>
                    <button type="button" class="workflow-canvas-fit" x-on:click.stop="addCanvasArea()" x-bind:disabled="canvasAreas().length >= 100" aria-label="Создать область этапа" title="Создать область этапа"><x-filament::icon icon="heroicon-o-rectangle-group" class="h-4 w-4"/></button>
                    <button type="button" class="workflow-canvas-fit" x-on:click.stop="fitView()" aria-label="Показать весь сценарий" title="Показать весь сценарий"><x-filament::icon icon="heroicon-o-arrows-pointing-in" class="h-4 w-4"/></button>
                </div>
                <button type="button" class="workflow-canvas-run" x-show="!$wire.debugOpen || !debugConfigured()" x-cloak
                    x-on:pointerdown.stop x-on:click.stop="if (!$wire.debugOpen) $wire.openWorkflowDebugger(); else startDebug(true)"
                    x-bind:disabled="busy || playing" x-bind:aria-busy="busy"
                    x-bind:title="$wire.debugOpen ? 'Запустить отладку' : 'Настроить отладку'" aria-label="Запустить отладку">
                    <x-filament::icon icon="heroicon-s-play" class="h-4 w-4"/><span>Запустить</span>
                </button>
                <div class="workflow-connection-hint" x-show="connecting || connectionError" x-cloak x-on:pointerdown.stop>
                    <span x-text="connectionError || 'Выберите другую ноду'"></span><button type="button" x-show="selectedEdge" x-on:click="removeSelectedEdge()">Удалить связь</button><button type="button" x-on:click="cancelConnection()">Закрыть</button>
                </div>
                <div
                    x-ref="stage"
                    wire:ignore.self
                    x-bind:style="stageStyle()"
                    class="workflow-node-editor__stage"
                >
                    @include('filament.workflow-builder.workflow-canvas-areas')
                    <svg
                        x-ref="edgeLayer"
                        class="workflow-node-edge-layer"
                        aria-hidden="true"
                        wire:ignore
                    ></svg>

                    <main id="workflow-canvas" class="workflow-builder workflow-node-flow">
                        <div class="workflow-node-starts">
                        @foreach($workflowStarts ?: ['trigger' => null] as $startId => $start)
                        @php($startMetadata = $start ? $this->getTriggerMetadata($start['type'], $start['config'] ?? []) : null)
                        <div
                            class="workflow-node-shell workflow-node-shell--trigger"
                            data-workflow-node-id="{{ $startId }}"
                            data-draft-type="{{ $start['type'] ?? '' }}"
                            wire:key="workflow-start-{{ $startId }}"
                        >
                            @if($start && $startMetadata)
                                <x-filament-workflows::workflows.trigger-card
                                    :node-id="$startId"
                                    :node-name="$start['name'] ?? null"
                                    :type="$start['type']"
                                    :config="$start['config'] ?? []"
                                    :metadata="$startMetadata"
                                    :read-only="false"
                                />
                            @else
                                <button
                                    type="button"
                                    x-on:click="workflowEditorCall($wire, 'beginTriggerAdd')"
                                    class="workflow-node-empty"
                                    data-workflow-empty-start
                                    aria-label="Выбрать способ запуска"
                                >
                                    <span class="workflow-node-card__icon">
                                        <x-filament::icon icon="heroicon-o-plus" class="h-5 w-5"/>
                                    </span>
                                    <span class="workflow-node-card__caption">
                                        <strong class="workflow-node-card__title">Выбрать запуск</strong>
                                    </span>
                                </button>
                            @endif
                        </div>

                        @endforeach
                        </div>
                        @if($workflowActionItems->isNotEmpty())
                                <x-filament-workflows::workflows.action-list
                                    :actions="$workflowActionItems->all()"
                                />
                        @endif
                    </main>

                    @if(!$isReplay || $this->trigger || $workflowActionItems->isNotEmpty())
                        <x-filament-workflows::workflows.edge-controls :actions="$workflowActionItems->all()" :connections="$this->definition['connections'] ?? null" :start-ids="array_keys($workflowStarts)"/>
                    @endif
                </div>
            </div>
            @include('filament.workflow-builder.workflow-debug-trail')
            </section>
            @include('filament.workflow-builder.workflow-debugger')
        </div>
    </div>
</div>

