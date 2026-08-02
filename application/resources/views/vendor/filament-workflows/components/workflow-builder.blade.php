@props(['submitLabel' => __('filament-workflows::workflows.actions.save_changes.label')])

@php
    $maskGroups = \App\Workflows\Actions\WorkflowTriggerConditionVariableCatalog::groupedOptions(false);
    $systemIdGroups = \App\Workflows\Actions\WorkflowTriggerConditionVariableCatalog::systemIdGroups();
    try {
        $workflowRecord = method_exists($this, 'getRecord') ? $this->getRecord() : null;
    } catch (\Throwable) {
        $workflowRecord = null;
    }
    $workflowActionItems = collect($this->workflowActions)->values();
    $workflowActionsCount = $workflowActionItems->count();
    $conditionActions = $workflowActionItems
        ->map(fn (array $action, int $index): array => $action + ['__workflowIndex' => $index])
        ->filter(fn (array $action): bool => ($action['type'] ?? null) === 'control-condition')
        ->values();
    $regularActions = $workflowActionItems
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
                        aria-label="{{ $submitLabel }}"
                        title="{{ $submitLabel }}"
                        class="workflow-workbench__quick-action workflow-workbench__quick-action--primary"
                    >
                        <x-filament::icon icon="heroicon-o-check" class="h-4 w-4"/>
                    </button>

                    @if ($this->trigger && $workflowActionsCount > 0)
                        <button
                            type="button"
                            wire:click="mountAction('testWorkflow')"
                            aria-label="Тестировать"
                            title="Тестировать"
                            class="workflow-workbench__quick-action workflow-workbench__quick-action--warning"
                        >
                            <x-filament::icon icon="heroicon-o-beaker" class="h-4 w-4"/>
                        </button>
                    @endif

                    @if ($workflowRecord)
                        <button
                            type="button"
                            wire:click="mountAction('workflowHistory')"
                            aria-label="История"
                            title="История"
                            class="workflow-workbench__quick-action"
                        >
                            <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4"/>
                        </button>
                    @endif

                    <button
                        type="button"
                        x-on:click="window.dispatchEvent(new CustomEvent('workflow-masks-open'))"
                        aria-label="Переменные"
                        title="Переменные"
                        class="workflow-workbench__quick-action"
                    >
                        <x-filament::icon icon="heroicon-o-variable" class="h-4 w-4"/>
                    </button>

                    @if ($workflowRecord)
                        <button
                            type="button"
                            wire:click="duplicateCurrentWorkflow"
                            wire:loading.attr="disabled"
                            wire:target="duplicateCurrentWorkflow"
                            aria-label="Дублировать"
                            title="Дублировать"
                            class="workflow-workbench__quick-action"
                        >
                            <x-filament::icon icon="heroicon-o-document-duplicate" class="h-4 w-4"/>
                        </button>
                    @endif

                </div>
            </div>
        </div>

        <div class="workflow-workbench__layout workflow-workbench__layout--rules">
            <main id="workflow-canvas" class="workflow-workbench__canvas workflow-rules-editor">
                <section class="workflow-rule-block">
                    <div class="workflow-rule-block__grid">
                        <div class="workflow-rule-column workflow-rule-column--conditions">
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

                            <div class="workflow-rule-empty">
                                <span>Выполнять всегда</span>
                            </div>

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

                @foreach($conditionActions as $conditionAction)
                    @php
                        $conditionConfig = $conditionAction['config'] ?? [];
                        $delay = is_array($conditionConfig['delay'] ?? null) ? $conditionConfig['delay'] : [];
                        $blockDelaySeconds = ($delay['mode'] ?? 'immediate') === 'after_seconds'
                            ? min(30, max(1, (int)($delay['seconds'] ?? 0)))
                            : 0;
                        $conditionPreviewRows = \App\Workflows\Actions\WorkflowConditionPreview::rows($conditionConfig, 4);
                        $conditionRemainingCount = \App\Workflows\Actions\WorkflowConditionPreview::remainingCount($conditionConfig, 4);
                        $conditionActionsPath = $conditionAction['__workflowIndex'] . '.config.true_actions';
                        $blockActions = $conditionConfig['true_actions'] ?? [];
                    @endphp

                    <div class="workflow-block-connector">
                        <button
                            type="button"
                            wire:click="addWorkflowBlockAt({{ $conditionAction['__workflowIndex'] }})"
                            title="Добавить блок здесь"
                            class="workflow-block-connector__add"
                        >
                            <x-filament::icon icon="heroicon-o-plus" class="h-4 w-4"/>
                        </button>

                        <label class="workflow-block-connector__delay">
                            <span>Задержка</span>
                            <select
                                wire:change="updateWorkflowBlockDelay('{{ $conditionAction['id'] }}', $event.target.value)"
                            >
                                @foreach([0, 5, 10, 15, 30] as $delayOption)
                                    <option value="{{ $delayOption }}" @selected($blockDelaySeconds === $delayOption)>
                                        {{ $delayOption }} сек.
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <section class="workflow-rule-block">
                        <div class="workflow-rule-block__topline">
                            <button
                                type="button"
                                wire:click="removeWorkflowAction('{{ $conditionAction['id'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="removeWorkflowAction('{{ $conditionAction['id'] }}')"
                                class="workflow-rule-block__delete"
                                title="Удалить блок"
                                aria-label="Удалить блок"
                            >
                                <x-filament::icon
                                    icon="heroicon-o-trash"
                                    class="h-4 w-4"
                                    wire:loading.remove
                                    wire:target="removeWorkflowAction('{{ $conditionAction['id'] }}')"
                                />
                                <x-filament::loading-indicator
                                    class="h-4 w-4"
                                    wire:loading
                                    wire:target="removeWorkflowAction('{{ $conditionAction['id'] }}')"
                                />
                            </button>
                        </div>

                        <div class="workflow-rule-block__grid">
                            <div class="workflow-rule-column workflow-rule-column--conditions">
                                <div class="workflow-rule-condition-preview">
                                    <button
                                        type="button"
                                        wire:click="openWorkflowActionEditor('{{ $conditionAction['id'] }}')"
                                        class="workflow-rule-condition-card"
                                    >
                                        @if($conditionPreviewRows !== [])
                                            <span class="workflow-rule-condition-card__rows">
                                                @foreach($conditionPreviewRows as $conditionPreviewRow)
                                                    <span class="workflow-rule-condition-card__row">
                                                        @if($conditionPreviewRow['connector'])
                                                            <span class="workflow-rule-condition-card__connector">
                                                                {{ $conditionPreviewRow['connector'] }}
                                                            </span>
                                                        @endif

                                                        <span class="workflow-rule-condition-card__value">
                                                            {{ $conditionPreviewRow['left'] }}
                                                        </span>
                                                        <span class="workflow-rule-condition-card__operator">
                                                            {{ $conditionPreviewRow['operator'] }}
                                                        </span>
                                                        @if($conditionPreviewRow['right'] !== null)
                                                            <span class="workflow-rule-condition-card__value">
                                                                {{ $conditionPreviewRow['right'] }}
                                                            </span>
                                                        @endif
                                                    </span>
                                                @endforeach

                                                @if($conditionRemainingCount > 0)
                                                    <span class="workflow-rule-condition-card__more">
                                                        ещё {{ $conditionRemainingCount }} услов.
                                                    </span>
                                                @endif
                                            </span>
                                        @else
                                            <span class="workflow-rule-condition-card__title">Условие не настроено</span>
                                            <span class="workflow-rule-condition-card__text">
                                                Нажмите, чтобы настроить
                                            </span>
                                        @endif
                                    </button>

                                    <button
                                        type="button"
                                        wire:click.stop="removeWorkflowAction('{{ $conditionAction['id'] }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="removeWorkflowAction('{{ $conditionAction['id'] }}')"
                                        class="workflow-rule-condition-preview__delete"
                                        title="Удалить условие"
                                    >
                                        <x-filament::icon
                                            icon="heroicon-o-trash"
                                            class="h-4 w-4"
                                            wire:loading.remove
                                            wire:target="removeWorkflowAction('{{ $conditionAction['id'] }}')"
                                        />
                                        <x-filament::loading-indicator
                                            class="h-4 w-4"
                                            wire:loading
                                            wire:target="removeWorkflowAction('{{ $conditionAction['id'] }}')"
                                        />
                                    </button>
                                </div>

                                <button
                                    type="button"
                                    wire:click="openWorkflowActionEditor('{{ $conditionAction['id'] }}')"
                                    class="workflow-rule-condition-add"
                                >
                                    <x-filament::icon icon="heroicon-o-plus" class="h-4 w-4"/>
                                    <span>Условие</span>
                                </button>
                            </div>

                            <div class="workflow-rule-column workflow-rule-column--actions">
                                <div class="workflow-rule-column__header">
                                    <span>Действия</span>
                                </div>

                                @if (!empty($blockActions))
                                    <x-filament-workflows::workflows.action-list
                                        :actions="$blockActions"
                                        :parent-path="$conditionActionsPath"
                                    />
                                @else
                                    <div class="workflow-rule-empty">
                                        <span>Добавьте первое действие</span>
                                    </div>
                                @endif

                                <button
                                    type="button"
                                    wire:click="openAddActionForPath('{{ $conditionActionsPath }}')"
                                    class="workflow-rule-add"
                                >
                                    <x-filament::icon icon="heroicon-o-plus" class="h-4 w-4"/>
                                    <span>Действие</span>
                                </button>
                            </div>
                        </div>
                    </section>
                @endforeach

                @if ($this->trigger)
                    <div class="workflow-block-connector workflow-block-connector--tail">
                        <button
                            type="button"
                            wire:click="addWorkflowBlock"
                            class="workflow-add-block"
                        >
                            <x-filament::icon icon="heroicon-o-plus" class="h-4 w-4"/>
                            <span>Добавить блок</span>
                        </button>
                    </div>
                @endif
            </main>
        </div>
    </div>
</div>
