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
    $conditionValueGroups = \App\Workflows\Actions\WorkflowTriggerConditionVariableCatalog::groupedOptions(true);
    $conditionValueGroupByOption = [];
    foreach ($conditionValueGroups as $groupLabel => $groupOptions) {
        foreach ($groupOptions as $optionValue => $optionLabel) {
            $conditionValueGroupByOption[(string) $optionValue] = (string) $groupLabel;
        }
    }

    $conditionOperatorOptions = [
        'equals' => ['label' => 'Равно', 'symbol' => '='],
        'not_equals' => ['label' => 'Не равно', 'symbol' => '≠'],
        'is_empty' => ['label' => 'Пусто', 'symbol' => '∅'],
        'is_not_empty' => ['label' => 'Не пусто', 'symbol' => '!∅'],
        'lt' => ['label' => 'Меньше', 'symbol' => '<'],
        'gt' => ['label' => 'Больше', 'symbol' => '>'],
    ];
    $conditionUnaryOperators = ['is_empty', 'is_not_empty'];
    $conditionActions = $workflowActionItems
        ->map(fn (array $action, int $index): array => $action + ['__workflowIndex' => $index])
        ->filter(fn (array $action): bool => ($action['type'] ?? null) === 'control-condition')
        ->values();
    $regularActions = $workflowActionItems
        ->reject(fn (array $action): bool => ($action['type'] ?? null) === 'control-condition')
        ->values();
    $firstBlockInsertIndex = $regularActions->count();
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
                        <div class="workflow-rule-column workflow-rule-column--conditions"
                             x-data="{ triggerListOpen: false }">
                            @unless ($this->trigger)
                                <button
                                    type="button"
                                    x-on:click="triggerListOpen = ! triggerListOpen"
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

                                <div
                                    x-cloak
                                    x-show="triggerListOpen"
                                    x-transition
                                    x-on:click.outside="triggerListOpen = false"
                                    class="workflow-inline-trigger-list"
                                >
                                    @include('filament-workflows::filament.partials.trigger-selection-grid', [
                                        'triggers' => $this->getAvailableTriggers(),
                                        'compact' => true,
                                    ])
                                </div>
                            @endunless

                            <div class="workflow-rule-empty">
                                <span>Выполнять всегда</span>
                            </div>

                            @if ($this->trigger)
                                <button
                                    type="button"
                                    wire:click="addWorkflowBlockAt({{ $firstBlockInsertIndex }})"
                                    class="workflow-rule-condition-add"
                                >
                                    <x-filament::icon icon="heroicon-o-plus" class="h-4 w-4"/>
                                    <span>Условие</span>
                                </button>
                            @endif

                        </div>

                        <div class="workflow-rule-column workflow-rule-column--actions">
                            @if ($regularActions->isNotEmpty())
                                <x-filament-workflows::workflows.action-list :actions="$regularActions->all()"/>
                            @else
                                @unless ($this->trigger)
                                    <div class="workflow-rule-empty">
                                        <span>Сначала выберите триггер</span>
                                    </div>
                                @endunless
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
                        $conditionRows = array_values(array_filter(
                            (array)($conditionConfig['conditions'] ?? []),
                            fn ($conditionRow): bool => is_array($conditionRow),
                        ));
                        $conditionLogic = (string)($conditionConfig['logic'] ?? 'and');
                        $conditionActionsPath = $conditionAction['__workflowIndex'] . '.config.true_actions';
                        $blockActions = $conditionConfig['true_actions'] ?? [];
                    @endphp

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
                                <div class="workflow-inline-condition-editor">
                                    @if($conditionRows === [])
                                        <div class="workflow-rule-empty">
                                            <span>Выполнять всегда</span>
                                        </div>
                                    @else
                                        <label class="workflow-inline-condition-editor__logic">
                                            <select
                                                aria-label="И или ИЛИ"
                                                wire:change="updateWorkflowInlineConditionLogic('{{ $conditionAction['id'] }}', $event.target.value)"
                                            >
                                                <option value="and" @selected($conditionLogic !== 'or')>И</option>
                                                <option value="or" @selected($conditionLogic === 'or')>ИЛИ</option>
                                            </select>
                                        </label>

                                        <div class="workflow-inline-condition-editor__rows">
                                            @foreach($conditionRows as $conditionIndex => $conditionRow)
                                                @php
                                                    $operator = (string)($conditionRow['operator'] ?? 'equals');
                                                    $isUnaryOperator = in_array($operator, $conditionUnaryOperators, true);
                                                    $leftValue = (string)($conditionRow['left'] ?? '');
                                                    $rightValue = (string)($conditionRow['right'] ?? '');
                                                    $leftGroup = $conditionValueGroupByOption[$leftValue] ?? ($leftValue !== '' ? '__custom' : '');
                                                    $rightGroup = $conditionValueGroupByOption[$rightValue] ?? ($rightValue !== '' ? '__custom' : '');
                                                @endphp

                                                <div class="workflow-inline-condition-row">
                                                    <button
                                                        type="button"
                                                        wire:click="removeWorkflowInlineCondition('{{ $conditionAction['id'] }}', {{ $conditionIndex }})"
                                                        class="workflow-inline-condition-row__delete"
                                                        title="Удалить условие"
                                                        aria-label="Удалить условие"
                                                    >
                                                        <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4"/>
                                                    </button>

                                                    <div class="workflow-inline-condition-grid">
                                                        <div
                                                            class="workflow-inline-condition-value"
                                                            x-data="{ group: @js($leftGroup) }"
                                                        >
                                                            <select
                                                                x-model="group"
                                                                aria-label="Группа значения"
                                                            >
                                                                <option value="">Выберите</option>
                                                                @foreach($conditionValueGroups as $groupLabel => $groupOptions)
                                                                    <option
                                                                        value="{{ $groupLabel }}">{{ $groupLabel }}</option>
                                                                @endforeach
                                                                <option value="__custom">Свое значение</option>
                                                            </select>

                                                            @foreach($conditionValueGroups as $groupLabel => $groupOptions)
                                                                <select
                                                                    x-cloak
                                                                    x-show="group === @js((string) $groupLabel)"
                                                                    wire:change="updateWorkflowInlineCondition('{{ $conditionAction['id'] }}', {{ $conditionIndex }}, 'left', $event.target.value)"
                                                                    aria-label="Значение"
                                                                >
                                                                    <option value="">Выберите</option>
                                                                    @foreach($groupOptions as $optionValue => $optionLabel)
                                                                        <option
                                                                            value="{{ $optionValue }}" @selected($leftValue === (string) $optionValue)>
                                                                            {{ $optionLabel }}
                                                                        </option>
                                                                    @endforeach
                                                                </select>
                                                            @endforeach

                                                            <input
                                                                x-cloak
                                                                x-show="group === '__custom'"
                                                                type="text"
                                                                value="{{ $leftValue }}"
                                                                wire:change="updateWorkflowInlineCondition('{{ $conditionAction['id'] }}', {{ $conditionIndex }}, 'left', $event.target.value)"
                                                                placeholder="Свое значение"
                                                            />
                                                        </div>

                                                        <label class="workflow-inline-condition-operator">
                                                            <select
                                                                aria-label="Сравнение"
                                                                wire:change="updateWorkflowInlineCondition('{{ $conditionAction['id'] }}', {{ $conditionIndex }}, 'operator', $event.target.value)"
                                                            >
                                                                @foreach($conditionOperatorOptions as $optionValue => $operatorOption)
                                                                    <option
                                                                        value="{{ $optionValue }}" @selected($operator === $optionValue)>
                                                                        {{ $operatorOption['symbol'] }}
                                                                    </option>
                                                                @endforeach
                                                            </select>
                                                        </label>

                                                        @unless($isUnaryOperator)
                                                            <div
                                                                class="workflow-inline-condition-value"
                                                                x-data="{ group: @js($rightGroup) }"
                                                            >
                                                                <select
                                                                    x-model="group"
                                                                    aria-label="Группа значения для сравнения"
                                                                >
                                                                    <option value="">Выберите</option>
                                                                    @foreach($conditionValueGroups as $groupLabel => $groupOptions)
                                                                        <option
                                                                            value="{{ $groupLabel }}">{{ $groupLabel }}</option>
                                                                    @endforeach
                                                                    <option value="__custom">Свое значение</option>
                                                                </select>

                                                                @foreach($conditionValueGroups as $groupLabel => $groupOptions)
                                                                    <select
                                                                        x-cloak
                                                                        x-show="group === @js((string) $groupLabel)"
                                                                        wire:change="updateWorkflowInlineCondition('{{ $conditionAction['id'] }}', {{ $conditionIndex }}, 'right', $event.target.value)"
                                                                        aria-label="Значение для сравнения"
                                                                    >
                                                                        <option value="">Выберите</option>
                                                                        @foreach($groupOptions as $optionValue => $optionLabel)
                                                                            <option
                                                                                value="{{ $optionValue }}" @selected($rightValue === (string) $optionValue)>
                                                                                {{ $optionLabel }}
                                                                            </option>
                                                                        @endforeach
                                                                    </select>
                                                                @endforeach

                                                                <input
                                                                    x-cloak
                                                                    x-show="group === '__custom'"
                                                                    type="text"
                                                                    value="{{ $rightValue }}"
                                                                    wire:change="updateWorkflowInlineCondition('{{ $conditionAction['id'] }}', {{ $conditionIndex }}, 'right', $event.target.value)"
                                                                    placeholder="Свое значение"
                                                                />
                                                            </div>
                                                        @endunless
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    <button
                                        type="button"
                                        wire:click="addWorkflowInlineCondition('{{ $conditionAction['id'] }}')"
                                        class="workflow-rule-condition-add"
                                    >
                                        <x-filament::icon icon="heroicon-o-plus" class="h-4 w-4"/>
                                        <span>Условие</span>
                                    </button>
                                </div>
                            </div>

                            <div class="workflow-rule-column workflow-rule-column--actions">
                                @if (!empty($blockActions))
                                    <x-filament-workflows::workflows.action-list
                                        :actions="$blockActions"
                                        :parent-path="$conditionActionsPath"
                                    />
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
