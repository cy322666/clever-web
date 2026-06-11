@props([
    'type',
    'config' => [],
    'metadata' => [],
    'readOnly' => false,
])

@php
    $name = $metadata['name'] ?? __('filament-workflows::workflows.builder.cards.unknown_trigger');
    $icon = $metadata['icon'] ?? 'heroicon-o-bolt';
    $color = $metadata['color'] ?? '#6B7280';
    $summaryItems = [];

    if ($type === 'workflow-completed' && filled($config['source_workflow_id'] ?? null)) {
        $workflowModel = config('filament-workflows.models.workflow', \App\Models\Workflows\Workflow::class);
        $workflowName = $workflowModel::query()->whereKey($config['source_workflow_id'])->value('name');

        $name = $workflowName ?: 'Процесс #' . $config['source_workflow_id'];
        $icon = 'heroicon-o-arrow-right-circle';
        $color = '#14B8A6';
    }

    if ($type !== 'manual' && filled($config['event'] ?? null)) {
        $summaryItems[] = [
            'icon' => 'heroicon-o-bolt',
            'label' => 'Событие: ' . $config['event'],
        ];
    }

    if ($type !== 'manual' && filled($config['model'] ?? null)) {
        $summaryItems[] = [
            'icon' => 'heroicon-o-cube',
            'label' => class_basename($config['model']),
        ];
    }

    if ($type !== 'manual' && filled($config['schedule'] ?? null)) {
        $summaryItems[] = [
            'icon' => 'heroicon-o-clock',
            'label' => 'Расписание: ' . $config['schedule'],
        ];
    }

    if ($type !== 'manual' && filled($config['date_field'] ?? null)) {
        $summaryItems[] = [
            'icon' => 'heroicon-o-calendar-days',
            'label' => 'Дата: ' . $config['date_field'],
        ];
    }
@endphp

<div
    {{ $attributes->class([
        'workflow-card workflow-trigger-card group relative rounded-xl border bg-white p-4 shadow-sm transition-all hover:shadow-md dark:bg-gray-900',
        'border-primary-300 dark:border-primary-800' => !$readOnly,
        'border-gray-200 dark:border-gray-700' => $readOnly,
    ]) }}
>
    <div class="flex items-start gap-4">
        <div
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl"
            style="background-color: {{ $color }}18;"
        >
            <x-filament::icon
                :icon="$icon"
                class="h-5 w-5"
                style="color: {{ $color }};"
            />
        </div>

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <h4 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    {{ $name }}
                </h4>

                <span class="text-sm font-medium text-primary-600 dark:text-primary-400">
                    Триггер
                </span>
            </div>

            @if(count($summaryItems) > 0)
                <div class="mt-3 flex flex-wrap items-center" style="column-gap: 2rem; row-gap: 0.375rem;">
                    @foreach($summaryItems as $item)
                        @php
                            $parts = explode(': ', (string) $item['label'], 2);
                            $labelPrefix = count($parts) === 2 ? $parts[0] . ': ' : null;
                            $labelValue = count($parts) === 2 ? $parts[1] : $item['label'];
                        @endphp
                        <span
                            class="inline-flex max-w-full items-center gap-1.5 text-sm font-medium text-slate-500 dark:text-gray-400">
                            <x-filament::icon :icon="$item['icon']" class="h-3.5 w-3.5 shrink-0"/>
                            <span class="truncate">
                                @if($labelPrefix)
                                    <span>{{ $labelPrefix }}</span><span
                                        class="font-semibold text-slate-800 dark:text-gray-100">{{ $labelValue }}</span>
                                @else
                                    <span
                                        class="font-semibold text-slate-800 dark:text-gray-100">{{ $labelValue }}</span>
                                @endif
                            </span>
                        </span>
                    @endforeach
                </div>
            @elseif($type !== 'manual')
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Запускает процесс при выбранном событии.
                </p>
            @endif
        </div>

        @if(!$readOnly)
            <div
                class="flex shrink-0 items-center gap-1 opacity-100 transition-opacity sm:opacity-0 sm:group-hover:opacity-100">
                @if($type !== 'manual')
                    <button
                        type="button"
                        wire:click="mountAction('configureTrigger')"
                        class="rounded-md p-1.5 text-gray-400 hover:bg-primary-50 hover:text-primary-600 dark:hover:bg-primary-950 dark:hover:text-primary-400"
                        title="{{ __('filament-workflows::workflows.builder.tooltips.configure_trigger') }}"
                    >
                        <x-filament::icon icon="heroicon-o-pencil" class="h-4 w-4"/>
                    </button>
                @endif

                <button
                    type="button"
                    wire:click="mountAction('changeTrigger')"
                    class="rounded-md p-1.5 text-gray-400 hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-950 dark:hover:text-danger-400"
                    title="{{ __('filament-workflows::workflows.builder.tooltips.delete_trigger') }}"
                >
                    <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4"/>
                </button>
            </div>
        @endif
    </div>
</div>
