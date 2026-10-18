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
    $systemTriggerTypes = ['manual', 'amo-button', 'amo-bulk', 'digital-pipeline', 'schedule', 'generic-webhook'];

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
            $group === 'system' ? '' : 'amoCRM',
            $type === 'amo-button' ? 'кнопка кнопкой амо amoCRM сделка' : '',
            $type === 'amo-bulk' ? 'массовое действие список сделки контакты компании амо amoCRM' : '',
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
        'workflow_loop',
        'workflow_set_variable',
        'http_request',
        'amocrm_create_lead',
        'amocrm_create_contact',
        'amocrm_create_company',
        'amocrm_start_salesbot',
        'amocrm_update_lead_fields',
        'amocrm_update_contact_fields',
        'amocrm_update_company_fields',
        'amocrm_change_lead_status',
        'amocrm_create_task',
        'amocrm_add_note',
        'amocrm_change_tags',
        'amocrm_link_entity',
    ];
    $unsupportedActionTypes = \App\Workflows\Actions\WorkflowAmoCrmActionCatalog::unsupportedWorkflowTypes();
    $queryActionGroups = [
        'amocrm_query_leads' => 'Сделки',
        'amocrm_contact_leads' => 'Сделки',
        'amocrm_get_contact' => 'Контакты',
    ];
    $queryActions = array_values(array_filter($actions, fn (array $action): bool => isset($queryActionGroups[$action['type'] ?? ''])));
    $actionEntities = [
        'salesbot' => ['title' => 'Salesbot', 'description' => 'Запуск бота', 'icon' => 'heroicon-o-cpu-chip', 'types' => ['amocrm_start_salesbot'], 'items' => []],
        'contact' => ['title' => 'Контакт', 'description' => 'Создание и поля контакта', 'icon' => 'heroicon-o-user', 'types' => ['amocrm_create_contact', 'amocrm_update_contact_fields'], 'items' => []],
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
        'links' => [
            'title' => 'Связи',
            'description' => 'Связать любые сущности amoCRM',
            'icon' => 'heroicon-o-link',
            'types' => ['amocrm_link_entity'],
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
            'действие узел',
        ]));

        if (in_array($type, ['control-condition', 'workflow_loop', 'workflow_set_variable', 'workflow_javascript', 'workflow_delay', 'workflow_filter_list', 'http_request'], true)) {
            $logicActions[] = $action;
            continue;
        }

        foreach ($actionEntities as &$entity) {
            if (in_array($type, $entity['types'], true)) {
                $action['search_text'] .= ' '.mb_strtolower('amoCRM '.$entity['title']);
                $entity['items'][] = $action;
                break;
            }
        }
        unset($entity);
    }

    $actionEntities = array_filter($actionEntities, static fn (array $entity): bool => $entity['items'] !== []);
    $readOperations = \App\Services\Workflows\WorkflowAmoReadCatalog::availableOperations();
    $triggerSearchTexts = array_merge(...array_values(array_map(static fn (array $group): array => array_column($group['items'], 'search_text'), $triggerGroups)));
    $amoSearchTexts = array_column(array_merge(...array_column($actionEntities, 'items')), 'search_text');
    $querySearchTexts = array_merge(
        array_map(static fn (array $action): string => 'amoCRM '.$action['name'].' '.($queryActionGroups[$action['type']] ?? ''), $queryActions),
        array_map(static fn (array $item): string => $item['group'].' '.($item['entity_group'] ?? '').' '.$item['name'].' '.$item['path'].' '.implode(' ', $item['variants'] ?? []), $readOperations),
    );
    $actionSearchTexts = array_merge(array_column($logicActions, 'search_text'), $amoSearchTexts, $querySearchTexts, ['telegram телеграм отправить сообщение']);
@endphp

<aside
    x-data="workflowNodeLibrary()"
    x-on:workflow-node-library-open.window="openFor($event.detail)"
    x-on:workflow-masks-open.window="openFor({ mode: 'variables', anchor: $event.detail?.anchor })"
    x-on:workflow-node-created.window="open = false; anchor = null"
    x-on:workflow-node-inserted.window="open = false; anchor = null"
    x-on:workflow-node-replaced.window="open = false; anchor = null"
    x-on:keydown.escape.window="if (open) closeLibrary()"
    x-on:pointerdown.window.capture="dismissLibrary($event)"
    x-on:resize.window="viewport = { width: window.innerWidth, height: window.innerHeight }"
    class="workflow-node-library workflow-node-library--floating is-contextual"
>
    <div x-ref="panel" x-cloak x-show="open" x-bind:style="floatingStyle()"
         x-bind:data-workflow-reference-panel="mode === 'variables' ? '' : null"
         class="workflow-node-library__panel" role="dialog"
         x-bind:aria-busy="workflowNodeIsAdding($wire)"
         x-bind:aria-label="mode === 'variables' ? 'Справочники' : mode === 'trigger' ? 'Выбрать запуск' : 'Добавить ноду'">
        <header class="workflow-node-library__header">
            <div class="workflow-node-library__heading">
                <h2 x-show="!workflowNodeIsAdding($wire)" x-text="mode === 'variables' ? 'Справочники' : mode === 'trigger' ? 'Выбрать запуск' : mode === 'action' ? 'Добавить действие' : 'Добавить ноду'"></h2>
                <small x-show="mode !== 'variables' && !workflowNodeIsAdding($wire)" x-text="mode === 'trigger' ? 'Событие, с которого начинается сценарий' : mode === 'action' ? 'Шаг в выбранной ветке' : 'Выберите запуск или действие'"></small>
                <div x-cloak x-show="workflowNodeIsAdding($wire)" class="workflow-node-library__pending" role="status" aria-live="polite"><span class="workflow-run-spinner" aria-hidden="true"></span>Добавляю…</div>
            </div>
            <button x-cloak x-show="mode === 'variables'" type="button" wire:click="refreshWorkflowReference" wire:loading.attr="disabled"
                    class="workflow-node-library__close workflow-node-library__refresh" aria-label="Обновить переменные" title="Обновить переменные">
                <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4"/>
            </button>
            <button type="button" x-on:click="closeLibrary()" class="workflow-node-library__close" aria-label="Закрыть каталог" title="Закрыть">
                <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4"/>
            </button>
        </header>
        <div x-show="mode !== 'variables'" class="workflow-node-library__search-wrap">
            <label class="workflow-node-library__search">
                <x-filament::icon icon="heroicon-o-magnifying-glass" class="h-4 w-4"/>
                <input x-ref="search" x-model.debounce.100ms="query" type="search" autocomplete="off"
                       x-bind:placeholder="mode === 'trigger' ? 'Найти способ запуска…' : 'Найти действие или сервис…'"
                       aria-label="Поиск нод"/>
                <button x-cloak x-show="query !== ''" type="button"
                        x-on:click="query = ''; $nextTick(() => $refs.search?.focus())" aria-label="Очистить поиск">
                    <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4"/>
                </button>
            </label>
        </div>
        @include('filament.workflow-builder.workflow-node-catalog', compact('triggerGroups', 'logicActions', 'actionEntities', 'queryActions', 'queryActionGroups', 'readOperations', 'triggerSearchTexts', 'amoSearchTexts', 'querySearchTexts', 'actionSearchTexts', 'maskGroups', 'systemIdGroups'))
    </div>
</aside>
