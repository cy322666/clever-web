@props([
    'triggers' => [],
    'actions' => [],
    'hasTrigger' => false,
    'maskGroups' => [],
    'systemIdGroups' => [],
])

@php
    $actions = array_map(function (array $action): array {
        $action['icon'] = \App\Services\Workflows\WorkflowAmoIcons::action($action['type'] ?? '', [], $action['icon'] ?? 'heroicon-o-cog-6-tooth');
        return $action;
    }, $actions);
    $triggers = array_values(array_filter($triggers, fn (array $trigger): bool => ! in_array($trigger['type'] ?? '', ['date-condition', 'workflow-completed'], true)));
    $triggerGroups = [
        'system' => [
            'title' => 'Способ запуска',
            'description' => 'Вручную, из воронки, по расписанию или вебхуком',
            'icon' => 'heroicon-o-play-circle',
            'items' => [],
        ],
        'lead' => [
            'title' => 'Сделка',
            'description' => 'Создание, изменение, статус и примечания',
            'icon' => 'heroicon-o-currency-dollar',
            'items' => [],
        ],
        'contact' => [
            'title' => 'Контакт',
            'description' => 'Создание, изменение и примечания',
            'icon' => 'heroicon-o-user',
            'items' => [],
        ],
        'company' => [
            'title' => 'Компания',
            'description' => 'Создание, изменение и примечания',
            'icon' => 'heroicon-o-building-office',
            'items' => [],
        ],
        'customer' => [
            'title' => 'Покупатель',
            'description' => 'События по покупателям',
            'icon' => 'heroicon-o-users',
            'items' => [],
        ],
        'task' => [
            'title' => 'Задача',
            'description' => 'Создание, изменение и ответственный',
            'icon' => 'heroicon-o-check-circle',
            'items' => [],
        ],
        'communication' => [
            'title' => 'Беседы',
            'description' => 'Чаты и шаблоны сообщений',
            'icon' => 'amocrm-imbox',
            'items' => [],
        ],
        'message' => [
            'title' => 'Сообщения',
            'description' => 'Входящие от клиента и исходящие из amoCRM',
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'items' => [],
        ],
        'unsorted' => [
            'title' => 'Неразобранное',
            'description' => 'Добавление, изменение, принятие и отклонение',
            'icon' => 'heroicon-o-inbox-arrow-down',
            'items' => [],
        ],
        'other' => [
            'title' => 'Другое',
            'description' => 'Остальные события amoCRM',
            'icon' => 'heroicon-o-bolt',
            'items' => [],
        ],
    ];
    $systemTriggerTypes = ['manual', 'amo-button', 'digital-pipeline', 'schedule', 'generic-webhook'];

    foreach ($triggers as $trigger) {
        $type = (string) ($trigger['type'] ?? '');
        $trigger['icon'] = \App\Services\Workflows\WorkflowAmoIcons::trigger($type, $trigger['icon'] ?? 'heroicon-o-bolt');
        $group = match (true) {
            in_array($type, $systemTriggerTypes, true) => 'system',
            str_contains($type, '-lead') => 'lead',
            str_contains($type, '-contact') => 'contact',
            str_contains($type, '-company') => 'company',
            str_contains($type, '-customer') => 'customer',
            str_contains($type, '-task') => 'task',
            str_ends_with($type, '-message') => 'message',
            str_ends_with($type, '-unsorted') => 'unsorted',
            str_contains($type, '-talk'), str_contains($type, 'chat-template-review') => 'communication',
            default => 'other',
        };

        $trigger['display_name'] = str($trigger['name'] ?? 'Событие')
            ->replaceStart('amoCRM: ', '')
            ->ucfirst()
            ->toString();
        $trigger['search_text'] = mb_strtolower(implode(' ', [
            $trigger['display_name'],
            strip_tags((string) ($trigger['description'] ?? '')),
            $type,
            $triggerGroups[$group]['title'],
            $type === 'amo-button' ? 'кнопка кнопкой амо amoCRM сделка' : '',
        ]));
        $triggerGroups[$group]['items'][] = $trigger;
    }

    $triggerGroups = array_filter($triggerGroups, static fn (array $group): bool => $group['items'] !== []);
    foreach ($triggerGroups as $key => &$group) $group['icon'] = \App\Services\Workflows\WorkflowAmoIcons::entity($key, $group['icon']);
    unset($group);

    $mvpActionTypes = [
        'control-condition',
        'workflow_javascript',
        'workflow_delay',
        'workflow_filter_list',
        'http_request',
        'amocrm_create_lead',
        'amocrm_create_company',
        'amocrm_start_salesbot',
        'amocrm_update_lead_fields',
        'amocrm_update_contact_fields',
        'amocrm_update_company_fields',
        'amocrm_change_lead_status',
        'amocrm_create_task',
        'amocrm_add_note',
        'amocrm_change_tags',
    ];
    $unsupportedActionTypes = \App\Workflows\Actions\WorkflowAmoCrmActionCatalog::unsupportedWorkflowTypes();
    $queryActionGroups = [
        'amocrm_query_leads' => 'Сделки',
        'amocrm_contact_leads' => 'Сделки',
        'amocrm_get_contact' => 'Контакты',
    ];
    $queryActions = array_values(array_filter($actions, fn (array $action): bool => isset($queryActionGroups[$action['type'] ?? ''])));
    $currentInsertPath = (string) (($this->insertActionPath ?? null) ?? ($this->targetPath ?? ''));
    $insideConditionBranch = str_contains($currentInsertPath, '.config.true_actions')
        || str_contains($currentInsertPath, '.config.false_actions')
        || str_starts_with($currentInsertPath, 'config.true_actions')
        || str_starts_with($currentInsertPath, 'config.false_actions');
    $insertContext = match (true) {
        ($this->insertConnection['sourcePort'] ?? null) === 'yes' => 'Ветка «Да»',
        ($this->insertConnection['sourcePort'] ?? null) === 'no' => 'Ветка «Нет»',
        isset($this->insertConnection) => 'Добавить в соединение',
        str_contains($currentInsertPath, 'true_actions') => 'Ветка «Да»',
        str_contains($currentInsertPath, 'false_actions') => 'Ветка «Нет»',
        default => 'Отдельная нода',
    };
    $actionEntities = [
        'salesbot' => ['title' => 'Salesbot', 'description' => 'Запуск бота', 'icon' => 'heroicon-o-cpu-chip', 'types' => ['amocrm_start_salesbot'], 'items' => []],
        'contact' => ['title' => 'Контакт', 'description' => 'Поля контакта', 'icon' => 'heroicon-o-user', 'types' => ['amocrm_update_contact_fields'], 'items' => []],
        'company' => ['title' => 'Компания', 'description' => 'Создание и поля компании', 'icon' => 'heroicon-o-building-office', 'types' => ['amocrm_create_company', 'amocrm_update_company_fields'], 'items' => []],
        'lead' => [
            'title' => 'Сделка',
            'description' => 'Поля и этап воронки',
            'icon' => 'heroicon-o-currency-dollar',
            'types' => ['amocrm_create_lead', 'amocrm_update_lead_fields', 'amocrm_change_lead_status'],
            'items' => [],
        ],
        'task' => [
            'title' => 'Задача',
            'description' => 'Создать задачу в amoCRM',
            'icon' => 'heroicon-o-clipboard-document-check',
            'types' => ['amocrm_create_task'],
            'items' => [],
        ],
        'note' => [
            'title' => 'Примечание',
            'description' => 'Добавить запись в ленту',
            'icon' => 'heroicon-o-chat-bubble-left-ellipsis',
            'types' => ['amocrm_add_note'],
            'items' => [],
        ],
        'tags' => [
            'title' => 'Теги',
            'description' => 'Добавить, удалить или очистить теги',
            'icon' => 'heroicon-o-tag',
            'types' => ['amocrm_change_tags'],
            'items' => [],
        ],
    ];
    $logicActions = [];
    foreach ($actionEntities as $key => &$group) $group['icon'] = \App\Services\Workflows\WorkflowAmoIcons::entity($key, $group['icon']);
    unset($group);

    foreach ($actions as $action) {
        $type = (string) ($action['type'] ?? '');

        if ($type === ''
            || ! in_array($type, $mvpActionTypes, true)
            || in_array($type, $unsupportedActionTypes, true)
            || (($action['available'] ?? true) === false)) {
            continue;
        }

        $action['search_text'] = mb_strtolower(implode(' ', [
            $action['name'] ?? '',
            strip_tags((string) ($action['description'] ?? '')),
            $type,
            'amoCRM действие узел',
        ]));

        if (in_array($type, ['control-condition', 'workflow_javascript', 'workflow_delay', 'workflow_filter_list', 'http_request'], true)) {
            $logicActions[] = $action;
            continue;
        }

        foreach ($actionEntities as &$entity) {
            if (in_array($type, $entity['types'], true)) {
                $entity['items'][] = $action;
                break;
            }
        }
        unset($entity);
    }

    $actionEntities = array_filter($actionEntities, static fn (array $entity): bool => $entity['items'] !== []);
    $allActions = array_merge($logicActions, ...array_column($actionEntities, 'items'));
@endphp

<aside
    x-data="{
        open: false,
        mode: @js($hasTrigger ? 'action' : 'trigger'),
        view: 'root',
        entity: null,
        query: '',
        normalize(value) { return String(value ?? '').toLocaleLowerCase('ru-RU').trim(); },
        matches(value) {
            const query = this.normalize(this.query);
            const source = this.normalize(value);
            return query === '' || query.split(/\s+/).every((token) => source.includes(token));
        },
        resetView(nextMode = this.mode) {
            this.mode = nextMode;
            this.view = 'root';
            this.entity = null;
            this.query = '';
        },
        openFor(detail = {}) {
            this.open = true;
            this.resetView(detail.mode || 'action');
            this.$nextTick(() => {
                if (this.mode !== 'variables') this.$refs.search?.focus();
            });
        },
        goBack() {
            if (this.mode === 'action' && this.view === 'variants') {
                this.view = 'amocrm';
                this.entity = null;
                return;
            }
            this.view = 'root';
            this.entity = null;
        },
    }"
    x-on:workflow-node-library-open.window="openFor($event.detail)"
    x-on:workflow-masks-open.window="openFor({ mode: 'variables' })"
    x-bind:class="{ 'is-collapsed': ! open }"
    class="workflow-node-library"
>
    <div class="workflow-node-library__rail">
        <a
            href="{{ \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl('index') }}"
            class="workflow-node-library__rail-button"
            aria-label="Сценарии"
            title="Сценарии"
        >
            <x-filament::icon icon="heroicon-o-squares-2x2" class="h-5 w-5"/>
        </a>

        <span class="workflow-node-library__rail-separator" aria-hidden="true"></span>

        <button
            type="button"
            wire:click="beginTriggerAdd"
            x-bind:class="{ 'is-active': mode === 'trigger' }"
            class="workflow-node-library__rail-button"
            aria-label="Триггеры"
            title="Триггеры"
        >
            <x-filament::icon icon="heroicon-o-bolt" class="h-5 w-5"/>
        </button>

        <button
            type="button"
            wire:click="switchActionPalette('action')"
            x-bind:class="{ 'is-active': mode === 'action' }"
            class="workflow-node-library__rail-button"
            aria-label="Действия"
            title="Действия"
        >
            <x-filament::icon icon="heroicon-o-cube" class="h-5 w-5"/>
        </button>

        <button type="button" wire:click="switchActionPalette('query')" x-bind:class="{ 'is-active': mode === 'query' }" class="workflow-node-library__rail-button" aria-label="Запросы" title="Запросы">
            <x-filament::icon icon="heroicon-o-circle-stack" class="h-5 w-5"/>
        </button>

        <button type="button" wire:click="switchActionPalette('service')" x-bind:class="{ 'is-active': mode === 'service' }" class="workflow-node-library__rail-button" aria-label="Сервисы" title="Сервисы"><x-filament::icon icon="heroicon-o-globe-alt" class="h-5 w-5"/></button>
        <button
            type="button"
            x-on:click="openFor({ mode: 'variables' })"
            x-bind:class="{ 'is-active': mode === 'variables' }"
            class="workflow-node-library__rail-button"
            aria-label="Переменные"
            title="Переменные"
        >
            <x-filament::icon icon="heroicon-o-variable" class="h-5 w-5"/>
        </button>
    </div>

    <div x-cloak x-show="open" class="workflow-node-library__panel">
        <header class="workflow-node-library__header">
            <div>
                <h2 x-text="mode === 'variables' ? 'Переменные' : mode === 'query' ? 'Запросы' : mode === 'service' ? 'Сервисы' : mode === 'trigger' ? 'События' : 'Действия'"></h2>
            </div>

            <button
                x-cloak
                x-show="mode === 'variables'"
                type="button"
                wire:click="refreshWorkflowReference"
                wire:loading.attr="disabled"
                class="workflow-node-library__close workflow-node-library__refresh"
                aria-label="Обновить переменные"
                title="Обновить переменные"
            >
                <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4"/>
            </button>

            <button
                type="button"
                x-on:click="open = false"
                wire:click="cancelActionInsertion"
                class="workflow-node-library__close"
                aria-label="Свернуть каталог"
                title="Свернуть"
            >
                <x-filament::icon icon="heroicon-o-chevron-left" class="h-4 w-4"/>
            </button>
        </header>

        <div x-show="mode !== 'variables'" class="workflow-node-library__search-wrap">
            <label class="workflow-node-library__search">
                <x-filament::icon icon="heroicon-o-magnifying-glass" class="h-4 w-4"/>
                <input
                    x-ref="search"
                    x-model.debounce.100ms="query"
                    type="search"
                    autocomplete="off"
                    placeholder="Поиск узлов…"
                    aria-label="Поиск узлов"
                />
                <button
                    x-cloak
                    x-show="query !== ''"
                    type="button"
                    x-on:click="query = ''; $nextTick(() => $refs.search?.focus())"
                    aria-label="Очистить поиск"
                >
                    <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4"/>
                </button>
            </label>
        </div>

        <div class="workflow-node-library__body">
            <div x-cloak x-show="mode === 'service'" class="workflow-node-library__list">
                <button type="button" x-show="view === 'root' && query === ''" x-on:click="view = 'telegram'" class="workflow-node-library__group">
                    <span class="workflow-node-library__group-icon"><x-workflow-icon icon="service-telegram" type="telegram_send_message" class="h-5 w-5"/></span>
                    <span><strong>Telegram</strong><small>Сообщения от бота</small></span><x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4"/>
                </button>
                <button type="button" x-show="(view === 'telegram' || query !== '') && matches('telegram телеграм отправить сообщение')" wire:click="selectActionType('telegram_send_message')" class="workflow-node-library__item">
                    <span class="workflow-node-library__item-icon"><x-workflow-icon icon="service-telegram" type="telegram_send_message" class="h-5 w-5"/></span>
                    <span><strong>Отправить сообщение</strong><small>Telegram · бот</small></span>
                </button>
            </div>
            <div x-cloak x-show="mode === 'query'" class="workflow-node-library__list">
                @foreach(\App\Services\Workflows\WorkflowAmoReadCatalog::options() as $group => $options)
                    <button type="button" x-show="query === '' && view === 'root'" x-on:click="view = @js($group)" class="workflow-node-library__group">
                        <span class="workflow-node-library__group-icon"><x-workflow-icon :icon="\App\Services\Workflows\WorkflowAmoIcons::entity($group)" :amo="true" class="h-5 w-5"/></span>
                        <span><strong>{{ $group }}</strong><small>Чтение данных</small></span><x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4"/>
                    </button>
                @endforeach
                <div x-show="query === '' && view !== 'root'" class="workflow-node-library__breadcrumb"><button type="button" x-on:click="view = 'root'">← Запросы</button></div>
                @foreach($queryActions as $queryAction)
                    @php($queryActionGroup = $queryActionGroups[$queryAction['type']] ?? 'Другое')
                    <button type="button" x-show="(query !== '' || view === @js($queryActionGroup)) && matches(@js('amoCRM '.$queryActionGroup.' запрос '.$queryAction['name']))" wire:click="selectActionType(@js($queryAction['type']))" class="workflow-node-library__item">
                        <span class="workflow-node-library__item-icon"><x-workflow-icon :icon="\App\Services\Workflows\WorkflowAmoIcons::action($queryAction['type'], [], $queryAction['icon'])" :amo="true" class="h-5 w-5"/></span>
                        <span><strong>{{ $queryAction['name'] }}</strong><small>{{ $queryActionGroup }}</small></span>
                    </button>
                @endforeach
                @foreach(\App\Services\Workflows\WorkflowAmoReadCatalog::availableOperations() as $operation => $item)
                    <button type="button" x-show="(query !== '' || view === @js($item['group'])) && matches(@js($item['group'].' '.($item['entity_group'] ?? '').' '.$item['name'].' '.$item['path'].' '.implode(' ', $item['variants'] ?? [])))" wire:click="selectReadOperation(@js($operation))" class="workflow-node-library__item">
                        <span class="workflow-node-library__item-icon"><x-workflow-icon :icon="\App\Services\Workflows\WorkflowAmoIcons::action('amocrm_read', ['operation' => $operation], 'heroicon-o-circle-stack')" :amo="true" class="h-5 w-5"/></span>
                        <span><strong>{{ $item['name'] }}</strong><small>{{ $item['entity_group'] ?? $item['group'] }}{{ count($item['variants'] ?? []) > 1 ? ' · список или ID' : '' }}</small></span>
                    </button>
                @endforeach
            </div>
            <div x-cloak x-show="mode === 'variables'" class="workflow-node-library__variables">
                @include('filament.workflow-builder.mask-reference', [
                    'groups' => $maskGroups,
                    'systemIdGroups' => $systemIdGroups,
                ])
            </div>
            <div x-cloak x-show="query !== '' && mode === 'trigger'" class="workflow-node-library__list">
                <div class="workflow-node-library__section-title">Результаты</div>
                @foreach($triggerGroups as $group)
                    @foreach($group['items'] as $trigger)
                        <button
                            x-show="matches(@js($trigger['search_text']))"
                            type="button"
                            wire:click="selectTriggerType(@js($trigger['type']))"
                            wire:loading.attr="disabled"
                            class="workflow-node-library__item"
                        >
                            <span class="workflow-node-library__item-icon workflow-node-library__item-icon--trigger">
                                <x-workflow-icon :icon="$trigger['icon'] ?? 'heroicon-o-bolt'" :amo="str_starts_with($trigger['type'], 'amocrm-') || $trigger['type'] === 'amo-button'" class="h-4 w-4"/>
                            </span>
                            <span>
                                <strong>{{ $trigger['display_name'] }}</strong>
                                <small>{{ $group['title'] }}</small>
                            </span>
                        </button>
                    @endforeach
                @endforeach
            </div>

            <div x-cloak x-show="query !== '' && mode === 'action'" class="workflow-node-library__list">
                <div class="workflow-node-library__section-title">Результаты</div>
                @foreach($allActions as $action)
                    <button
                        x-show="matches(@js($action['search_text']))"
                        type="button"
                        wire:click="selectActionType(@js($action['type']))"
                        wire:loading.attr="disabled"
                        class="workflow-node-library__item"
                    >
                        <span class="workflow-node-library__item-icon">
                            <x-workflow-icon :icon="$action['icon'] ?? 'heroicon-o-cog-6-tooth'" :type="$action['type']" :amo="str_starts_with($action['type'], 'amocrm_')" class="h-4 w-4"/>
                        </span>
                        <span>
                            <strong>{{ $action['name'] ?? 'Действие' }}</strong>
                            <small>{{ in_array($action['type'] ?? '', ['control-condition', 'workflow_javascript', 'workflow_delay', 'workflow_filter_list', 'http_request'], true) ? 'Управление потоком' : 'amoCRM' }}</small>
                        </span>
                    </button>
                @endforeach
            </div>

            <div x-cloak x-show="query === '' && mode === 'trigger'">
                <div x-show="view !== 'root'" class="workflow-node-library__breadcrumb">
                    <button type="button" x-on:click="goBack()">
                        <x-filament::icon icon="heroicon-o-arrow-left" class="h-4 w-4"/>
                        Назад
                    </button>
                </div>

                <div x-show="view === 'root'" class="workflow-node-library__list">
                    @foreach($triggerGroups as $groupKey => $group)
                        <button
                            type="button"
                            x-on:click="view = @js($groupKey)"
                            class="workflow-node-library__group"
                        >
                            <span class="workflow-node-library__group-icon">
                                <x-workflow-icon :icon="$group['icon']" :amo="$groupKey !== 'system'" class="h-5 w-5"/>
                            </span>
                            <span>
                                <strong>{{ $group['title'] }}</strong>
                                <small>{{ $group['description'] }}</small>
                            </span>
                            <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4"/>
                        </button>
                    @endforeach
                </div>

                @foreach($triggerGroups as $groupKey => $group)
                    <div x-cloak x-show="view === @js($groupKey)" class="workflow-node-library__list">
                        <div class="workflow-node-library__section-title">{{ $group['title'] }}</div>
                        @foreach($group['items'] as $trigger)
                            <button
                                type="button"
                                wire:click="selectTriggerType(@js($trigger['type']))"
                                wire:loading.attr="disabled"
                                class="workflow-node-library__item"
                            >
                                <span class="workflow-node-library__item-icon workflow-node-library__item-icon--trigger">
                                    <x-workflow-icon :icon="$trigger['icon'] ?? 'heroicon-o-bolt'" :amo="str_starts_with($trigger['type'], 'amocrm-') || $trigger['type'] === 'amo-button'" class="h-4 w-4"/>
                                </span>
                                <span>
                                    <strong>{{ $trigger['display_name'] }}</strong>
                                    <small>{{ strip_tags((string) ($trigger['description'] ?? '')) }}</small>
                                </span>
                            </button>
                        @endforeach
                    </div>
                @endforeach
            </div>

            <div x-cloak x-show="query === '' && mode === 'action'">
                <div x-show="view !== 'root'" class="workflow-node-library__breadcrumb">
                    <button type="button" x-on:click="goBack()">
                        <x-filament::icon icon="heroicon-o-arrow-left" class="h-4 w-4"/>
                        Назад
                    </button>
                    <span x-show="view === 'variants'">amoCRM</span>
                </div>

                <div x-show="view === 'root'" class="workflow-node-library__list">
                    @if($logicActions !== [])
                        <button type="button" x-on:click="view = 'logic'" class="workflow-node-library__group">
                            <span class="workflow-node-library__group-icon workflow-node-library__group-icon--logic">
                                <x-filament::icon icon="heroicon-o-arrows-right-left" class="h-5 w-5"/>
                            </span>
                            <span>
                                <strong>Управление потоком</strong>
                                <small>Условия, списки, задержки и запросы</small>
                            </span>
                            <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4"/>
                        </button>
                    @endif

                    <button type="button" x-on:click="view = 'amocrm'" class="workflow-node-library__group">
                        <span class="workflow-node-library__group-icon workflow-node-library__group-icon--amo">
                            <img src="{{ asset('images/workflows/amocrm.svg') }}" alt="" class="workflow-brand-icon"/>
                        </span>
                        <span>
                            <strong>amoCRM</strong>
                            <small>Действия с сущностями</small>
                        </span>
                        <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4"/>
                    </button>
                </div>

                <div x-cloak x-show="view === 'logic'" class="workflow-node-library__list">
                    <div class="workflow-node-library__section-title">Управление потоком</div>
                    @foreach($logicActions as $action)
                        <button
                            type="button"
                            wire:click="selectActionType(@js($action['type']))"
                            wire:loading.attr="disabled"
                            class="workflow-node-library__item"
                        >
                            <span class="workflow-node-library__item-icon">
                                <x-workflow-icon :icon="$action['icon'] ?? 'heroicon-o-adjustments-horizontal'" :type="$action['type']" class="h-4 w-4"/>
                            </span>
                            <span>
                                <strong>{{ $action['name'] ?? 'Условие' }}</strong>
                                <small>{{ strip_tags((string) ($action['description'] ?? '')) }}</small>
                            </span>
                        </button>
                    @endforeach
                </div>

                <div x-cloak x-show="view === 'amocrm'" class="workflow-node-library__list">
                    @foreach($actionEntities as $entityKey => $entity)
                        <button
                            type="button"
                            x-on:click="view = 'variants'; entity = @js($entityKey)"
                            class="workflow-node-library__group"
                        >
                            <span class="workflow-node-library__group-icon">
                                <x-workflow-icon :icon="$entity['icon']" :amo="true" class="h-5 w-5"/>
                            </span>
                            <span>
                                <strong>{{ $entity['title'] }}</strong>
                                <small>{{ $entity['description'] }}</small>
                            </span>
                            <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4"/>
                        </button>
                    @endforeach
                </div>

                @foreach($actionEntities as $entityKey => $entity)
                    <div
                        x-cloak
                        x-show="view === 'variants' && entity === @js($entityKey)"
                        class="workflow-node-library__list"
                    >
                        <div class="workflow-node-library__section-title">{{ $entity['title'] }} · действие</div>
                        @foreach($entity['items'] as $action)
                            <button
                                type="button"
                                wire:click="selectActionType(@js($action['type']))"
                                wire:loading.attr="disabled"
                                class="workflow-node-library__item"
                            >
                                <span class="workflow-node-library__item-icon">
                                    <x-workflow-icon :icon="$action['icon'] ?? 'heroicon-o-cog-6-tooth'" :amo="true" class="h-4 w-4"/>
                                </span>
                                <span>
                                    <strong>{{ $action['name'] ?? 'Действие' }}</strong>
                                    <small>{{ strip_tags((string) ($action['description'] ?? '')) }}</small>
                                </span>
                            </button>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</aside>
