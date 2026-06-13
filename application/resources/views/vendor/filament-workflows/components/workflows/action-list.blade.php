@props(['actions', 'parentPath' => null])

@php
    $listPath = $parentPath ?? '';
    $nestingDepth = $parentPath ? preg_match_all('/config\.(true_actions|false_actions)/', $parentPath) : 0;
@endphp

<div
    @class([
        'workflow-sortable-list',
        'workflow-sortable-list--nested' => $nestingDepth > 0,
    ])
    x-data="workflowSortableList(@js($listPath))"
>
@forelse($actions as $index => $action)
    @php
        $currentPath = $parentPath !== null && $parentPath !== '' ? "{$parentPath}.{$index}" : "{$index}";
        $insertPath = $parentPath ?? '';
    @endphp

    @if($index > 0)
        <div class="my-2 flex flex-col items-center">
            <x-filament-workflows::workflows.connector/>
            <button
                type="button"
                wire:click="openAddActionAtPath('{{ $insertPath }}', {{ $index }})"
                title="Добавить шаг здесь"
                class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-gray-300 bg-white text-gray-500 shadow-sm transition hover:border-primary-400 hover:bg-primary-50 hover:text-primary-600 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-400 dark:hover:border-primary-500 dark:hover:bg-primary-950 dark:hover:text-primary-300"
            >
                <x-filament::icon icon="heroicon-o-plus" class="h-4 w-4"/>
            </button>
            <x-filament-workflows::workflows.connector/>
        </div>
    @endif

    <div
        class="workflow-sortable-item"
        draggable="true"
        data-workflow-sort-index="{{ $index }}"
        x-on:dragstart="onDragStart($event, {{ $index }})"
        x-on:dragover.prevent="onDragOver($event, {{ $index }})"
        x-on:drop.prevent="onDrop($event, {{ $index }})"
        x-on:dragend="onDragEnd($event)"
        x-on:click.capture="suppressClickAfterDrag($event)"
        x-bind:class="{
            'workflow-sortable-item--dragging': draggingIndex === {{ $index }},
            'workflow-sortable-item--over': overIndex === {{ $index }} && draggingIndex !== null && draggingIndex !== {{ $index }},
        }"
        wire:key="workflow-sortable-{{ $action['id'] ?? $index }}"
    >
        @if($action['type'] === 'control-condition')
            @php
                $config = $action['config'] ?? [];
                $conditions = $config['conditions'] ?? [];
                $logic = ($config['logic'] ?? 'and') === 'or' ? 'ИЛИ' : 'И';
                $hasTrueBranch = (bool) ($config['has_true_branch'] ?? true);
                $hasFalseBranch = (bool) ($config['has_false_branch'] ?? false);
                $branchCount = (int) $hasTrueBranch + (int) $hasFalseBranch;
                $branchGridClass = $nestingDepth > 0 || $branchCount < 2
                    ? 'workflow-condition-branches workflow-condition-branches--single'
                    : 'workflow-condition-branches workflow-condition-branches--split';

                $valueLabel = static function (mixed $value): string {
                    $value = trim((string) $value);

                    if ($value === '') {
                        return '-';
                    }

                    return \App\Workflows\Actions\WorkflowTriggerConditionVariableCatalog::label($value, true) ?? $value;
                };

                $operatorLabel = static fn (string $operator): string => [
                    'equals' => 'равно',
                    'not_equals' => 'не равно',
                    'strict_equals' => 'строго равно',
                    'gt' => 'больше',
                    'gte' => 'больше или равно',
                    'lt' => 'меньше',
                    'lte' => 'меньше или равно',
                    'contains' => 'содержит',
                    'not_contains' => 'не содержит',
                    'starts_with' => 'начинается с',
                    'ends_with' => 'заканчивается на',
                    'in' => 'в списке',
                    'not_in' => 'не в списке',
                    'is_empty' => 'пусто',
                    'is_not_empty' => 'не пусто',
                    'is_null' => 'не заполнено',
                    'is_not_null' => 'заполнено',
                    'is_true' => 'истина',
                    'is_false' => 'ложь',
                    'matches' => 'соответствует шаблону',
                ][$operator] ?? $operator;

                $unaryOperators = ['is_empty', 'is_not_empty', 'is_null', 'is_not_null', 'is_true', 'is_false'];
            @endphp

            <div
                wire:click="openWorkflowActionEditor('{{ $action['id'] }}')"
                @class([
                    'workflow-condition-node cursor-pointer rounded-xl border border-amber-200 bg-amber-50/30 p-3 dark:border-amber-900/60 dark:bg-amber-950/10',
                    'workflow-condition-node--nested' => $nestingDepth > 0,
                ])>
                <div class="mb-3 flex items-start gap-3">
                    <div class="flex items-center gap-2">
                        <span
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                            <x-filament::icon icon="heroicon-o-arrow-path-rounded-square" class="h-4 w-4"/>
                        </span>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold leading-5 text-gray-950 dark:text-white">
                                    Условие
                                </span>
                                <span class="text-sm font-medium leading-5 text-amber-600 dark:text-amber-300">
                                    {{ $logic }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                @if(count($conditions) > 0)
                    <div class="workflow-condition-summary mb-4">
                        <div class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            Если
                        </div>
                        <div class="space-y-1.5">
                            @foreach($conditions as $conditionIndex => $condition)
                                @php
                                    $operator = (string) ($condition['operator'] ?? 'equals');
                                @endphp

                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm leading-5">
                                    @if($conditionIndex > 0)
                                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-gray-500">
                                            {{ $logic }}
                                        </span>
                                    @endif

                                    <span class="font-semibold text-amber-700 dark:text-amber-300">
                                        {{ $valueLabel($condition['left'] ?? '') }}
                                    </span>

                                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                        {{ $operatorLabel($operator) }}
                                    </span>

                                    @if(!in_array($operator, $unaryOperators, true))
                                        <span class="font-semibold text-slate-700 dark:text-gray-200">
                                            {{ $valueLabel($condition['right'] ?? '') }}
                                        </span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div
                        class="mb-4 rounded-lg border border-dashed border-amber-200 bg-white/60 p-3 text-center dark:border-amber-900/60 dark:bg-gray-900/50">
                        <div class="text-sm leading-5 text-gray-500 dark:text-gray-400">
                            Условие ещё не настроено.
                        </div>
                    </div>
                @endif

                @if($branchCount > 0)
                    <div class="{{ $branchGridClass }}" x-on:click.stop>
                        @if($hasTrueBranch)
                            <div class="condition-branch">
                                <div
                                    class="mb-3 flex items-center justify-center gap-2 text-xs font-semibold text-success-700 dark:text-success-300">
                                    <x-filament::icon icon="heroicon-o-check-circle" class="h-4 w-4"/>
                                    Да
                                </div>

                                <x-filament-workflows::workflows.action-list
                                    :actions="$config['true_actions'] ?? []"
                                    :parent-path="$currentPath . '.config.true_actions'"
                                />

                                @if(empty($config['true_actions'] ?? []))
                                <div class="mt-3 flex justify-center">
                                    <x-filament::button
                                        wire:click="openAddActionForPath('{{ $currentPath }}.config.true_actions')"
                                        icon="heroicon-o-plus"
                                        size="xs"
                                        color="success"
                                        outlined
                                    >
                                        {{ __('filament-workflows::workflows.actions.add.label') }}
                                    </x-filament::button>
                                </div>
                                @endif
                            </div>
                        @endif

                        @if($hasFalseBranch)
                            <div class="condition-branch">
                                <div
                                    class="mb-3 flex items-center justify-center gap-2 text-xs font-semibold text-danger-700 dark:text-danger-300">
                                    <x-filament::icon icon="heroicon-o-x-circle" class="h-4 w-4"/>
                                    Нет
                                </div>

                                <x-filament-workflows::workflows.action-list
                                    :actions="$config['false_actions'] ?? []"
                                    :parent-path="$currentPath . '.config.false_actions'"
                                />

                                @if(empty($config['false_actions'] ?? []))
                                <div class="mt-3 flex justify-center">
                                    <x-filament::button
                                        wire:click="openAddActionForPath('{{ $currentPath }}.config.false_actions')"
                                        icon="heroicon-o-plus"
                                        size="xs"
                                        color="danger"
                                        outlined
                                    >
                                        {{ __('filament-workflows::workflows.actions.add.label') }}
                                    </x-filament::button>
                                </div>
                                @endif
                            </div>
                        @endif
                    </div>
                @else
                    <div
                        class="rounded-lg border border-dashed border-slate-300 bg-white/50 p-3 text-center text-xs text-gray-500 dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-400">
                        Действия по результату условия отключены.
                    </div>
                @endif
            </div>
        @else
            <div @class([
                'mx-auto w-full' => $parentPath === null,
                'max-w-3xl' => $parentPath === null,
            ])>
                <x-filament-workflows::workflows.action-card
                    :action="$action"
                    :index="$index"
                    :total="count($actions)"
                    :metadata="$this->getWorkflowActionMetadata($action['type'], $action['config'] ?? [])"
                    :read-only="false"
                    wire:key="action-{{ $action['id'] }}"
                />
            </div>
        @endif
    </div>

    @if($loop->last)
        <div class="my-2 flex flex-col items-center">
            <x-filament-workflows::workflows.connector/>
            <button
                type="button"
                wire:click="openAddActionAtPath('{{ $insertPath }}', {{ count($actions) }})"
                title="Добавить шаг здесь"
                class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-gray-300 bg-white text-gray-500 shadow-sm transition hover:border-primary-400 hover:bg-primary-50 hover:text-primary-600 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-400 dark:hover:border-primary-500 dark:hover:bg-primary-950 dark:hover:text-primary-300"
            >
                <x-filament::icon icon="heroicon-o-plus" class="h-4 w-4"/>
            </button>
        </div>
    @endif

@empty
@endforelse
</div>
