@props([
    'triggers' => [],
])

@php
    $groups = [
        'system' => [
            'title' => 'Ручной запуск и время',
            'description' => 'Запуск пользователем, по расписанию или относительно даты.',
            'icon' => 'heroicon-o-clock',
            'types' => ['manual', 'schedule', 'date-condition'],
            'items' => [],
        ],
        'lead' => [
            'title' => 'Сделки',
            'description' => 'Создание, изменение, удаление, статус, ответственный и примечания в сделке.',
            'icon' => 'heroicon-o-currency-dollar',
            'types' => [],
            'items' => [],
        ],
        'contact' => [
            'title' => 'Контакты',
            'description' => 'События по контактам: создание, изменение, удаление, ответственный и примечания.',
            'icon' => 'heroicon-o-user',
            'types' => [],
            'items' => [],
        ],
        'company' => [
            'title' => 'Компании',
            'description' => 'События по компаниям: создание, изменение, удаление, ответственный и примечания.',
            'icon' => 'heroicon-o-building-office',
            'types' => [],
            'items' => [],
        ],
        'customer' => [
            'title' => 'Покупатели',
            'description' => 'Создание, изменение, удаление, ответственный и примечания по покупателям.',
            'icon' => 'heroicon-o-users',
            'types' => [],
            'items' => [],
        ],
        'task' => [
            'title' => 'Задачи',
            'description' => 'Создание, изменение, удаление задачи и смена ответственного.',
            'icon' => 'heroicon-o-check-circle',
            'types' => [],
            'items' => [],
        ],
        'communication' => [
            'title' => 'Беседы и WhatsApp',
            'description' => 'События по беседам и шаблонам сообщений.',
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'types' => [],
            'items' => [],
        ],
        'other' => [
            'title' => 'Другое',
            'description' => 'Остальные доступные триггеры.',
            'icon' => 'heroicon-o-bolt',
            'types' => [],
            'items' => [],
        ],
    ];

    $resolveGroup = static function (string $type) use ($groups): string {
        if (in_array($type, $groups['system']['types'], true)) {
            return 'system';
        }

        return match (true) {
            str_contains($type, '-lead') => 'lead',
            str_contains($type, '-contact') => 'contact',
            str_contains($type, '-company') => 'company',
            str_contains($type, '-customer') => 'customer',
            str_contains($type, '-task') => 'task',
            str_contains($type, '-talk'), str_contains($type, 'chat-template-review') => 'communication',
            default => 'other',
        };
    };

    foreach ($triggers as $trigger) {
        $type = $trigger['type'] ?? '';
        $group = $resolveGroup($type);
        $groups[$group]['items'][] = $trigger;
    }

    $groups = array_filter($groups, static fn (array $group): bool => count($group['items']) > 0);
@endphp

<div class="workflow-trigger-selection space-y-5 p-4">
    @foreach($groups as $group)
        <section
            class="rounded-2xl border border-slate-300 bg-white p-3.5 shadow-sm dark:border-gray-600 dark:bg-gray-900/60">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div class="flex min-w-0 items-center gap-2">
                    <div
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-50 text-primary-600 ring-1 ring-slate-300 dark:bg-gray-950 dark:text-primary-400 dark:ring-gray-600">
                        @svg($group['icon'], 'h-4 w-4')
                    </div>

                    <h3 class="truncate text-sm font-semibold leading-5 text-slate-950 dark:text-white">
                        {{ $group['title'] }}
                    </h3>
                </div>

            </div>

            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($group['items'] as $trigger)
                    @php
                        $type = $trigger['type'] ?? '';
                        $name = str($trigger['name'] ?? __('filament-workflows::workflows.builder.selection.unknown'))
                            ->replaceStart('amoCRM: ', '')
                            ->ucfirst()
                            ->toString();
                        $icon = $trigger['icon'] ?? 'heroicon-o-bolt';
                        $color = $trigger['color'] ?? '#6B7280';
                    @endphp

                    <button
                        type="button"
                        wire:click="selectTriggerType('{{ $type }}')"
                        class="group relative flex min-h-20 items-center gap-2.5 rounded-lg border border-slate-200 bg-white p-3 text-left transition-all hover:border-slate-400 hover:ring-1 hover:ring-slate-300/70 dark:border-gray-700 dark:bg-gray-950/60 dark:hover:border-gray-500"
                    >
                        <div
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md"
                            style="background-color: {{ $color }}20;"
                        >
                            @svg($icon, 'h-4 w-4', ['style' => 'color: ' . $color])
                        </div>

                        <h4 class="line-clamp-2 text-sm font-medium leading-5 text-gray-900 group-hover:text-primary-600 dark:text-white dark:group-hover:text-primary-400">
                            {{ $name }}
                        </h4>
                    </button>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
