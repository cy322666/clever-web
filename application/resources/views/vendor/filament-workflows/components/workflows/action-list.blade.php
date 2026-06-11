@props(['actions', 'parentPath' => null])

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

    @if($action['type'] === 'control-condition')
        @php
            $config = $action['config'] ?? [];
            $conditions = $config['conditions'] ?? [];
            $logic = ($config['logic'] ?? 'and') === 'or' ? 'ИЛИ' : 'И';
            $hasTrueBranch = (bool) ($config['has_true_branch'] ?? true);
            $hasFalseBranch = (bool) ($config['has_false_branch'] ?? false);
            $branchCount = (int) $hasTrueBranch + (int) $hasFalseBranch;
            $branchGridClass = $branchCount > 1 ? 'grid grid-cols-2 gap-8' : 'grid grid-cols-1 gap-8';

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
            class="workflow-condition-node rounded-xl border border-amber-200 bg-amber-50/30 p-4 dark:border-amber-900/60 dark:bg-amber-950/10">
            <div class="mb-4 flex items-start justify-between gap-4">
                <div class="flex items-center gap-2">
                    <span
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                        <x-filament::icon icon="heroicon-o-arrow-path-rounded-square" class="h-5 w-5"/>
                    </span>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                                Условие
                            </span>
                            <span class="text-sm font-medium text-amber-600 dark:text-amber-300">
                                {{ $logic }}
                            </span>
                        </div>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                            Действия пойдут по ветке «Да» или «Нет».
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <x-filament::icon-button
                        icon="heroicon-o-pencil"
                        color="gray"
                        size="sm"
                        wire:click="openWorkflowActionEditor('{{ $action['id'] }}')"
                    />
                    <x-filament::icon-button
                        icon="heroicon-o-trash"
                        color="danger"
                        size="sm"
                        wire:click="removeWorkflowAction('{{ $action['id'] }}')"
                    />
                </div>
            </div>

            @if(count($conditions) > 0)
                <div
                    class="mb-5 rounded-xl border border-amber-100 bg-white/80 p-3 shadow-sm dark:border-amber-900/50 dark:bg-gray-900/70">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Если
                    </div>
                    <div class="space-y-2">
                        @foreach($conditions as $conditionIndex => $condition)
                            @php
                                $operator = (string) ($condition['operator'] ?? 'equals');
                            @endphp

                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                @if($conditionIndex > 0)
                                    <span class="text-xs font-semibold text-slate-400 dark:text-gray-500">
                                        {{ $logic }}
                                    </span>
                                @endif

                                <span class="text-sm font-medium text-amber-700 dark:text-amber-300">
                                    {{ $valueLabel($condition['left'] ?? '') }}
                                </span>

                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                    {{ $operatorLabel($operator) }}
                                </span>

                                @if(!in_array($operator, $unaryOperators, true))
                                    <span class="text-sm font-medium text-slate-600 dark:text-gray-300">
                                        {{ $valueLabel($condition['right'] ?? '') }}
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <div
                    class="mb-5 rounded-xl border border-dashed border-amber-200 bg-white/70 p-4 text-center dark:border-amber-900/60 dark:bg-gray-900/60">
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        Условие ещё не настроено.
                    </div>
                </div>
            @endif

            @if($branchCount > 0)
                <div class="{{ $branchGridClass }}">
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
