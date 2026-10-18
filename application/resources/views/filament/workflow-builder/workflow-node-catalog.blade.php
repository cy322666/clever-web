<div x-show="mode !== 'variables'" class="workflow-node-library__catalog">
    <nav class="workflow-node-library__categories" aria-label="Категории нод">
        <div x-show="mode === 'all'" class="workflow-node-library__category-caption">Запуск</div>
        @foreach($triggerGroups as $groupKey => $group)
            <button x-cloak x-show="mode === 'trigger' || mode === 'all'" type="button"
                    x-on:click="selectCategory(@js('trigger:'.$groupKey))"
                    x-bind:aria-current="view === @js('trigger:'.$groupKey) && query === '' ? 'true' : null"
                    class="workflow-node-library__category">
                <span class="workflow-node-library__category-icon"><x-workflow-icon :icon="$group['icon']" :amo="$groupKey !== 'system'" class="h-4 w-4"/></span>
                <span>{{ $group['title'] }}</span>
                <small>{{ count($group['items']) }}</small>
            </button>
        @endforeach
        <div x-show="mode === 'all'" class="workflow-node-library__category-caption">Действия</div>
        @if($logicActions !== [])
            <button x-cloak x-show="mode !== 'trigger'" type="button" x-on:click="selectCategory('logic')"
                    x-bind:aria-current="view === 'logic' && query === '' ? 'true' : null" class="workflow-node-library__category">
                <span class="workflow-node-library__category-icon"><x-filament::icon icon="heroicon-o-arrows-right-left" class="h-4 w-4"/></span>
                <span>Логика</span><small>{{ count($logicActions) }}</small>
            </button>
        @endif
        <button x-cloak x-show="mode !== 'trigger'" type="button" x-on:click="selectCategory('amocrm')"
                x-bind:aria-current="view === 'amocrm' && query === '' ? 'true' : null" class="workflow-node-library__category">
            <span class="workflow-node-library__category-icon"><img src="{{ asset('images/workflows/amocrm.svg') }}" alt="" class="workflow-brand-icon"/></span>
            <span>amoCRM</span>
        </button>
        <button x-cloak x-show="mode !== 'trigger'" type="button" x-on:click="selectCategory('query')"
                x-bind:aria-current="view === 'query' && query === '' ? 'true' : null" class="workflow-node-library__category">
            <span class="workflow-node-library__category-icon"><x-filament::icon icon="heroicon-o-magnifying-glass" class="h-4 w-4"/></span>
            <span>Запросы</span>
        </button>
        <button x-cloak x-show="mode !== 'trigger'" type="button" x-on:click="selectCategory('service')"
                x-bind:aria-current="view === 'service' && query === '' ? 'true' : null" class="workflow-node-library__category">
            <span class="workflow-node-library__category-icon"><x-workflow-icon icon="service-telegram" type="telegram_send_message" class="h-4 w-4"/></span>
            <span>Сервисы</span>
        </button>
    </nav>

    <div class="workflow-node-library__results">
        @foreach($triggerGroups as $groupKey => $group)
            <div x-cloak x-show="(mode === 'trigger' || mode === 'all') && (query === '' ? view === @js('trigger:'.$groupKey) : matchesAny(@js(array_column($group['items'], 'search_text'))))"
                 class="workflow-node-library__list">
                <div class="workflow-node-library__result-heading"><strong>{{ $group['title'] }}</strong><small>{{ $group['description'] }}</small></div>
                @foreach($group['items'] as $trigger)
                    <button x-show="query === '' || matches(@js($trigger['search_text']))" type="button"
                            x-on:click="workflowEditorCall($wire, 'selectTriggerType', @js($trigger['type']), !replaceTrigger)"
                            x-bind:disabled="workflowNodeIsAdding($wire)" wire:loading.attr="disabled" class="workflow-node-library__item">
                        <span class="workflow-node-library__item-icon workflow-node-library__item-icon--trigger"><x-workflow-icon :icon="$trigger['icon']" :amo="str_starts_with($trigger['type'], 'amocrm-') || $trigger['type'] === 'amo-button'" class="h-4 w-4"/></span>
                        <span><strong>{{ $trigger['display_name'] }}</strong><small>{{ strip_tags((string) ($trigger['description'] ?? '')) }}</small></span>
                        <x-filament::icon icon="heroicon-m-plus" class="workflow-node-library__item-add"/>
                    </button>
                @endforeach
            </div>
        @endforeach

        <div x-cloak x-show="mode !== 'trigger' && (query === '' ? view === 'logic' : matchesAny(@js(array_column($logicActions, 'search_text'))))" class="workflow-node-library__list">
            <div class="workflow-node-library__result-heading"><strong>Логика и данные</strong><small>Условия, циклы, переменные и код</small></div>
            @foreach($logicActions as $action)
                <button x-show="query === '' || matches(@js($action['search_text']))" type="button"
                        x-on:click="workflowEditorCall($wire, 'selectActionType', @js($action['type']))"
                        x-bind:disabled="workflowNodeIsAdding($wire)" wire:loading.attr="disabled" class="workflow-node-library__item">
                    <span class="workflow-node-library__item-icon"><x-workflow-icon :icon="$action['icon']" :type="$action['type']" class="h-4 w-4"/></span>
                    <span><strong>{{ $action['name'] }}</strong><small>{{ strip_tags((string) ($action['description'] ?? '')) }}</small></span>
                    <x-filament::icon icon="heroicon-m-plus" class="workflow-node-library__item-add"/>
                </button>
            @endforeach
        </div>

        <div x-cloak x-show="mode !== 'trigger' && (query === '' ? view === 'amocrm' : matchesAny(@js($amoSearchTexts)))" class="workflow-node-library__list">
            @foreach($actionEntities as $entity)
                <div x-show="query === '' || [@foreach($entity['items'] as $action)@js($action['search_text']){{ !$loop->last ? ',' : '' }}@endforeach].some(value => matches(value))"
                     class="workflow-node-library__section-title">{{ $entity['title'] }}</div>
                @foreach($entity['items'] as $action)
                    <button x-show="query === '' || matches(@js($action['search_text']))" type="button"
                            x-on:click="workflowEditorCall($wire, 'selectActionType', @js($action['type']))"
                            x-bind:disabled="workflowNodeIsAdding($wire)" wire:loading.attr="disabled" class="workflow-node-library__item">
                        <span class="workflow-node-library__item-icon"><x-workflow-icon :icon="$action['icon']" :amo="true" class="h-4 w-4"/></span>
                        <span><strong>{{ $action['name'] }}</strong><small>{{ $entity['description'] }}</small></span>
                        <x-filament::icon icon="heroicon-m-plus" class="workflow-node-library__item-add"/>
                    </button>
                @endforeach
            @endforeach
        </div>

        <div x-cloak x-show="mode !== 'trigger' && (query === '' ? view === 'query' : matchesAny(@js($querySearchTexts)))" class="workflow-node-library__list">
            <div class="workflow-node-library__result-heading"><strong>Запросы amoCRM</strong><small>Получить сущность или список</small></div>
            @foreach($queryActions as $action)
                <button x-show="query === '' || matches(@js('amoCRM '.$action['name'].' '.$queryActionGroups[$action['type']]))" type="button"
                        x-on:click="workflowEditorCall($wire, 'selectActionType', @js($action['type']))"
                        x-bind:disabled="workflowNodeIsAdding($wire)" wire:loading.attr="disabled" class="workflow-node-library__item">
                    <span class="workflow-node-library__item-icon"><x-workflow-icon :icon="$action['icon']" :amo="true" class="h-4 w-4"/></span>
                    <span><strong>{{ $action['name'] }}</strong><small>{{ $queryActionGroups[$action['type']] }}</small></span>
                    <x-filament::icon icon="heroicon-m-plus" class="workflow-node-library__item-add"/>
                </button>
            @endforeach
            @foreach($readOperations as $operation => $item)
                <button x-show="query === '' || matches(@js($item['group'].' '.($item['entity_group'] ?? '').' '.$item['name'].' '.$item['path'].' '.implode(' ', $item['variants'] ?? [])))" type="button"
                        x-on:click="workflowEditorCall($wire, 'selectReadOperation', @js($operation))"
                        x-bind:disabled="workflowNodeIsAdding($wire)" wire:loading.attr="disabled" class="workflow-node-library__item">
                    <span class="workflow-node-library__item-icon"><x-workflow-icon :icon="\App\Services\Workflows\WorkflowAmoIcons::action('amocrm_read', ['operation' => $operation], 'heroicon-o-circle-stack')" :amo="true" class="h-4 w-4"/></span>
                    <span><strong>{{ $item['name'] }}</strong><small>{{ $item['group'] }}</small></span>
                    <x-filament::icon icon="heroicon-m-plus" class="workflow-node-library__item-add"/>
                </button>
            @endforeach
        </div>

        <div x-cloak x-show="mode !== 'trigger' && (query === '' ? view === 'service' : matches('telegram телеграм отправить сообщение'))" class="workflow-node-library__list">
            <div class="workflow-node-library__result-heading"><strong>Сервисы</strong><small>Внешние подключения</small></div>
            <button x-show="query === '' || matches('telegram телеграм отправить сообщение')" type="button"
                    x-on:click="workflowEditorCall($wire, 'selectActionType', 'telegram_send_message')"
                    x-bind:disabled="workflowNodeIsAdding($wire)" wire:loading.attr="disabled" class="workflow-node-library__item">
                <span class="workflow-node-library__item-icon"><x-workflow-icon icon="service-telegram" type="telegram_send_message" class="h-4 w-4"/></span>
                <span><strong>Отправить сообщение</strong><small>Телеграм</small></span>
                <x-filament::icon icon="heroicon-m-plus" class="workflow-node-library__item-add"/>
            </button>
        </div>
        <p x-cloak x-show="query !== '' && !(mode !== 'trigger' && matchesAny(@js($actionSearchTexts))) && !(mode !== 'action' && matchesAny(@js($triggerSearchTexts)))"
           class="workflow-node-library__no-results">Ничего не найдено. Попробуйте другое название.</p>
    </div>
</div>

<div x-cloak x-show="mode === 'variables'" class="workflow-node-library__variables workflow-node-library__body">
    @include('filament.workflow-builder.mask-reference', [
        'groups' => $maskGroups,
        'systemIdGroups' => $systemIdGroups,
    ])
</div>
