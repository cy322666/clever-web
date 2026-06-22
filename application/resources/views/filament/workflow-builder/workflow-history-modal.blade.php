@php
    use App\Filament\WorkflowBuilder\Resources\WorkflowRunResource;

    $statusClasses = [
        'completed' => 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-400/10 dark:text-green-300 dark:ring-green-400/20',
        'failed' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-400/10 dark:text-red-300 dark:ring-red-400/20',
        'running' => 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-400/10 dark:text-blue-300 dark:ring-blue-400/20',
        'pending' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20',
        'paused' => 'bg-slate-50 text-slate-700 ring-slate-600/20 dark:bg-slate-400/10 dark:text-slate-300 dark:ring-slate-400/20',
        'cancelled' => 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-300 dark:ring-gray-400/20',
    ];
@endphp

<div class="space-y-4">
    <div>
        <div>
            <div class="text-sm text-gray-500 dark:text-gray-400">Сценарий</div>
            <div class="text-base font-semibold text-gray-950 dark:text-white">
                {{ $workflow?->name ?? 'Процесс' }}
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950">
        @if($runs->isEmpty())
            <div class="flex min-h-48 flex-col items-center justify-center gap-2 px-6 py-10 text-center">
                <div class="rounded-full bg-gray-100 p-3 text-gray-400 dark:bg-gray-900 dark:text-gray-500">
                    <x-filament::icon icon="heroicon-o-play-circle" class="h-7 w-7"/>
                </div>
                <div class="text-sm font-semibold text-gray-950 dark:text-white">Запусков пока нет</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Когда сценарий выполнится, история появится здесь.</div>
            </div>
        @else
            <div class="max-h-[70vh] overflow-auto">
                <table class="w-full table-fixed divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead class="sticky top-0 z-10 bg-gray-50/95 backdrop-blur dark:bg-gray-900/95">
                    <tr>
                        <th class="w-44 whitespace-nowrap px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Дата</th>
                        <th class="w-40 px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Инициатор</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Блок / шаг</th>
                        <th class="w-40 whitespace-nowrap px-4 py-3 text-center font-semibold text-gray-700 dark:text-gray-200">Статус</th>
                        <th class="w-24 whitespace-nowrap px-4 py-3 text-center font-semibold text-gray-700 dark:text-gray-200">Шаги</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                    @foreach($runs as $run)
                        @php
                            $statusValue = $run->status?->value ?? (string) $run->status;
                            $statusLabel = $run->status?->getLabel() ?? $statusValue;
                            $statusIcon = $run->status?->getIcon();
                            $statusClass = $statusClasses[$statusValue] ?? 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-300 dark:ring-gray-400/20';
                        @endphp
                        <tr class="align-middle transition hover:bg-gray-50/70 dark:hover:bg-gray-900/70">
                            <td class="whitespace-nowrap px-4 py-2.5 text-gray-700 dark:text-gray-200">
                                {{ WorkflowRunResource::startedDescription($run) }}
                            </td>
                            <td class="truncate px-4 py-2.5 text-gray-700 dark:text-gray-200">
                                {!! WorkflowRunResource::initiatorHtml($run) !!}
                            </td>
                            <td class="px-4 py-2.5 text-gray-700 dark:text-gray-200">
                                {!! WorkflowRunResource::latestBlockHtml($run) !!}
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span
                                    @if($statusValue === 'failed' && filled($run->error_message))
                                        title="{{ $run->error_message }}"
                                    @endif
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {{ $statusClass }}"
                                >
                                    @if($statusIcon)
                                        <x-filament::icon :icon="$statusIcon" class="h-3.5 w-3.5"/>
                                    @endif
                                    {{ $statusLabel }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-center text-gray-600 dark:text-gray-300">
                                {{ min(((int) $run->current_step_index) + 1, (int) $run->steps_count) }} / {{ (int) $run->steps_count }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
