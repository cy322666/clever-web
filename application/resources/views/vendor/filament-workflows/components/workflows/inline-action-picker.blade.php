@props(['actions' => []])

@php
    $currentInsertPath = (string) (($this->inlineActionPickerPath ?? null) ?? ($this->insertActionPath ?? null) ?? ($this->targetPath ?? ''));
    $isInsideConditionBranch = str_contains($currentInsertPath, '.config.true_actions')
        || str_contains($currentInsertPath, '.config.false_actions')
        || str_starts_with($currentInsertPath, 'config.true_actions')
        || str_starts_with($currentInsertPath, 'config.false_actions');

    $groups = [
        'flow' => [
            'title' => 'Логика процесса',
            'icon' => 'heroicon-o-adjustments-horizontal',
            'types' => ['control-condition', 'run_workflow'],
            'items' => [],
        ],
        'communication' => [
            'title' => 'Уведомления',
            'icon' => 'heroicon-o-bell-alert',
            'types' => ['send_notification', 'send_email'],
            'items' => [],
        ],
        'entities' => [
            'title' => 'Сущности amoCRM',
            'icon' => 'heroicon-o-rectangle-stack',
            'types' => ['amocrm_create_lead', 'amocrm_create_contact', 'amocrm_create_company', 'amocrm_copy_lead'],
            'items' => [],
        ],
        'fields' => [
            'title' => 'Поля и данные',
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
            'title' => 'Задачи и заметки',
            'icon' => 'heroicon-o-clipboard-document-check',
            'types' => ['amocrm_create_task', 'amocrm_update_task', 'amocrm_add_note'],
            'items' => [],
        ],
        'automation' => [
            'title' => 'Автоматизация и подписки',
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
            'icon' => 'heroicon-o-shopping-bag',
            'types' => ['amocrm_add_products', 'amocrm_remove_products'],
            'items' => [],
        ],
        'relations' => [
            'title' => 'Поиск и связи',
            'icon' => 'heroicon-o-link',
            'types' => ['amocrm_find_entity', 'amocrm_link_entity', 'amocrm_unlink_entity'],
            'items' => [],
        ],
        'service' => [
            'title' => 'Служебные',
            'icon' => 'heroicon-o-wrench-screwdriver',
            'types' => ['amocrm_cancel_delayed_action'],
            'items' => [],
        ],
        'other' => [
            'title' => 'Другое',
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

    foreach ($actions as $action) {
        $type = (string) ($action['type'] ?? '');

        if ($type === '' || (($action['available'] ?? true) === false)) {
            continue;
        }

        if ($isInsideConditionBranch && $type === 'control-condition') {
            continue;
        }

        if (in_array($type, $unsupportedActionTypes, true)) {
            continue;
        }

        $groups[$typeToGroup[$type] ?? 'other']['items'][] = $action;
    }

    $groups = array_filter($groups, static fn (array $group): bool => count($group['items']) > 0);
@endphp

@if(count($groups) > 0)
    <div
        class="workflow-inline-action-picker"
        wire:key="workflow-inline-action-picker-{{ md5((string) ($this->inlineActionPickerKey ?? 'root')) }}"
    >
        @foreach($groups as $group)
            <section class="workflow-inline-action-picker__group">
                <div class="workflow-inline-action-picker__group-title">
                    <x-filament::icon :icon="$group['icon']" class="h-4 w-4"/>
                    <span>{{ $group['title'] }}</span>
                </div>

                <div class="workflow-inline-action-picker__items">
                    @foreach($group['items'] as $action)
                        @php
                            $type = (string) ($action['type'] ?? '');
                            $name = $action['name'] ?? __('filament-workflows::workflows.builder.selection.unknown');
                            $icon = $action['icon'] ?? 'heroicon-o-cog-6-tooth';
                            $color = $action['color'] ?? '#f97316';
                        @endphp

                        <button
                            type="button"
                            wire:click="selectActionType('{{ $type }}')"
                            class="workflow-inline-action-picker__item"
                        >
                            <span class="workflow-inline-action-picker__icon" style="background-color: {{ $color }}18;">
                                <x-filament::icon :icon="$icon" class="h-4 w-4" style="color: {{ $color }}"/>
                            </span>
                            <span class="workflow-inline-action-picker__name">{{ $name }}</span>
                        </button>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endif
