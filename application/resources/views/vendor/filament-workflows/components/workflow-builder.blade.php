@props([
    'submitLabel' => __('filament-workflows::workflows.actions.save_changes.label'),
    'pageMode' => false,
])

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
    <div class="workflow-workbench__shell">
        <div class="workflow-workbench__toolbar">
            <div class="workflow-workbench__toolbar-layout">
                <div class="workflow-workbench__identity">
                    <div class="workflow-identity-inline" x-data="{ editing: false }">
                        <h1 class="workflow-workbench__identity-title" x-show="!editing"><button type="button" title="Переименовать поток" x-on:click="editing=true; $nextTick(() => { $refs.name.focus(); $refs.name.select(); })">{{ $workflowTitle }}</button></h1>
                        <input x-ref="name" x-show="editing" x-cloak value="{{ $workflowTitle }}" maxlength="255" aria-label="Название потока"
                            x-on:keydown.enter.prevent.stop="$el.blur()" x-on:keydown.escape.prevent.stop="editing=false; $el.value=@js($workflowTitle)"
                            x-on:blur="if(editing) { editing=false; $wire.renameWorkflow($el.value); }" />
                        @error('name')<small>{{ $message }}</small>@enderror
                        @if($this->definition['tags'] ?? [])<div class="workflow-identity-tags">@foreach(array_slice($this->definition['tags'],0,3) as $tag)<span>{{ $tag }}</span>@endforeach @if(count($this->definition['tags'])>3)<span>+{{ count($this->definition['tags'])-3 }}</span>@endif</div>@endif
                    </div>
                    <details class="workflow-identity-popover" x-on:click.outside="$el.open=false">
                        <summary title="Теги потока" aria-label="Теги потока"><x-filament::icon icon="heroicon-o-tag" class="h-4 w-4"/></summary>
                        <div x-data="{ tag: '' }">
                            @foreach($this->definition['tags'] ?? [] as $tag)
                                <button type="button" class="workflow-tag-chip" wire:click="setWorkflowTags(@js(array_values(array_diff($this->definition['tags'] ?? [], [$tag]))))" title="Удалить тег">{{ $tag }} ×</button>
                            @endforeach
                            <input x-model="tag" aria-label="Новый тег" placeholder="Добавить тег · Enter" maxlength="50" x-on:keydown.enter.prevent.stop="if(tag.trim()) { $wire.setWorkflowTags([...@js($this->definition['tags'] ?? []),tag.trim()]); tag=''; }" />
                            @error('tags')<small>{{ $message }}</small>@enderror
                        </div>
                    </details>
                    <details class="workflow-identity-popover" x-on:click.outside="$el.open=false">
                        <summary title="Папка потока" aria-label="Папка потока"><x-filament::icon icon="heroicon-o-folder" class="h-4 w-4"/><span>{{ $this->data['group_name'] ?? $workflowRecord?->group_name ?? '' }}</span></summary>
                        <div><select aria-label="Папка потока" wire:change="setWorkflowFolder($event.target.value)">
                            <option value="">Без папки</option>
                            @foreach(\App\Services\Workflows\WorkflowFolders::options() as $folder)
                                <option value="{{ $folder }}" @selected(($this->data['group_name'] ?? $workflowRecord?->group_name) === $folder)>{{ $folder }}</option>
                            @endforeach
                        </select></div>
                    </details>
                </div>

                <div class="workflow-workbench__quick-actions">
                    @if($workflowRecord)
                        <button type="button" wire:click="toggleWorkflowActivation" wire:loading.attr="disabled" wire:target="toggleWorkflowActivation" class="workflow-workbench__quick-action workflow-workbench__quick-action--labeled" aria-pressed="{{ $workflowRecord->is_active ? 'true' : 'false' }}" title="{{ $workflowRecord->is_active ? 'Выключить поток' : 'Сохранить и включить поток' }}">
                            <x-filament::icon icon="heroicon-o-power" class="h-4 w-4"/><span>{{ $workflowRecord->is_active ? 'Выключить' : 'Включить' }}</span>
                        </button>
                    @endif
                    <button type="button" wire:click="mountAction('workflowCredentials')" class="workflow-workbench__quick-action" title="Подключения сервисов" aria-label="Подключения">
                        <x-filament::icon icon="heroicon-o-key" class="h-5 w-5"/>
                    </button>
                    @if($workflowRecord)
                        <button class="workflow-workbench__quick-action" type="button" wire:click="openWorkflowDebugger" aria-label="Отладка" title="Отладка"><x-filament::icon icon="heroicon-o-beaker" class="h-5 w-5"/></button>
                        <a href="{{ \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl('history', ['record' => $workflowRecord]) }}" class="workflow-workbench__quick-action" aria-label="История" title="История"><x-filament::icon icon="heroicon-o-clock" class="h-5 w-5"/></a>
                    @elseif($isReplay)
                        <button class="workflow-workbench__quick-action" type="button" wire:click="openWorkflowDebugger" aria-label="Данные шагов" title="Данные шагов"><x-filament::icon icon="heroicon-o-beaker" class="h-5 w-5"/></button>
                        <a href="{{ $this->getReplayBackUrl() }}" class="workflow-workbench__quick-action" aria-label="Вернуться к запуску" title="Вернуться к запуску"><x-filament::icon icon="heroicon-o-clock" class="h-5 w-5"/></a>
                    @else
                        <button class="workflow-workbench__quick-action" type="button" wire:click="openWorkflowDebugger" aria-label="Отладка" title="Отладка"><x-filament::icon icon="heroicon-o-beaker" class="h-5 w-5"/></button>
                    @endif

                    <button
                        type="submit"
                        aria-label="{{ $submitLabel }}"
                        title="{{ $submitLabel }}"
                        class="workflow-workbench__quick-action workflow-workbench__quick-action--primary workflow-workbench__quick-action--labeled"
                    >
                        <span>{{ $submitLabel }}</span>
                    </button>

                    <details class="workflow-workbench__more" x-on:click.outside="$el.open = false" x-on:keydown.escape.stop="$el.open = false">
                        <summary class="workflow-workbench__quick-action" aria-label="Действия со сценарием" title="Действия со сценарием">
                            <x-filament::icon icon="heroicon-o-ellipsis-horizontal" class="h-5 w-5"/>
                        </summary>
                        <div class="workflow-workbench__menu" x-on:click="$el.closest('details').open = false">
                            <button type="button" x-on:click="window.dispatchEvent(new CustomEvent('workflow-export'))"><x-filament::icon icon="heroicon-o-arrow-down-tray" class="h-4 w-4"/> Экспорт JSON</button>
                            <button type="button" wire:click="mountAction('importWorkflow')"><x-filament::icon icon="heroicon-o-arrow-up-tray" class="h-4 w-4"/> Импорт JSON</button>
                            <button type="button" x-on:click="window.dispatchEvent(new CustomEvent('workflow-layout-reset'))">
                                <x-filament::icon icon="heroicon-o-squares-2x2" class="h-4 w-4"/> Выровнять блоки
                            </button>
                            @if ($workflowRecord)
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

        <div class="workflow-workbench__main">
            <x-filament-workflows::workflows.node-library
                :triggers="$workflowTriggerOptions"
                :actions="$workflowActionOptions"
                :has-trigger="(bool) $this->trigger"
                :mask-groups="$maskGroups"
                :system-id-groups="$systemIdGroups"
            />

            <section
                x-data="workflowNodeCanvas(@js($workflowLayoutKey), @js($this->definition['canvas_layout'] ?? []))"
                x-init="$nextTick(() => { initializeCanvas(); setExecutionState(@js($this->debugState)); })"
                x-on:pointermove.window="moveCanvas($event)"
                x-on:pointerup.window="stopCanvasInteraction($event)"
                x-on:pointercancel.window="stopCanvasInteraction($event)"
                x-on:workflow-layout-reset.window="resetNodeLayout()"
                x-on:workflow-debug-updated.window="setExecutionState($event.detail.state)"
                x-on:keydown.escape.window="cancelConnection(); clearNodeSelection()"
                x-on:workflow-node-inserted.window="placeInsertedNode($event.detail)"
                x-on:workflow-connections-updated.window="scheduleGraphRefresh()"
                x-on:workflow-export.window="$wire.exportCurrentWorkflow(positions)"
                class="workflow-node-editor"
            >
            <div
                x-ref="viewport"
                x-on:pointerdown="startCanvasInteraction($event)"
                x-on:click.capture="suppressNodeClick($event)"
                x-bind:class="{
                    'is-panning': panning,
                    'is-node-dragging': draggingNodeId !== null,
                    'has-free-node-layout': true,
                }"
                class="workflow-node-editor__viewport"
                title="Shift + протянуть по пустому месту — выделить ноды; перетащить выделенную ноду — перенести группу"
            >
                <div class="workflow-selection-box" x-show="selectionBox" x-bind:style="selectionStyle()" x-cloak></div>
                <div class="workflow-canvas-note" x-data="{ open: false, note: @js($this->definition['description'] ?? '') }" x-on:pointerdown.stop>
                    <button type="button" x-on:click="open = !open" :aria-expanded="open" aria-label="Описание сценария" title="Описание сценария"><x-filament::icon icon="heroicon-o-document-text" class="h-4 w-4"/></button>
                    <textarea x-show="open" x-cloak x-model="note" x-on:change="$wire.updateWorkflowDescription(note)" maxlength="10000" aria-label="Описание сценария" placeholder="Описание сценария…"></textarea>
                </div>
                <div class="workflow-canvas-controls" x-on:pointerdown.stop>
                    <button type="button" class="workflow-canvas-fit" x-on:click.stop="fitView()" aria-label="Показать весь сценарий" title="Показать весь сценарий"><x-filament::icon icon="heroicon-o-arrows-pointing-in" class="h-4 w-4"/></button>
                    <button type="button" class="workflow-canvas-fit" x-on:click.stop="zoomCanvas(1.2)" :disabled="scale >= 2" aria-label="Увеличить масштаб" title="Увеличить масштаб"><x-filament::icon icon="heroicon-o-plus" class="h-4 w-4"/></button>
                    <button type="button" class="workflow-canvas-fit" x-on:click.stop="zoomCanvas(1 / 1.2)" :disabled="scale <= 0.01" aria-label="Уменьшить масштаб" title="Уменьшить масштаб"><x-filament::icon icon="heroicon-o-minus" class="h-4 w-4"/></button>
                </div>
                <div class="workflow-connection-hint" x-show="connecting" x-cloak x-on:pointerdown.stop>
                    <span>Выберите другую ноду</span><button type="button" x-show="selectedEdge" x-on:click="removeSelectedEdge()">Удалить связь</button><button type="button" x-on:click="cancelConnection()">Отмена</button>
                </div>
                <div class="workflow-connection-menu" x-show="selectedEdge && !connecting" x-cloak x-on:pointerdown.stop>
                    <button type="button" x-on:click="reconnectSelectedEdge()">Переподключить</button>
                    <button type="button" x-on:click="removeSelectedEdge()">Удалить связь</button>
                    <button type="button" x-on:click="selectedEdge = null" aria-label="Закрыть меню связи">×</button>
                </div>
                <div
                    x-ref="stage"
                    x-bind:style="stageStyle()"
                    class="workflow-node-editor__stage"
                >
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
                                    x-on:click="window.dispatchEvent(new CustomEvent('workflow-node-library-open', { detail: { mode: 'trigger' } }))"
                                    class="workflow-node-empty"
                                >
                                    <span class="workflow-node-card__icon">
                                        <x-filament::icon icon="heroicon-o-bolt" class="h-5 w-5"/>
                                    </span>
                                    <span class="workflow-node-card__caption">
                                        <strong class="workflow-node-card__title">Выбрать запуск</strong>
                                        <small class="workflow-node-card__subtitle">Добавьте триггер</small>
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

                    @if($this->trigger)
                        <x-filament-workflows::workflows.edge-controls :actions="$workflowActionItems->all()" :connections="$this->definition['connections'] ?? null" :start-ids="array_keys($workflowStarts)"/>
                    @endif
                </div>
            </div>
            @include('filament.workflow-builder.workflow-debugger')
            </section>
        </div>
    </div>
</div>
