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
            'title' => 'Статусы, теги и распределение',
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
        $type = $action['type'] ?? '';

        if ($isInsideConditionBranch && $type === 'control-condition') {
            continue;
        }

        if (in_array($type, $unsupportedActionTypes, true)) {
            continue;
        }

        $groupKey = $typeToGroup[$type] ?? 'other';

        $groups[$groupKey]['items'][] = $action;
    }

    $groups = array_filter($groups, static fn (array $group): bool => count($group['items']) > 0);
@endphp

<div class="workflow-action-selection space-y-5 p-4">
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
                @foreach($group['items'] as $action)
                    @php
                        $type = $action['type'] ?? '';
                        $name = $action['name'] ?? __('filament-workflows::workflows.builder.selection.unknown');
                        $icon = $action['icon'] ?? 'heroicon-o-cog-6-tooth';
                        $color = $action['color'] ?? '#6B7280';
                        $available = $action['available'] ?? true;
                    @endphp

                    @if($available)
                        <button
                            type="button"
                            wire:click="selectActionType('{{ $type }}')"
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
                    @else
                        <button
                            type="button"
                            wire:click="showUnavailableAction('{{ $type }}')"
                            class="group relative flex min-h-20 items-center gap-2.5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-3 text-left opacity-75 transition-all hover:border-slate-400 hover:opacity-100 dark:border-gray-600 dark:bg-gray-900/50 dark:hover:border-gray-500"
                        >
                            <div
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md"
                                style="background-color: {{ $color }}10;"
                            >
                                @svg($icon, 'h-4 w-4 opacity-50', ['style' => 'color: ' . $color])
                            </div>

                            <h4 class="line-clamp-2 text-sm font-medium leading-5 text-gray-500 dark:text-gray-400">
                                {{ $name }}
                            </h4>

                            <span
                                class="mt-1.5 inline-flex items-center gap-0.5 text-[9px] font-medium text-gray-400 dark:text-gray-500">
                                @svg('heroicon-m-arrow-down-tray', 'h-2.5 w-2.5')
                                {{ __('filament-workflows::workflows.builder.selection.requires_plugin') }}
                            </span>
                        </button>
                    @endif
                @endforeach
            </div>
        </section>
    @endforeach
</div>
