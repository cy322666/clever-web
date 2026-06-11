@php
    $statusValue = $run->status?->value ?? (string) $run->status;
    $durationSeconds = $run->getDurationInSeconds();
    $duration = match (true) {
        $durationSeconds === null => '-',
        $durationSeconds < 60 => $durationSeconds . ' сек.',
        default => floor($durationSeconds / 60) . ' мин. ' . ($durationSeconds % 60) . ' сек.',
    };

    $actionLabel = static fn (?string $type): string => [
        'control-condition' => 'Условие',
        'run_workflow' => 'Запустить процесс',
        'amocrm_create_lead' => 'Создать сделку',
        'amocrm_create_contact' => 'Создать контакт',
        'amocrm_create_company' => 'Создать компанию',
        'amocrm_copy_lead' => 'Копировать сделку',
        'amocrm_update_fields' => 'Сменить значение поля',
        'amocrm_update_lead_fields' => 'Изменить сделку',
        'amocrm_update_contact_fields' => 'Изменить контакт',
        'amocrm_update_company_fields' => 'Изменить компанию',
        'amocrm_create_task' => 'Поставить задачу',
        'amocrm_add_note' => 'Добавить примечание',
        'amocrm_change_tags' => 'Сменить теги',
        'amocrm_change_lead_status' => 'Сменить статус сделки',
        'amocrm_find_entity' => 'Найти сущность',
        'amocrm_link_entity' => 'Прикрепить сущность',
        'amocrm_unlink_entity' => 'Открепить сущность',
    ][$type ?? ''] ?? ($type ?: 'Действие');
@endphp

<div class="space-y-5">
    <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4 dark:border-gray-700 dark:bg-gray-950/40">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Статус</div>
                <div class="mt-1">
                    <span @class([
                        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset',
                        'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20' => in_array($statusValue, ['pending', 'cancelled'], true),
                        'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/20' => $statusValue === 'running',
                        'bg-yellow-50 text-yellow-700 ring-yellow-600/20 dark:bg-yellow-400/10 dark:text-yellow-400 dark:ring-yellow-400/20' => $statusValue === 'paused',
                        'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-400/10 dark:text-green-400 dark:ring-green-400/20' => $statusValue === 'completed',
                        'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-400/10 dark:text-red-400 dark:ring-red-400/20' => $statusValue === 'failed',
                    ])>
                        @if($run->status?->getIcon())
                            <x-filament::icon :icon="$run->status->getIcon()" class="h-4 w-4"/>
                        @endif
                        {{ $run->status?->getLabel() ?? $statusValue }}
                    </span>
                </div>
            </div>

            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Источник</div>
                <div class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ $run->trigger_source?->getLabel() ?? '-' }}
                </div>
            </div>

            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Старт</div>
                <div class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ $run->started_at?->format('d.m.Y H:i:s') ?? 'не запущен' }}
                </div>
            </div>

            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Длительность
                </div>
                <div class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ $duration }}
                </div>
            </div>
        </div>

        @if($run->error_message)
            <div
                class="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
                <div class="flex gap-2">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0"/>
                    <div>{{ $run->error_message }}</div>
                </div>
            </div>
        @endif
    </div>

    <div>
        <div class="mb-3 flex items-center justify-between gap-3">
            <h4 class="text-sm font-semibold text-gray-950 dark:text-white">
                Timeline запуска
            </h4>
            <span class="text-xs text-gray-500 dark:text-gray-400">
                {{ $run->steps->count() }} шаг(ов)
            </span>
        </div>

        <div class="space-y-3">
            @forelse($run->steps as $index => $step)
                @php
                    $stepStatus = $step->status?->value ?? (string) $step->status;
                    $type = $step->action_type ?? $step->step_type;
                @endphp

                <div @class([
                    'rounded-xl border bg-white p-4 shadow-sm dark:bg-gray-900',
                    'border-gray-200 dark:border-gray-700' => in_array($stepStatus, ['pending', 'skipped'], true),
                    'border-blue-200 bg-blue-50/50 dark:border-blue-900/50 dark:bg-blue-950/20' => $stepStatus === 'running',
                    'border-green-200 bg-green-50/50 dark:border-green-900/50 dark:bg-green-950/20' => $stepStatus === 'completed',
                    'border-red-200 bg-red-50/50 dark:border-red-900/50 dark:bg-red-950/20' => $stepStatus === 'failed',
                ])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-start gap-3">
                            <span
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-600 dark:bg-gray-800 dark:text-gray-300">
                                {{ $index + 1 }}
                            </span>

                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h5 class="text-sm font-semibold text-gray-950 dark:text-white">
                                        {{ $actionLabel($type) }}
                                    </h5>

                                    <span
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500 dark:bg-gray-800 dark:text-gray-400">
                                        {{ $step->step_id }}
                                    </span>
                                </div>

                                @if($step->error_message)
                                    <div
                                        class="mt-2 rounded-lg bg-red-100 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">
                                        {{ $step->error_message }}
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <span @class([
                                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold',
                                'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' => in_array($stepStatus, ['pending', 'skipped'], true),
                                'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300' => $stepStatus === 'running',
                                'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300' => $stepStatus === 'completed',
                                'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' => $stepStatus === 'failed',
                            ])>
                                {{ $step->status?->getLabel() ?? $stepStatus }}
                            </span>

                            @if($step->duration_ms !== null)
                                <span
                                    class="text-xs text-gray-500 dark:text-gray-400">{{ $step->duration_ms }} мс</span>
                            @endif
                        </div>
                    </div>

                    @if($step->input_data || $step->output_data)
                        <div class="mt-4 grid gap-3 lg:grid-cols-2">
                            @if($step->input_data)
                                <details
                                    class="rounded-lg border border-slate-200 bg-white/70 p-3 dark:border-gray-700 dark:bg-gray-950/40">
                                    <summary
                                        class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Входные данные
                                    </summary>
                                    <pre
                                        class="mt-2 max-h-44 overflow-auto rounded-md bg-slate-50 p-2 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ json_encode($step->input_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @endif

                            @if($step->output_data)
                                <details
                                    class="rounded-lg border border-slate-200 bg-white/70 p-3 dark:border-gray-700 dark:bg-gray-950/40"
                                    open>
                                    <summary
                                        class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Результат шага
                                    </summary>
                                    <pre
                                        class="mt-2 max-h-44 overflow-auto rounded-md bg-slate-50 p-2 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ json_encode($step->output_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-gray-300 p-6 text-center dark:border-gray-700">
                    <x-filament::icon icon="heroicon-o-list-bullet" class="mx-auto h-8 w-8 text-gray-400"/>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">По этому запуску ещё нет шагов.</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
