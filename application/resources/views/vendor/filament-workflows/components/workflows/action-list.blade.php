@props(['actions', 'parentPath' => null])

@forelse($actions as $index => $action)
    @php
        $currentPath = $parentPath !== null && $parentPath !== '' ? "{$parentPath}.{$index}" : "{$index}";
    @endphp

    @if($index > 0)
        <x-filament-workflows::workflows.connector/>
    @endif

    @if($action['type'] === 'control-condition')
        @php
            $config = $action['config'] ?? [];
            $conditions = $config['conditions'] ?? [];
            $hasTrueBranch = (bool) ($config['has_true_branch'] ?? true);
            $hasFalseBranch = (bool) ($config['has_false_branch'] ?? false);
            $branchCount = (int) $hasTrueBranch + (int) $hasFalseBranch;
            $branchGridClass = $branchCount > 1 ? 'grid grid-cols-2 gap-8' : 'grid grid-cols-1 gap-8';
        @endphp

        <div
            class="workflow-condition-node border-2 border-warning-200 dark:border-warning-800 rounded-lg p-4 bg-warning-50/30 dark:bg-warning-900/10">
            <div class="flex justify-between items-center mb-3">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-arrows-pointing-out" class="w-5 h-5 text-warning-600"/>
                    <span
                        class="font-medium text-warning-900 dark:text-warning-100">{{ __('filament-workflows::workflows.builder.condition.label') }}</span>
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
                    class="mb-4 p-3 bg-white/50 dark:bg-gray-800/50 rounded-lg border border-warning-200 dark:border-warning-700">
                    <div
                        class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">{{ __('filament-workflows::workflows.builder.condition.when') }}</div>
                    <div class="space-y-1">
                        @foreach($conditions as $conditionIndex => $condition)
                            <div class="flex items-center gap-2 text-sm">
                                @if($conditionIndex > 0)
                                    <span
                                        class="text-gray-400 dark:text-gray-500 text-xs">{{ __('filament-workflows::workflows.builder.condition.and') }}</span>
                                @endif
                                <code
                                    class="px-1.5 py-0.5 bg-warning-100 dark:bg-warning-900/50 text-warning-800 dark:text-warning-200 rounded text-xs">{{ $condition['left'] ?? 'field' }}</code>
                                <span
                                    class="text-gray-600 dark:text-gray-300">{{ $condition['operator'] ?? '=' }}</span>
                                @if(!in_array($condition['operator'] ?? '', ['is_empty', 'is_not_empty']))
                                    <code
                                        class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 rounded text-xs">{{ $condition['right'] ?? '' }}</code>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <div
                    class="mb-4 p-3 bg-white/50 dark:bg-gray-800/50 rounded-lg border border-dashed border-warning-300 dark:border-warning-700">
                    <div
                        class="text-xs text-gray-400 dark:text-gray-500 text-center">{{ __('filament-workflows::workflows.builder.condition.empty') }}</div>
                </div>
            @endif

            @if($branchCount > 0)
                <div class="{{ $branchGridClass }}">
                    @if($hasTrueBranch)
                        <div class="condition-branch">
                            <div
                                class="text-xs font-bold text-success-600 uppercase tracking-wider mb-3 text-center border-b border-success-200 pb-2">
                                {{ __('filament-workflows::workflows.builder.condition.if_true') }}
                            </div>

                            <x-filament-workflows::workflows.action-list
                                :actions="$config['true_actions'] ?? []"
                                :parent-path="$currentPath . '.config.true_actions'"
                            />

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
                        </div>
                    @endif

                    @if($hasFalseBranch)
                        <div class="condition-branch">
                            <div
                                class="text-xs font-bold text-danger-600 uppercase tracking-wider mb-3 text-center border-b border-danger-200 pb-2">
                                {{ __('filament-workflows::workflows.builder.condition.if_false') }}
                            </div>

                            <x-filament-workflows::workflows.action-list
                                :actions="$config['false_actions'] ?? []"
                                :parent-path="$currentPath . '.config.false_actions'"
                            />

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
        <x-filament-workflows::workflows.action-card
            :action="$action"
            :index="$index"
            :total="count($actions)"
            :metadata="$this->getWorkflowActionMetadata($action['type'], $action['config'] ?? [])"
            :read-only="false"
            wire:key="action-{{ $action['id'] }}"
        />
    @endif

@empty
@endforelse
