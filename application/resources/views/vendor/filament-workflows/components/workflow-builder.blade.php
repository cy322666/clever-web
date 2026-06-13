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
    $workflowRunsUrl = $workflowRecord
        ? \App\Filament\WorkflowBuilder\Resources\WorkflowRunResource::getUrl('index', [
            'workflow_id' => $workflowRecord->getKey(),
        ])
        : null;
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
        class="workflow-mask-dock fixed bottom-4 left-4 top-4 z-[100] flex w-[min(28rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-950/20 dark:border-gray-700 dark:bg-gray-950"
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
                    class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200"
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
                    @if ($this->trigger && $workflowActionsCount > 0)
                        <button
                            type="button"
                            wire:click="mountAction('testWorkflow')"
                            class="workflow-workbench__quick-action workflow-workbench__quick-action--warning"
                        >
                            <x-filament::icon icon="heroicon-o-beaker" class="h-4 w-4"/>
                            <span>Запустить тест</span>
                        </button>
                    @endif

                    @if ($workflowRunsUrl)
                        <a href="{{ $workflowRunsUrl }}" target="_blank" rel="noopener noreferrer" class="workflow-workbench__quick-action">
                            <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4"/>
                            <span>Запуски</span>
                        </a>
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
                            <span>Копировать</span>
                        </button>
                    @endif

                </div>
            </div>
        </div>

        <div class="workflow-workbench__layout">
            <main id="workflow-canvas" class="workflow-workbench__canvas">
                <div class="workflow-builder w-full">
        {{-- Trigger Section --}}
        <div class="mb-6">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-3">
                {{ __('filament-workflows::workflows.messages.when_this_happens') }}
            </h3>

            @if ($this->trigger)
                <x-filament-workflows::workflows.trigger-card :type="$this->trigger['type']"
                                                              :config="$this->trigger['config'] ?? []"
                                                              :metadata="$this->getTriggerMetadata($this->trigger['type'], $this->trigger['config'] ?? [])"
                                                              :read-only="false"/>
            @else
                <x-filament-workflows::workflows.empty-state icon="heroicon-o-bolt"
                                                             :message="__('filament-workflows::workflows.builder.empty_state.no_trigger')"
                                                             :description="__('filament-workflows::workflows.messages.trigger_empty_description')">
                    <x-filament::button wire:click="mountAction('selectTrigger')" icon="heroicon-o-plus"
                                        color="primary">
                        {{ __('filament-workflows::workflows.actions.select_trigger.label') }}
                    </x-filament::button>
                </x-filament-workflows::workflows.empty-state>
            @endif
        </div>

        {{-- Connector (only if trigger exists) --}}
        @if ($this->trigger)
            <x-filament-workflows::workflows.connector/>
        @endif

        {{-- Actions Section --}}
        <div class="mb-6">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-3">
                {{ __('filament-workflows::workflows.messages.then_do_this') }}
            </h3>

            @if (count($this->workflowActions) > 0)
                {{-- Render existing actions --}}
                <x-filament-workflows::workflows.action-list :actions="$this->workflowActions"/>
            @else
                @if ($this->trigger)
                    <x-filament-workflows::workflows.empty-state icon="heroicon-o-sparkles"
                                                                 :message="__('filament-workflows::workflows.builder.empty_state.add_first_action')"
                                                                 :description="__('filament-workflows::workflows.messages.first_action_description')">
                        <div class="flex flex-col items-center gap-3">
                            <x-filament::button wire:click="mountAction('addWorkflowAction')" icon="heroicon-o-plus"
                                                color="primary" size="lg">
                                {{ __('filament-workflows::workflows.actions.add_action.label') }}
                            </x-filament::button>
                            <p class="text-xs text-gray-400 dark:text-gray-500">
                                {{ __('filament-workflows::workflows.messages.action_examples') }}
                            </p>
                        </div>
                    </x-filament-workflows::workflows.empty-state>
                @else
                    <div class="text-center py-8 text-gray-400 dark:text-gray-500 text-sm">
                        {{ __('filament-workflows::workflows.messages.select_trigger_first') }}
                    </div>
                @endif
            @endif
        </div>

        {{-- Validation Status --}}
        <div class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between text-sm">
                <div class="flex items-center gap-2">
                    @if ($this->isWorkflowValid())
                        <span class="inline-flex items-center gap-1.5 text-success-600 dark:text-success-400">
                            <x-filament::icon icon="heroicon-o-check-circle" class="w-4 h-4"/>
                            {{ __('filament-workflows::workflows.messages.workflow_valid') }}
                        </span>
                    @elseif($this->trigger || count($this->workflowActions) > 0)
                        <span class="inline-flex items-center gap-1.5 text-warning-600 dark:text-warning-400">
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-4 h-4"/>
                            {{ __('filament-workflows::workflows.messages.configure_all_steps') }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 text-gray-500 dark:text-gray-400">
                            <x-filament::icon icon="heroicon-o-information-circle" class="w-4 h-4"/>
                            {{ __('filament-workflows::workflows.messages.add_trigger_and_actions') }}
                        </span>
                    @endif
                </div>

                <div class="flex items-center gap-3">
                    <div class="text-xs text-gray-400 dark:text-gray-500">
                        {{ __('filament-workflows::workflows.messages.action_count', ['count' => count($this->workflowActions)]) }}
                    </div>
                </div>
            </div>

                </div>
                </div>
            </main>

        </div>
    </div>

    <div class="mt-6 flex justify-end gap-x-3">
        <x-filament::button type="submit">
            {{ $submitLabel }}
        </x-filament::button>
    </div>
</div>
