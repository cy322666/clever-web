<div x-show="mode !== 'variables'" class="workflow-node-library__catalog">
    <nav class="workflow-node-library__categories" aria-label="Категории нод">
        <div x-show="mode === 'all'" class="workflow-node-library__category-caption">События</div>
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
                <span>Инструменты</span><small>{{ count($logicActions) }}</small>
            </button>
        @endif
        <button x-cloak x-show="mode !== 'trigger'" type="button" x-on:click="selectCategory('amocrm')"
                x-bind:aria-current="view.startsWith('amocrm') && query === '' ? 'true' : null" class="workflow-node-library__category">
            <span class="workflow-node-library__category-icon"><img src="{{ asset('images/workflows/amocrm.svg') }}" alt="" class="workflow-brand-icon"/></span>
            <span>amoCRM</span><small>{{ array_sum(array_column($actionEntities, 'count')) }}</small>
        </button>
        <button x-cloak x-show="mode !== 'trigger'" type="button" x-on:click="selectCategory('service')"
                x-bind:aria-current="view === 'service' && query === '' ? 'true' : null" class="workflow-node-library__category">
            <span class="workflow-node-library__category-icon"><x-workflow-icon icon="service-telegram" type="telegram_send_message" class="h-4 w-4"/></span>
            <span>Сервисы</span><small>1</small>
        </button>
    </nav>

    <div class="workflow-node-library__results">
        @foreach($triggerGroups as $groupKey => $group)
            <div x-cloak x-show="(mode === 'trigger' || mode === 'all') && (query === '' ? view === @js('trigger:'.$groupKey) : matchesAny(@js(array_column($group['items'], 'search_text'))))"
                 class="workflow-node-library__list">
                <div class="workflow-node-library__result-heading"><strong>{{ $group['title'] }}</strong></div>
                @foreach($group['items'] as $trigger)
                    <button x-show="query === '' || matches(@js($trigger['search_text']))" type="button"
                            x-on:click="workflowEditorCall($wire, 'selectTriggerType', @js($trigger['type']), !replaceTrigger)"
                            x-bind:disabled="workflowNodeIsAdding($wire)" wire:loading.attr="disabled" class="workflow-node-library__item">
                        <span class="workflow-node-library__item-icon workflow-node-library__item-icon--trigger"><x-workflow-icon :icon="$trigger['icon']" :amo="str_starts_with($trigger['type'], 'amocrm-') || $trigger['type'] === 'amo-button'" class="h-4 w-4"/></span>
                        <span><strong>{{ $trigger['display_name'] }}</strong></span>
                    </button>
                @endforeach
            </div>
        @endforeach

        <div x-cloak x-show="mode !== 'trigger' && (query === '' ? view === 'logic' : matchesAny(@js(array_column($logicActions, 'search_text'))))" class="workflow-node-library__list">
            <div class="workflow-node-library__result-heading"><strong>Инструменты</strong></div>
            @foreach($logicActions as $action)
                <button x-show="query === '' || matches(@js($action['search_text']))" type="button"
                        x-on:click="workflowEditorCall($wire, 'selectActionType', @js($action['type']))"
                        x-bind:disabled="workflowNodeIsAdding($wire)" wire:loading.attr="disabled" class="workflow-node-library__item">
                    <span class="workflow-node-library__item-icon"><x-workflow-icon :icon="$action['icon']" :type="$action['type']" class="h-4 w-4"/></span>
                    <span><strong>{{ $action['name'] }}</strong></span>
                </button>
            @endforeach
        </div>

        <div x-cloak x-show="mode !== 'trigger' && query === '' && view === 'amocrm'" class="workflow-node-library__list">
            @foreach($actionEntities as $key => $entity)
                <button type="button" x-on:click="selectEntity(@js($key))" class="workflow-node-library__item workflow-node-library__entity">
                    <span class="workflow-node-library__item-icon"><x-workflow-icon :icon="$entity['icon']" :amo="true" class="h-4 w-4"/></span>
                    <span><strong>{{ $entity['title'] }}</strong></span>
                    <small>{{ $entity['count'] }}</small>
                    <x-filament::icon icon="heroicon-m-chevron-right" class="workflow-node-library__entity-arrow"/>
                </button>
            @endforeach
        </div>

        @foreach($actionEntities as $key => $entity)
            <div x-cloak x-show="mode !== 'trigger' && (query === '' ? view === @js('amocrm:'.$key) : matchesAny(@js($entity['search_texts'])))" class="workflow-node-library__list">
                <div class="workflow-node-library__result-heading workflow-node-library__entity-heading">
                    <button x-show="query === ''" type="button" x-on:click="selectCategory('amocrm')" class="workflow-node-library__back" aria-label="Назад к сущностям amoCRM"><x-filament::icon icon="heroicon-m-chevron-left" class="h-4 w-4"/>amoCRM</button>
                    <strong>{{ $entity['title'] }}</strong>
                </div>
                @foreach($entity['items'] as $action)
                    <button x-show="query === '' || matches(@js($action['search_text']))" type="button"
                            x-on:click="workflowEditorCall($wire, 'selectActionType', @js($action['type']))"
                            x-bind:disabled="workflowNodeIsAdding($wire)" wire:loading.attr="disabled" class="workflow-node-library__item">
                        <span class="workflow-node-library__item-icon"><x-workflow-icon :icon="$action['icon']" :amo="true" class="h-4 w-4"/></span>
                        <span><strong>{{ $action['name'] }}</strong></span>
                    </button>
                @endforeach
                @foreach($entity['query_items'] ?? [] as $action)
                    <button x-show="query === '' || matches(@js($action['search_text']))" type="button"
                            x-on:click="workflowEditorCall($wire, 'selectActionType', @js($action['type']))"
                            x-bind:disabled="workflowNodeIsAdding($wire)" wire:loading.attr="disabled" class="workflow-node-library__item">
                        <span class="workflow-node-library__item-icon"><x-workflow-icon :icon="$action['icon']" :amo="true" class="h-4 w-4"/></span>
                        <span><strong>{{ $action['name'] }}</strong></span>
                    </button>
                @endforeach
                @foreach($entity['read_items'] ?? [] as $item)
                    <button x-show="query === '' || matches(@js($item['search_text']))" type="button"
                            x-on:click="workflowEditorCall($wire, 'selectReadOperation', @js($item['operation']))"
                            x-bind:disabled="workflowNodeIsAdding($wire)" wire:loading.attr="disabled" class="workflow-node-library__item">
                        <span class="workflow-node-library__item-icon"><x-workflow-icon :icon="\App\Services\Workflows\WorkflowAmoIcons::action('amocrm_read', ['operation' => $item['operation']], 'heroicon-o-circle-stack')" :amo="true" class="h-4 w-4"/></span>
                        <span><strong>{{ $item['name'] }}</strong></span>
                    </button>
                @endforeach
            </div>
        @endforeach

        <div x-cloak x-show="mode !== 'trigger' && (query === '' ? view === 'service' : matches('telegram телеграм отправить сообщение'))" class="workflow-node-library__list">
            <div class="workflow-node-library__result-heading"><strong>Сервисы</strong></div>
            <button x-show="query === '' || matches('telegram телеграм отправить сообщение')" type="button"
                    x-on:click="workflowEditorCall($wire, 'selectActionType', 'telegram_send_message')"
                    x-bind:disabled="workflowNodeIsAdding($wire)" wire:loading.attr="disabled" class="workflow-node-library__item">
                <span class="workflow-node-library__item-icon"><x-workflow-icon icon="service-telegram" type="telegram_send_message" class="h-4 w-4"/></span>
                <span><strong>Отправить сообщение</strong></span>
            </button>
        </div>
        <p x-cloak x-show="query !== '' && !(mode !== 'trigger' && matchesAny(@js($actionSearchTexts))) && !(mode !== 'action' && matchesAny(@js($triggerSearchTexts)))"
           class="workflow-node-library__no-results">Ничего не найдено. Попробуйте другое название.</p>
    </div>
</div>

<div x-cloak x-show="mode === 'variables'" class="workflow-node-library__variables workflow-node-library__body">
    @include('filament.workflow-builder.mask-reference', [
        'groups' => \App\Services\Workflows\WorkflowTriggerReferenceCatalog::maskGroups($triggerDefinition) + $maskGroups,
        'systemIdGroups' => $systemIdGroups,
    ])
</div>
