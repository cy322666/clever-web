@props(['submitLabel' => __('filament-workflows::workflows.actions.save_changes.label')])

@php
    $maskGroups = \App\Workflows\Actions\WorkflowTriggerConditionVariableCatalog::groupedOptions(false);
    $systemIdGroups = \App\Workflows\Actions\WorkflowTriggerConditionVariableCatalog::systemIdGroups();
    try {
        $workflowRecord = method_exists($this, 'getRecord') ? $this->getRecord() : null;
    } catch (\Throwable) {
        $workflowRecord = null;
    }
    $workflowActionsCount = count($this->workflowActions);
    $conditionActions = collect($this->workflowActions)
        ->filter(fn (array $action): bool => ($action['type'] ?? null) === 'control-condition')
        ->values();
    $regularActions = collect($this->workflowActions)
        ->reject(fn (array $action): bool => ($action['type'] ?? null) === 'control-condition')
        ->values();
@endphp

<div
    x-data="{ masksOpen: false }"
    x-on:keydown.escape.window="masksOpen = false"
    x-on:workflow-masks-open.window="masksOpen = true"
    class="workflow-workbench"
>
    <aside
        x-show="masksOpen"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="-translate-x-full opacity-0"
        x-transition:enter-end="translate-x-0 opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="translate-x-0 opacity-100"
        x-transition:leave-end="-translate-x-full opacity-0"
        class="workflow-mask-dock fixed bottom-4 left-4 top-4 z-[100] flex w-[min(28rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl shadow-slate-950/20 dark:border-gray-700 dark:bg-gray-950"
    >
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-gray-800">
            <div>
                <div class="text-sm font-semibold text-gray-950 dark:text-white">Справочник переменных и ID</div>
            </div>

            <div class="flex items-center gap-2">
                <x-filament::button
                    type="button"
                    size="sm"
                    color="gray"
                    icon="heroicon-o-arrow-path"
                    wire:click="refreshWorkflowReference"
                    wire:loading.attr="disabled"
                    wire:target="refreshWorkflowReference"
                >
                    <span wire:loading.remove wire:target="refreshWorkflowReference">Обновить</span>
                    <span wire:loading wire:target="refreshWorkflowReference">Обновляю...</span>
                </x-filament::button>

                <button
                    type="button"
                    x-on:click="masksOpen = false"
                    class="rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                >
                    <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5"/>
                </button>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto p-4">
            @include('filament.workflow-builder.mask-reference', [
                'groups' => $maskGroups,
                'systemIdGroups' => $systemIdGroups,
            ])
        </div>
    </aside>

    <div class="workflow-workbench__shell mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm shadow-slate-950/5 dark:border-gray-800 dark:bg-gray-950">
        <div class="workflow-workbench__toolbar sticky top-0 z-20 border-b border-slate-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-gray-800 dark:bg-gray-950/95">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="workflow-workbench__quick-actions ml-auto flex flex-wrap items-center gap-2">
                    <button
                        type="submit"
                        class="workflow-workbench__quick-action workflow-workbench__quick-action--primary"
                    >
                        <x-filament::icon icon="heroicon-o-check" class="h-4 w-4"/>
                        <span>{{ $submitLabel }}</span>
                    </button>

                    @if ($this->trigger && $workflowActionsCount > 0)
                        <button
                            type="button"
                            wire:click="mountAction('testWorkflow')"
                            class="workflow-workbench__quick-action workflow-workbench__quick-action--warning"
                        >
                            <x-filament::icon icon="heroicon-o-beaker" class="h-4 w-4"/>
                            <span>Тестировать</span>
                        </button>
                    @endif

                    @if ($workflowRecord)
                        <button
                            type="button"
                            wire:click="mountAction('workflowHistory')"
                            class="workflow-workbench__quick-action"
                        >
                            <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4"/>
                            <span>История</span>
                        </button>
                    @endif

                    @if ($workflowRecord)
                        <button
                            type="button"
                            wire:click="duplicateCurrentWorkflow"
                            wire:loading.attr="disabled"
                            wire:target="duplicateCurrentWorkflow"
                            class="workflow-workbench__quick-action"
                        >
                            <x-filament::icon icon="heroicon-o-document-duplicate" class="h-4 w-4"/>
                            <span>Дублировать</span>
                        </button>
                    @endif

                </div>
            </div>
        </div>

        <div class="workflow-workbench__layout workflow-workbench__layout--rules">
            <main id="workflow-canvas" class="workflow-workbench__canvas workflow-rules-editor">
                <section class="workflow-rule-block">
                    <div class="workflow-rule-block__topline">
                        <div>
                            <div class="workflow-rule-block__name">Блок #1</div>
                            <div class="workflow-rule-block__meta">
                                через <span>0</span> сек.
                            </div>
                        </div>
                    </div>

                    <div class="workflow-rule-block__grid">
                        <div class="workflow-rule-column workflow-rule-column--conditions">
                            <div class="workflow-rule-column__header">
                                <span>Условия</span>
                            </div>

                            @unless ($this->trigger)
                                <button
                                    type="button"
                                    wire:click="mountAction('selectTrigger')"
                                    class="workflow-rules-empty-trigger"
                                >
                                    <span class="workflow-rules-empty-trigger__icon">
                                        <x-filament::icon icon="heroicon-o-bolt" class="h-5 w-5"/>
                                    </span>
                                    <span>
                                        <span class="workflow-rules-empty-trigger__title">Выбрать триггер</span>
                                        <span class="workflow-rules-empty-trigger__text">Что запускает сценарий</span>
                                    </span>
                                </button>
                            @endunless

                            @if($conditionActions->isNotEmpty())
                                <div class="workflow-rule-condition-list">
                                    @foreach($conditionActions as $conditionAction)
                                        @php
                                            $conditionConfig = $conditionAction['config'] ?? [];
                                            $conditions = $conditionConfig['conditions'] ?? [];
                                            $logic = ($conditionConfig['logic'] ?? 'and') === 'or' ? 'ИЛИ' : 'И';
                                        @endphp

                                        <button
                                            type="button"
                                            wire:click="openWorkflowActionEditor('{{ $conditionAction['id'] }}')"
                                            class="workflow-rule-condition-card"
                                        >
                                            <span class="workflow-rule-condition-card__title">
                                                {{ filled($conditionAction['name'] ?? null) ? $conditionAction['name'] : 'Условие' }}
                                            </span>
                                            <span class="workflow-rule-condition-card__text">
                                                {{ count($conditions) }} услов. · {{ $logic }}
                                            </span>
                                        </button>
                                    @endforeach
                                </div>
                            @else
                                <div class="workflow-rule-empty">
                                    <span>Выполнять всегда</span>
                                </div>
                            @endif

                            @if ($this->trigger)
                                <button
                                    type="button"
                                    wire:click="mountAction('addWorkflowAction')"
                                    class="workflow-rule-add workflow-rule-add--condition"
                                >
                                    <x-filament::icon icon="heroicon-o-plus" class="h-4 w-4"/>
                                    <span>Условие</span>
                                </button>
                            @endif
                        </div>

                        <div class="workflow-rule-column workflow-rule-column--actions">
                            <div class="workflow-rule-column__header">
                                <span>Действия</span>
                            </div>

                            @if ($regularActions->isNotEmpty())
                                <x-filament-workflows::workflows.action-list :actions="$regularActions->all()"/>
                            @elseif ($this->trigger)
                                <div class="workflow-rule-empty">
                                    <span>Добавьте первое действие</span>
                                </div>
                            @else
                                <div class="workflow-rule-empty">
                                    <span>Сначала выберите триггер</span>
                                </div>
                            @endif

                            @if ($this->trigger)
                                <button
                                    type="button"
                                    wire:click="mountAction('addWorkflowAction')"
                                    class="workflow-rule-add"
                                >
                                    <x-filament::icon icon="heroicon-o-plus" class="h-4 w-4"/>
                                    <span>Действие</span>
                                </button>
                            @endif
                        </div>
                    </div>
                </section>

                @if ($this->trigger)
                    <button
                        type="button"
                        wire:click="addWorkflowBlock"
                        class="workflow-add-block"
                    >
                        <x-filament::icon icon="heroicon-o-plus" class="h-4 w-4"/>
                        <span>Добавить блок</span>
                    </button>
                @endif
            </main>
        </div>
    </div>
</div>
