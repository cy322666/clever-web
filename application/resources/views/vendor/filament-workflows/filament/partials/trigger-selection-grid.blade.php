@props([
    'triggers' => [],
    'compact' => false,
])

@php
    $triggers = array_values(array_filter($triggers, fn (array $trigger): bool => ($trigger['type'] ?? '') !== 'date-condition'));
    $groups = [
        'system' => [
            'title' => 'Запуск и время',
            'description' => 'Вручную, кнопкой в amoCRM, по расписанию или внешним запросом.',
            'icon' => 'heroicon-o-clock',
            'types' => ['manual', 'amo-button', 'schedule', 'workflow-completed', 'generic-webhook'],
            'items' => [],
        ],
        'lead' => [
            'title' => 'Сделки',
            'description' => 'Создание, изменение, статус, ответственный и примечания.',
            'icon' => 'heroicon-o-currency-dollar',
            'types' => [],
            'items' => [],
        ],
        'contact' => [
            'title' => 'Контакты',
            'description' => 'Создание, изменение, удаление, ответственный и примечания.',
            'icon' => 'heroicon-o-user',
            'types' => [],
            'items' => [],
        ],
        'company' => [
            'title' => 'Компании',
            'description' => 'События по компаниям и их ответственным.',
            'icon' => 'heroicon-o-building-office',
            'types' => [],
            'items' => [],
        ],
        'customer' => [
            'title' => 'Покупатели',
            'description' => 'События по периодическим покупателям.',
            'icon' => 'heroicon-o-users',
            'types' => [],
            'items' => [],
        ],
        'task' => [
            'title' => 'Задачи',
            'description' => 'Создание, изменение, удаление и смена ответственного.',
            'icon' => 'heroicon-o-check-circle',
            'types' => [],
            'items' => [],
        ],
        'communication' => [
            'title' => 'Беседы',
            'description' => 'Беседы, чаты и шаблоны сообщений.',
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'types' => [],
            'items' => [],
        ],
        'other' => [
            'title' => 'Другое',
            'description' => 'Остальные доступные события.',
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
        $type = (string) ($trigger['type'] ?? '');
        $groups[$resolveGroup($type)]['items'][] = $trigger;
    }

    $groups = array_filter($groups, static fn (array $group): bool => count($group['items']) > 0);
    $searchIndex = [];

    foreach ($groups as $groupKey => $group) {
        foreach ($group['items'] as $trigger) {
            $type = (string) ($trigger['type'] ?? '');
            $searchIndex[] = [
                'category' => $groupKey,
                'text' => implode(' ', [
                    $trigger['name'] ?? '',
                    $trigger['description'] ?? '',
                    $type,
                    $type === 'amo-button' ? 'кнопка кнопкой amoCRM амо' : '',
                ]),
            ];
        }
    }
@endphp

<div
    x-data="{
        query: '',
        category: 'all',
        items: @js($searchIndex),
        normalize(value) {
            return String(value ?? '').toLocaleLowerCase('ru-RU').trim();
        },
        matchesQuery(haystack) {
            const source = this.normalize(haystack);
            const query = this.normalize(this.query);

            if (query === '') {
                return true;
            }

            return query.split(/\s+/).every((token) =>
                source.includes(token) || (token.length >= 5 && source.includes(token.slice(0, -1)))
            );
        },
        itemMatches(category, haystack) {
            const categoryMatches = this.category === 'all' || this.category === category;

            return categoryMatches && this.matchesQuery(haystack);
        },
        groupMatches(category) {
            return this.items.some((item) => item.category === category && this.itemMatches(item.category, item.text));
        },
        hasMatches() {
            return this.items.some((item) => this.itemMatches(item.category, item.text));
        },
    }"
    x-init="if (window.innerWidth >= 768) $nextTick(() => $refs.search?.focus())"
    @class([
        'workflow-trigger-palette',
        'workflow-trigger-palette--compact' => $compact,
    ])
>
    <div class="workflow-palette__header">
        <div class="workflow-palette__intro">
            <div>
                <div class="workflow-palette__eyebrow">Старт процесса</div>
                <h3 class="workflow-palette__title">Что должно произойти?</h3>
                <p class="workflow-palette__description">Выберите одно событие, которое запустит сценарий.</p>
            </div>

            <span class="workflow-palette__count">{{ count($triggers) }} событий</span>
        </div>

        <label class="workflow-palette__search">
            <span class="sr-only">Найти событие</span>
            <x-filament::icon icon="heroicon-o-magnifying-glass" class="h-4 w-4"/>
            <input
                x-ref="search"
                x-model.debounce.100ms="query"
                type="search"
                placeholder="Найти событие: статус, сделка, задача…"
                autocomplete="off"
            />
            <button
                x-cloak
                x-show="query !== ''"
                x-on:click="query = ''; $nextTick(() => $refs.search?.focus())"
                type="button"
                aria-label="Очистить поиск"
            >
                <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4"/>
            </button>
        </label>

        <div class="workflow-palette__filters" role="group" aria-label="Категории событий">
            <button
                type="button"
                x-on:click="category = 'all'"
                x-bind:class="{ 'is-active': category === 'all' }"
                x-bind:aria-pressed="category === 'all'"
            >
                Все
                <span>{{ count($triggers) }}</span>
            </button>

            @foreach($groups as $groupKey => $group)
                <button
                    type="button"
                    x-on:click="category = @js($groupKey)"
                    x-bind:class="{ 'is-active': category === @js($groupKey) }"
                    x-bind:aria-pressed="category === @js($groupKey)"
                >
                    {{ $group['title'] }}
                    <span>{{ count($group['items']) }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <div class="workflow-palette__body">
        @foreach($groups as $groupKey => $group)
            <section
                x-cloak
                x-show="groupMatches(@js($groupKey))"
                class="workflow-palette__group"
            >
                <div class="workflow-palette__group-heading">
                    <span class="workflow-palette__group-icon">
                        @svg($group['icon'], 'h-4 w-4')
                    </span>
                    <div>
                        <h4>{{ $group['title'] }}</h4>
                        <p>{{ $group['description'] }}</p>
                    </div>
                </div>

                <div class="workflow-palette__grid">
                    @foreach($group['items'] as $trigger)
                        @php
                            $type = (string) ($trigger['type'] ?? '');
                            $name = str($trigger['name'] ?? __('filament-workflows::workflows.builder.selection.unknown'))
                                ->replaceStart('amoCRM: ', '')
                                ->ucfirst()
                                ->toString();
                            $description = trim(strip_tags((string) ($trigger['description'] ?? '')));

                            if ($type === 'manual') {
                                $description = 'Запуск вручную. Настройки не нужны.';
                            }

                            $icon = $trigger['icon'] ?? 'heroicon-o-bolt';
                            $color = $trigger['color'] ?? '#6B7280';
                            $itemSearchText = implode(' ', [$name, $description, $type]);
                        @endphp

                        <button
                            x-cloak
                            x-show="itemMatches(@js($groupKey), @js($itemSearchText))"
                            type="button"
                            wire:click="selectTriggerType('{{ $type }}')"
                            wire:loading.attr="disabled"
                            class="workflow-palette-card"
                        >
                            <span class="workflow-palette-card__icon" style="background-color: {{ $color }}18;">
                                @svg($icon, 'h-4 w-4', ['style' => 'color: ' . $color])
                            </span>

                            <span class="workflow-palette-card__content">
                                <span class="workflow-palette-card__name">{{ $name }}</span>
                                @if($description !== '')
                                    <span class="workflow-palette-card__description">{{ $description }}</span>
                                @endif
                            </span>

                            <x-filament::icon icon="heroicon-m-chevron-right" class="workflow-palette-card__arrow h-4 w-4"/>
                        </button>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div x-cloak x-show="! hasMatches()" class="workflow-palette__empty">
            <x-filament::icon icon="heroicon-o-magnifying-glass" class="h-5 w-5"/>
            <div>
                <strong>Ничего не найдено</strong>
                <span>Попробуйте другое слово или выберите «Все».</span>
            </div>
        </div>
    </div>
</div>
