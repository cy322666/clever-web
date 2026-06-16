@php
    $payload = $preview['payload'] ?? [];
    $query = $preview['query'] ?? [];
    $headers = $preview['headers'] ?? [];
    $variables = $preview['variables'] ?? [];
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $queryJson = json_encode($query, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $headersJson = json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $variablesByGroup = collect([
        [
            'title' => 'Хедеры',
            'items' => collect($variables)
                ->filter(fn(array $variable): bool => str_starts_with((string)($variable['path'] ?? ''), 'headers.'))
                ->values(),
        ],
        [
            'title' => 'Системные',
            'items' => collect($variables)
                ->filter(fn(array $variable): bool => in_array((string)($variable['path'] ?? ''), [
                    'method',
                    'url',
                    'path',
                    'ip',
                    'received_at',
                ], true))
                ->values(),
        ],
        [
            'title' => 'Query-параметры',
            'items' => collect($variables)
                ->filter(fn(array $variable): bool => str_starts_with((string)($variable['path'] ?? ''), 'query.'))
                ->values(),
        ],
        [
            'title' => 'Body-параметры',
            'items' => collect($variables)
                ->filter(fn(array $variable): bool => str_starts_with((string)($variable['path'] ?? ''), 'payload.')
                    || str_starts_with((string)($variable['path'] ?? ''), 'body.'))
                ->values(),
        ],
    ])->filter(fn(array $group): bool => $group['items']->isNotEmpty())->values();
@endphp

<div
    class="space-y-4"
    x-data="{
        copied: null,
        copy(value) {
            navigator.clipboard?.writeText(value);
            this.copied = value;
            setTimeout(() => this.copied = null, 1200);
        },
    }"
>
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="text-sm font-semibold text-gray-950 dark:text-white">Приемщик webhook</div>
                <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Отправьте запрос на URL ниже. Панель обновляется автоматически.
                </div>
            </div>

            <div class="inline-flex items-center gap-2 rounded-full bg-sky-50 px-3 py-1 text-xs font-medium text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">
                <span class="h-2 w-2 animate-pulse rounded-full bg-sky-500"></span>
                Ожидание запроса
            </div>
        </div>

        @if($url)
            <button
                type="button"
                class="mt-4 inline-flex max-w-full items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-medium text-gray-800 transition hover:border-sky-300 hover:bg-sky-50 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:hover:border-sky-500/60 dark:hover:bg-sky-500/10"
                x-on:click="copy(@js($url))"
            >
                <x-filament::icon icon="heroicon-o-clipboard-document" class="h-4 w-4 text-gray-500 dark:text-gray-400" />
                <span>Скопировать URL webhook</span>
            </button>
            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Полный адрес скрыт, чтобы не ломать панель.
            </div>
            <div class="mt-1 text-xs text-emerald-600 dark:text-emerald-300" x-show="copied === @js($url)" x-cloak>
                URL webhook скопирован
            </div>
        @else
            <div class="mt-4 rounded-lg border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                URL появится после создания процесса.
            </div>
        @endif
    </div>

    @if(!$preview)
        <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300">
            Последнего запроса пока нет. Откройте эту панель и отправьте webhook на URL процесса.
        </div>
    @else
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-500/30 dark:bg-emerald-500/10">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div class="text-sm font-semibold text-emerald-900 dark:text-emerald-100">
                    Последний запрос получен
                </div>
                <div class="text-xs text-emerald-700 dark:text-emerald-300">
                    {{ $preview['method'] ?? 'REQUEST' }} · {{ $preview['received_at'] ?? '' }}
                </div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">Тело запроса</div>
                <pre class="max-h-80 overflow-auto rounded-lg bg-gray-950 p-3 text-xs leading-5 text-gray-100">{{ $payloadJson ?: '{}' }}</pre>
            </div>

            <div class="space-y-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">Query-параметры</div>
                    <pre class="max-h-36 overflow-auto rounded-lg bg-gray-950 p-3 text-xs leading-5 text-gray-100">{{ $queryJson ?: '{}' }}</pre>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">Заголовки</div>
                    <pre class="max-h-36 overflow-auto rounded-lg bg-gray-950 p-3 text-xs leading-5 text-gray-100">{{ $headersJson ?: '{}' }}</pre>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div class="text-sm font-semibold text-gray-950 dark:text-white">Переменные из запроса</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Нажмите, чтобы скопировать</div>
            </div>

            @if(count($variables) === 0)
                <div class="rounded-lg border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    В запросе нет простых значений для переменных.
                </div>
            @else
                <div class="space-y-4">
                    @foreach($variablesByGroup as $group)
                        <div>
                            <div class="mb-2 flex items-center gap-2">
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ $group['title'] }}
                                </div>
                                <div class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                    {{ $group['items']->count() }}
                                </div>
                            </div>

                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach($group['items'] as $variable)
                                    <button
                                        type="button"
                                        class="group rounded-lg border border-gray-200 px-3 py-2 text-left transition hover:border-sky-300 hover:bg-sky-50 dark:border-gray-700 dark:hover:border-sky-500/60 dark:hover:bg-sky-500/10"
                                        x-on:click="copy(@js($variable['mask']))"
                                    >
                                        <div class="font-mono text-xs font-semibold text-sky-700 dark:text-sky-300">
                                            {{ $variable['mask'] }}
                                        </div>
                                        <div class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">
                                            {{ $variable['value'] }}
                                        </div>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-2 text-xs text-emerald-600 dark:text-emerald-300" x-show="copied && copied.startsWith('{{')" x-cloak>
                    Переменная скопирована
                </div>
            @endif
        </div>
    @endif
</div>
