@php
    $statusColors = [
        'simulated' => 'text-warning-600 dark:text-warning-400',
        'completed' => 'text-success-600 dark:text-success-400',
        'evaluated' => 'text-info-600 dark:text-info-400',
        'error' => 'text-danger-600 dark:text-danger-400',
        'validation_error' => 'text-danger-600 dark:text-danger-400',
    ];

    $status = $step['status'] ?? 'simulated';
    $statusColor = $statusColors[$status] ?? $statusColors['simulated'];
    $statusLabel = [
        'simulated' => 'Будет выполнено',
        'completed' => 'Выполнено',
        'evaluated' => 'Проверено',
        'error' => 'Ошибка',
        'validation_error' => 'Ошибка настройки',
    ][$status] ?? $status;

    $isCondition = ($step['type'] ?? '') === 'condition' || ($step['type'] ?? '') === 'control-condition';
    $isSideEffect = $step['is_side_effect'] ?? false;
    $paddingLeft = $depth * 1.25;
@endphp

<div class="relative" style="padding-left: {{ $paddingLeft }}rem;">
    <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex items-start gap-3">
            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full
                @if($isCondition)
                    bg-info-100 text-info-600 dark:bg-info-900/30 dark:text-info-400
                @elseif($isSideEffect)
                    bg-warning-100 text-warning-600 dark:bg-warning-900/30 dark:text-warning-400
                @else
                    bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400
                @endif
                text-xs font-semibold">
                @if($isCondition)
                    <x-filament::icon icon="heroicon-o-question-mark-circle" class="h-4 w-4"/>
                @elseif($isSideEffect)
                    <x-filament::icon icon="heroicon-o-shield-check" class="h-4 w-4"/>
                @else
                    {{ $index + 1 }}
                @endif
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-semibold text-gray-950 dark:text-white">
                        {{ $step['name'] ?? $step['type'] ?? 'Шаг процесса' }}
                    </span>

                    <span class="text-sm font-semibold {{ $statusColor }}">
                        {{ $statusLabel }}
                    </span>

                    @if($isSideEffect)
                        <span
                            class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/20 dark:text-amber-400">
                            Без внешнего вызова
                        </span>
                    @endif
                </div>

                @if(!empty($step['description']))
                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                        {{ $step['description'] }}
                    </p>
                @endif

                @if(!empty($step['error']))
                    <p class="mt-2 rounded-lg bg-danger-50 px-3 py-2 text-xs text-danger-700 dark:bg-danger-950/30 dark:text-danger-300">
                        {{ $step['error'] }}
                    </p>
                @endif

                @if($isCondition)
                    <div class="mt-3 rounded-lg bg-gray-50 p-2 dark:bg-gray-950/50">
                        <div class="flex items-center gap-2 text-xs">
                            <span class="text-gray-500 dark:text-gray-400">Результат:</span>
                            @if($step['condition_result'] ?? false)
                                <span
                                    class="inline-flex items-center gap-1 font-semibold text-success-600 dark:text-success-400">
                                    <x-filament::icon icon="heroicon-o-check" class="h-3 w-3"/>
                                    пойдёт ветка «Да»
                                </span>
                            @else
                                <span
                                    class="inline-flex items-center gap-1 font-semibold text-danger-600 dark:text-danger-400">
                                    <x-filament::icon icon="heroicon-o-x-mark" class="h-3 w-3"/>
                                    пойдёт ветка «Нет»
                                </span>
                            @endif
                        </div>
                    </div>

                    @if(!empty($step['true_branch']) && ($step['condition_result'] ?? false))
                        <div class="mt-3 border-l-2 border-success-200 pl-4 dark:border-success-800">
                            <span class="mb-2 block text-xs font-semibold text-success-600 dark:text-success-400">Ветка «Да»</span>
                            <div class="space-y-2">
                                @foreach($step['true_branch'] as $branchIndex => $branchStep)
                                    @include('filament-workflows::filament.partials.test-step-result', [
                                        'step' => $branchStep,
                                        'index' => $branchIndex,
                                        'depth' => $depth + 1
                                    ])
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if(!empty($step['false_branch']) && !($step['condition_result'] ?? false))
                        <div class="mt-3 border-l-2 border-danger-200 pl-4 dark:border-danger-800">
                            <span class="mb-2 block text-xs font-semibold text-danger-600 dark:text-danger-400">Ветка «Нет»</span>
                            <div class="space-y-2">
                                @foreach($step['false_branch'] as $branchIndex => $branchStep)
                                    @include('filament-workflows::filament.partials.test-step-result', [
                                        'step' => $branchStep,
                                        'index' => $branchIndex,
                                        'depth' => $depth + 1
                                    ])
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif

                @if(!empty($step['output']['affected_entities']) && !$isCondition)
                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-2">
                            @foreach($step['output']['affected_entities'] as $entity)
                                @if(!empty($entity['url']) && !empty($entity['id']))
                                    <a
                                        href="{{ $entity['url'] }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1.5 text-sm font-medium text-primary-600 hover:text-primary-500 hover:underline dark:text-primary-400 dark:hover:text-primary-300"
                                    >
                                        <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="h-4 w-4"/>
                                        {{ $entity['label'] ?? $entity['type'] ?? 'amoCRM' }} #{{ $entity['id'] }}
                                    </a>
                                @endif
                            @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
