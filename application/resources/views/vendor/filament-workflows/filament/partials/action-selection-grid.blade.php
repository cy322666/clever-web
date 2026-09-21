@props([
    'actions' => [],
])

@php
    $currentInsertPath = (string) (($this->insertActionPath ?? null) ?? ($this->targetPath ?? ''));
    $isInsideConditionBranch = str_contains($currentInsertPath, '.config.true_actions')
        || str_contains($currentInsertPath, '.config.false_actions')
        || str_starts_with($currentInsertPath, 'config.true_actions')
        || str_starts_with($currentInsertPath, 'config.false_actions');

    $groups = [
        'flow' => [
            'title' => 'Логика',
            'description' => 'Условия и запуск другого процесса.',
            'icon' => 'heroicon-o-adjustments-horizontal',
            'types' => ['control-condition', 'run_workflow'],
            'items' => [],
        ],
        'communication' => [
            'title' => 'Уведомления',
            'description' => 'Сообщения в выбранные каналы.',
            'icon' => 'heroicon-o-bell-alert',
            'types' => ['send_notification', 'send_email'],
            'items' => [],
        ],
        'entities' => [
            'title' => 'Создание сущностей',
            'description' => 'Сделки, контакты, компании и копии сделок.',
            'icon' => 'heroicon-o-rectangle-stack',
            'types' => ['amocrm_create_lead', 'amocrm_create_contact', 'amocrm_create_company', 'amocrm_copy_lead'],
            'items' => [],
        ],
        'fields' => [
            'title' => 'Поля и данные',
            'description' => 'Запись, изменение и вычисление значений.',
            'icon' => 'heroicon-o-pencil-square',
            'types' => [
                'amocrm_update_lead_fields',
                'amocrm_update_contact_fields',
                'amocrm_update_company_fields',
                'amocrm_normalize_contact_data',
            ],
            'items' => [],
        ],
        'tasks' => [
            'title' => 'Задачи и примечания',
            'description' => 'Поставить задачу или добавить примечание.',
            'icon' => 'heroicon-o-clipboard-document-check',
            'types' => ['amocrm_create_task', 'amocrm_update_task', 'amocrm_add_note'],
            'items' => [],
        ],
        'automation' => [
            'title' => 'Автоматизация',
            'description' => 'Статусы, теги, распределение и SalesBot.',
            'icon' => 'heroicon-o-bolt',
            'types' => [
                'amocrm_change_tags',
                'amocrm_change_lead_status',
                'amocrm_distribution_queue',
                'amocrm_start_salesbot',
                'amocrm_stop_salesbot',
                'amocrm_manage_subscription',
            ],
            'items' => [],
        ],
        'products' => [
            'title' => 'Товары',
            'description' => 'Добавление и удаление товаров.',
            'icon' => 'heroicon-o-shopping-bag',
            'types' => ['amocrm_add_products', 'amocrm_remove_products'],
            'items' => [],
        ],
        'relations' => [
            'title' => 'Поиск и связи',
            'description' => 'Найти сущность, связать или отвязать её.',
            'icon' => 'heroicon-o-link',
            'types' => ['amocrm_find_entity', 'amocrm_link_entity', 'amocrm_unlink_entity'],
            'items' => [],
        ],
        'service' => [
            'title' => 'Служебные',
            'description' => 'Управление отложенными действиями.',
            'icon' => 'heroicon-o-wrench-screwdriver',
            'types' => ['amocrm_cancel_delayed_action'],
            'items' => [],
        ],
        'other' => [
            'title' => 'Другое',
            'description' => 'Остальные доступные действия.',
            'icon' => 'heroicon-o-squares-2x2',
            'types' => [],
            'items' => [],
        ],
    ];

    $typeToGroup = [];

    foreach ($groups as $groupKey => $group) {
        foreach ($group['types'] as $type) {
            $typeToGroup[$type] = $groupKey;
        }
    }

    $unsupportedActionTypes = \App\Workflows\Actions\WorkflowAmoCrmActionCatalog::unsupportedWorkflowTypes();
    $mvpActionTypes = [
        'control-condition',
        'amocrm_create_company',
        'amocrm_update_lead_fields',
        'amocrm_change_lead_status',
        'amocrm_create_task',
        'amocrm_add_note',
        'amocrm_link_entity',
    ];

    foreach ($actions as $action) {
        $type = (string) ($action['type'] ?? '');

        if ($type === ''
            || ! in_array($type, $mvpActionTypes, true)
            || in_array($type, $unsupportedActionTypes, true)) {
            continue;
        }

        if ($isInsideConditionBranch && $type === 'control-condition') {
            continue;
        }

        $groups[$typeToGroup[$type] ?? 'other']['items'][] = $action;
    }

    $groups = array_filter($groups, static fn (array $group): bool => count($group['items']) > 0);
    $visibleActionCount = array_sum(array_map(static fn (array $group): int => count($group['items']), $groups));
    $searchIndex = [];

    foreach ($groups as $groupKey => $group) {
        foreach ($group['items'] as $action) {
            $searchIndex[] = [
                'category' => $groupKey,
                'text' => implode(' ', [
                    $action['name'] ?? '',
                    $action['description'] ?? '',
                    $action['type'] ?? '',
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
    class="workflow-action-palette"
>
    <div class="workflow-palette__header">
        <div class="workflow-palette__intro">
            <div>
                <div class="workflow-palette__eyebrow">Следующий шаг</div>
                <h3 class="workflow-palette__title">Что должен сделать процесс?</h3>
                <p class="workflow-palette__description">Найдите действие и добавьте его в выбранное место.</p>
            </div>

            <span class="workflow-palette__count">{{ $visibleActionCount }} действий</span>
        </div>

        <label class="workflow-palette__search">
            <span class="sr-only">Найти действие</span>
            <x-filament::icon icon="heroicon-o-magnifying-glass" class="h-4 w-4"/>
            <input
                x-ref="search"
                x-model.debounce.100ms="query"
                type="search"
                placeholder="Найти действие: задача, статус, поле…"
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

        <div class="workflow-palette__filters" role="group" aria-label="Категории действий">
            <button
                type="button"
                x-on:click="category = 'all'"
                x-bind:class="{ 'is-active': category === 'all' }"
                x-bind:aria-pressed="category === 'all'"
            >
                Все
                <span>{{ $visibleActionCount }}</span>
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
                    @foreach($group['items'] as $action)
                        @php
                            $type = (string) ($action['type'] ?? '');
                            $name = (string) ($action['name'] ?? __('filament-workflows::workflows.builder.selection.unknown'));
                            $description = trim(strip_tags((string) ($action['description'] ?? '')));
                            $icon = $action['icon'] ?? 'heroicon-o-cog-6-tooth';
                            $color = $action['color'] ?? '#6B7280';
                            $available = (bool) ($action['available'] ?? true);
                            $itemSearchText = implode(' ', [$name, $description, $type]);
                        @endphp

                        <button
                            x-cloak
                            x-show="itemMatches(@js($groupKey), @js($itemSearchText))"
                            type="button"
                            wire:click="{{ $available ? 'selectActionType' : 'showUnavailableAction' }}('{{ $type }}')"
                            wire:loading.attr="disabled"
                            @class([
                                'workflow-palette-card',
                                'workflow-palette-card--unavailable' => ! $available,
                            ])
                        >
                            <span class="workflow-palette-card__icon" style="background-color: {{ $color }}18;">
                                @svg($icon, 'h-4 w-4', ['style' => 'color: ' . $color])
                            </span>

                            <span class="workflow-palette-card__content">
                                <span class="workflow-palette-card__name">{{ $name }}</span>
                                @if($description !== '')
                                    <span class="workflow-palette-card__description">{{ $description }}</span>
                                @endif
                                @unless($available)
                                    <span class="workflow-palette-card__status">Пока недоступно</span>
                                @endunless
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
