@props(['submitLabel' => __('filament-workflows::workflows.actions.save_changes.label')])

<x-filament::section class="mt-6" icon="heroicon-o-bolt" compact="true">
    <x-slot name="heading">
        {{ __('filament-workflows::workflows.builder.heading') }}
    </x-slot>

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

                {{-- Add Action Button (after existing actions) --}}
                @if ($this->trigger)
                    <x-filament-workflows::workflows.connector dashed/>

                    <div class="flex justify-center">
                        <x-filament::button wire:click="mountAction('addWorkflowAction')" icon="heroicon-o-plus"
                                            color="primary">
                            {{ __('filament-workflows::workflows.actions.add_action.label') }}
                        </x-filament::button>
                    </div>
                @endif
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
                    {{-- Test Button --}}
                    @if ($this->trigger && count($this->workflowActions) > 0)
                        <x-filament::button
                            wire:click="mountAction('testWorkflow')"
                            icon="heroicon-o-beaker"
                            color="warning"
                            size="xs"
                        >
                            {{ __('filament-workflows::workflows.actions.test.label') }}
                        </x-filament::button>
                    @endif

                    <div class="text-xs text-gray-400 dark:text-gray-500">
                        {{ __('filament-workflows::workflows.messages.action_count', ['count' => count($this->workflowActions)]) }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-filament::section>

<div class="mt-6 flex justify-end gap-x-3">
    <x-filament::button type="submit">
        {{ $submitLabel }}
    </x-filament::button>
</div>
