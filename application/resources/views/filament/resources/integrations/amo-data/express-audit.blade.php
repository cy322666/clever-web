@php
    $audit = $audit ?? null;

    $toneClasses = [
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-700/60 dark:bg-emerald-900/20 dark:text-emerald-300',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-700/60 dark:bg-amber-900/20 dark:text-amber-300',
        'danger' => 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-700/60 dark:bg-rose-900/20 dark:text-rose-300',
    ];

    $chipClasses = [
        'success' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
        'warning' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
        'danger' => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300',
    ];
@endphp

@if (!is_array($audit) || !($audit['ready'] ?? false))
    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900/40">
        <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $audit['title'] ?? 'Экспресс-аудит пока недоступен' }}</p>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ $audit['message'] ?? 'Запустите выгрузку данных, чтобы увидеть показатели.' }}</p>
    </div>
@else
    <div class="space-y-4">
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Итоговый
                    Score</p>
                <div class="mt-3 flex items-end gap-3">
                    <p class="text-4xl font-semibold leading-none text-slate-900 dark:text-white">{{ data_get($audit, 'score.value', 0) }}</p>
                    <span
                        class="rounded-full px-2 py-1 text-xs font-medium {{ $chipClasses[data_get($audit, 'score.tone', 'warning')] ?? $chipClasses['warning'] }}">
                        {{ data_get($audit, 'score.label', 'Есть зона роста') }}
                    </span>
                </div>

                <div class="mt-4 h-2 rounded-full bg-slate-100 dark:bg-slate-800">
                    <div
                        class="h-2 rounded-full {{ data_get($audit, 'score.value', 0) >= 75 ? 'bg-emerald-500' : (data_get($audit, 'score.value', 0) >= 50 ? 'bg-amber-500' : 'bg-rose-500') }}"
                        style="width: {{ max(0, min(100, (int) data_get($audit, 'score.value', 0))) }}%"
                    ></div>
                </div>
            </div>

            <div
                class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900 lg:col-span-2">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Срез базы</p>

                <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Всего сделок</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">{{ number_format((int) data_get($audit, 'summary.leads', 0), 0, '.', ' ') }}</p>
                    </div>

                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Активные сделки</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">{{ number_format((int) data_get($audit, 'summary.open_leads', 0), 0, '.', ' ') }}</p>
                    </div>

                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Всего задач</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">{{ number_format((int) data_get($audit, 'summary.tasks', 0), 0, '.', ' ') }}</p>
                    </div>

                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Открытая сумма</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">{{ number_format((int) data_get($audit, 'summary.open_revenue', 0), 0, '.', ' ') }}
                            ₽</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">5 ключевых метрик</p>

            <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                @foreach (data_get($audit, 'metrics', []) as $metric)
                    <div
                        class="rounded-lg border p-3 {{ $toneClasses[$metric['tone'] ?? 'warning'] ?? $toneClasses['warning'] }}">
                        <p class="text-xs font-medium uppercase tracking-wide">{{ $metric['label'] ?? 'Метрика' }}</p>
                        <p class="mt-2 text-2xl font-semibold leading-none">{{ $metric['value'] ?? '0' }}</p>
                        <p class="mt-2 text-xs">{{ $metric['description'] ?? '' }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Топ-3 проблем</p>

                <div class="mt-3 space-y-3">
                    @forelse (data_get($audit, 'problems', []) as $problem)
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ $problem['title'] ?? 'Проблема' }}</p>
                            <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">{{ $problem['description'] ?? '' }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-slate-600 dark:text-slate-300">Критичных проблем не обнаружено.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Топ-3 действия на 7 дней</p>

                <div class="mt-3 space-y-3">
                    @foreach (data_get($audit, 'actions', []) as $action)
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ $action['title'] ?? 'Действие' }}</p>
                            <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">{{ $action['description'] ?? '' }}</p>
                            <p class="mt-2 text-xs font-medium text-slate-800 dark:text-slate-200">
                                Эффект: {{ $action['effect'] ?? '' }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Потенциал допродажи и перевнедрения</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-white">{{ number_format((int) data_get($audit, 'potential.amount', 0), 0, '.', ' ') }}
                ₽</p>
            <p class="mt-2 text-xs text-slate-600 dark:text-slate-300">
                Потенциально возвращаемых
                сделок: {{ number_format((int) data_get($audit, 'potential.recoverable_deals', 0), 0, '.', ' ') }}.
                {{ data_get($audit, 'potential.note', '') }}
            </p>
        </div>
    </div>
@endif
