@props([
    'action',
    'index' => 0,
    'total' => 1,
    'metadata' => [],
    'readOnly' => false,
])

@php
    $actionId = $action['id'] ?? '';
    $actionType = $action['type'] ?? '';
    $actionName = $action['name'] ?? $metadata['name'] ?? __('filament-workflows::workflows.builder.cards.unknown_action');
    $icon = $metadata['icon'] ?? 'heroicon-o-cog-6-tooth';
    $color = $metadata['color'] ?? '#6B7280';
    $category = $metadata['category'] ?? __('filament-workflows::workflows.builder.cards.general_category');
    $config = $action['config'] ?? [];

    $entityLabels = [
        'lead' => 'Сделка',
        'contact' => 'Контакт',
        'company' => 'Компания',
        'customer' => 'Покупатель',
        'task' => 'Задача',
    ];

    $targetEntity = (string) ($config['target_entity'] ?? ($actionType === 'amocrm_change_lead_status' ? 'lead' : ''));
    $targetEntityLabel = $entityLabels[$targetEntity] ?? null;
    $targetEntityId = trim((string) ($config['target_entity_id'] ?? ''));
    $delay = $config['delay'] ?? [];
    $delayMode = $delay['mode'] ?? 'immediate';
    $delayLabel = null;

    if ($delayMode === 'after_seconds' && filled($delay['seconds'] ?? null)) {
        $delayLabel = $delay['seconds'] . ' сек.';
    } elseif ($delayMode === 'date_field' && filled($delay['date_field'] ?? null)) {
        $delayLabel = 'в дату из ' . $delay['date_field'];
    }

    $summaryItems = [];
    $dealSummaryItems = [];

    if ($targetEntityLabel) {
        $summaryItems[] = [
            'icon' => 'heroicon-o-cube',
            'label' => $targetEntityId !== '' ? $targetEntityLabel . ': ' . $targetEntityId : $targetEntityLabel,
        ];
    } elseif ($targetEntityId !== '') {
        $summaryItems[] = [
            'icon' => 'heroicon-o-cube',
            'label' => 'ID: ' . $targetEntityId,
        ];
    }

    if (filled($config['pipeline_id'] ?? null)) {
        $pipelineName = \App\Workflows\Actions\WorkflowAmoCrmActionCatalog::resolvePipelineName($config['pipeline_id']);

        $dealSummaryItems[] = [
            'icon' => 'heroicon-o-funnel',
            'label' => 'Воронка: ' . ($pipelineName ?? 'не найдена'),
        ];
    }

    if (filled($config['status_id'] ?? null)) {
        $statusName = \App\Workflows\Actions\WorkflowAmoCrmActionCatalog::resolveStatusName(
            $config['status_id'],
            $config['pipeline_id'] ?? null,
        );

        $dealSummaryItems[] = [
            'icon' => 'heroicon-o-flag',
            'label' => 'Статус: ' . ($statusName ?? 'не найден'),
        ];
    }

    if (filled($config['subject'] ?? null)) {
        $summaryItems[] = [
            'icon' => 'heroicon-o-envelope',
            'label' => Str::limit((string) $config['subject'], 42),
        ];
    }

    if (filled($config['text'] ?? null)) {
        $summaryItems[] = [
            'icon' => 'heroicon-o-chat-bubble-left-ellipsis',
            'label' => Str::limit((string) $config['text'], 46),
        ];
    }

    if ($actionType === 'run_workflow' && filled($config['workflow_id'] ?? null)) {
        $workflowId = (int) $config['workflow_id'];
        $workflowModel = config('filament-workflows.models.workflow', \Leek\FilamentWorkflows\Models\Workflow::class);
        $workflowName = $workflowModel::query()->whereKey($workflowId)->value('name');

        $summaryItems[] = [
            'icon' => 'heroicon-o-arrow-right-circle',
            'label' => 'Процесс: ' . ($workflowName ?: 'Процесс #' . $workflowId),
            'url' => \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl('edit', ['record' => $workflowId]),
        ];
    }
@endphp

<div
    @if(!$readOnly)
        wire:click="openWorkflowActionEditor('{{ $actionId }}')"
    @endif
    {{ $attributes->class([
        'workflow-card group relative rounded-xl border bg-white p-3 shadow-sm transition-all hover:shadow-md dark:bg-gray-900',
        'cursor-pointer' => !$readOnly,
        'border-gray-200 dark:border-gray-700',
    ]) }}
>
    <div class="flex items-start gap-3">
        <div
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
            style="background-color: {{ $color }}18;"
        >
            <x-filament::icon
                :icon="$icon"
                class="h-4 w-4"
                style="color: {{ $color }};"
            />
        </div>

        <div @class([
            'min-w-0 flex-1',
        ])>
            <div class="flex flex-wrap items-center gap-2">
                <h4 class="text-sm font-semibold leading-5 text-gray-950 dark:text-white">
                    {{ $actionName }}
                </h4>

                @if($category)
                    <span class="text-sm font-medium leading-5 text-gray-500 dark:text-gray-400">
                        {{ $category }}
                    </span>
                @endif
            </div>

            @if(count($summaryItems) > 0 || count($dealSummaryItems) > 0)
                @foreach([$summaryItems, $dealSummaryItems] as $rowItems)
                    @if(count($rowItems) > 0)
                        <div
                            @class([
                                'flex flex-wrap items-center',
                                'mt-2' => $loop->first,
                                'mt-1' => !$loop->first,
                            ])
                            style="column-gap: 1.25rem; row-gap: 0.25rem;"
                        >
                            @foreach($rowItems as $item)
                                @php
                                    $parts = explode(': ', (string) $item['label'], 2);
                                    $labelPrefix = count($parts) === 2 ? $parts[0] . ': ' : null;
                                    $labelValue = count($parts) === 2 ? $parts[1] : $item['label'];
                                @endphp
                                <span
                                    class="inline-flex max-w-full items-center gap-1.5 text-sm font-medium leading-5 text-slate-500 dark:text-gray-400">
                                    <x-filament::icon :icon="$item['icon']" class="h-3.5 w-3.5 shrink-0"/>
                                    <span class="truncate">
                                        @if($labelPrefix)
                                            <span>{{ $labelPrefix }}</span>
                                            @if(filled($item['url'] ?? null))
                                                <a
                                                    href="{{ $item['url'] }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="inline-flex max-w-full items-center gap-1 font-semibold text-primary-700 hover:text-primary-600 dark:text-primary-300 dark:hover:text-primary-200"
                                                    title="Открыть процесс"
                                                    x-on:click.stop
                                                >
                                                    <span class="truncate">{{ $labelValue }}</span>
                                                    <x-filament::icon icon="heroicon-o-arrow-top-right-on-square"
                                                                      class="h-3.5 w-3.5 shrink-0"/>
                                                </a>
                                            @else
                                                <span
                                                    class="font-semibold text-slate-800 dark:text-gray-100">{{ $labelValue }}</span>
                                            @endif
                                        @else
                                            @if(filled($item['url'] ?? null))
                                                <a
                                                    href="{{ $item['url'] }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="inline-flex max-w-full items-center gap-1 font-semibold text-primary-700 hover:text-primary-600 dark:text-primary-300 dark:hover:text-primary-200"
                                                    title="Открыть процесс"
                                                    x-on:click.stop
                                                >
                                                    <span class="truncate">{{ $labelValue }}</span>
                                                    <x-filament::icon icon="heroicon-o-arrow-top-right-on-square"
                                                                      class="h-3.5 w-3.5 shrink-0"/>
                                                </a>
                                            @else
                                                <span
                                                    class="font-semibold text-slate-800 dark:text-gray-100">{{ $labelValue }}</span>
                                            @endif
                                        @endif
                                    </span>
                                </span>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            @else
                <p class="mt-1.5 text-sm leading-5 text-gray-500 dark:text-gray-400">
                    Настройте действие, чтобы оно стало видно в конструкторе и истории запусков.
                </p>
            @endif
        </div>

        <div class="flex shrink-0 flex-col items-end gap-2">
            @if($delayLabel)
                <span class="inline-flex items-center gap-1.5 text-sm font-medium leading-5 text-amber-600 dark:text-amber-300">
                    <x-filament::icon icon="heroicon-o-clock" class="h-3.5 w-3.5 shrink-0"/>
                    {{ $delayLabel }}
                </span>
            @endif

            @if(!$readOnly)
                <div
                    class="flex items-center gap-1 opacity-100 transition-opacity sm:opacity-0 sm:group-hover:opacity-100">
                    <button
                        type="button"
                        wire:click="duplicateWorkflowActionStep('{{ $actionId }}')"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-50"
                        wire:target="duplicateWorkflowActionStep('{{ $actionId }}')"
                        x-on:click.stop
                        class="rounded-md p-1.5 text-gray-400 hover:bg-primary-50 hover:text-primary-600 dark:hover:bg-primary-950 dark:hover:text-primary-400"
                        title="Копировать шаг"
                    >
                        <x-filament::icon
                            icon="heroicon-o-document-duplicate"
                            class="h-4 w-4"
                            wire:loading.remove
                            wire:target="duplicateWorkflowActionStep('{{ $actionId }}')"
                        />
                        <x-filament::loading-indicator
                            class="h-4 w-4"
                            wire:loading
                            wire:target="duplicateWorkflowActionStep('{{ $actionId }}')"
                        />
                    </button>

                    <button
                        type="button"
                        wire:click="removeWorkflowAction('{{ $actionId }}')"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-50"
                        wire:target="removeWorkflowAction('{{ $actionId }}')"
                        wire:confirm="{{ __('filament-workflows::workflows.messages.remove_action_confirmation') }}"
                        x-on:click.stop
                        class="rounded-md p-1.5 text-gray-400 hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-950 dark:hover:text-danger-400"
                        title="{{ __('filament-workflows::workflows.builder.tooltips.remove_action') }}"
                    >
                        <x-filament::icon
                            icon="heroicon-o-trash"
                            class="h-4 w-4"
                            wire:loading.remove
                            wire:target="removeWorkflowAction('{{ $actionId }}')"
                        />
                        <x-filament::loading-indicator
                            class="h-4 w-4"
                            wire:loading
                            wire:target="removeWorkflowAction('{{ $actionId }}')"
                        />
                    </button>
                </div>
            @endif
        </div>
    </div>
</div>
