<?php

use App\Filament\Resources\Integrations\DistributionResource;
use App\Filament\Resources\Integrations\ImportExcel\ImportResource;
use App\Filament\Resources\Integrations\Sqns\SqnsResource;
use App\Filament\Resources\Integrations\TildaResource;
use App\Filament\Resources\Integrations\Vetmanager\VetmanagerResource;
use App\Filament\Resources\Integrations\YClients\YClientsResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource;

return [
    'default_trial_days' => 7,

    'definitions' => [
        'finder' => [
            'category' => 'universal',
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'resource' => \App\Filament\Resources\Integrations\Finder\FinderResource::class,
            'amo_widget' => \App\Models\Core\Account::DEFAULT_WIDGET,
            'public' => true,
            'crm_providers' => ['amocrm'],
            'title' => 'Контроль ответов',
            'description' => 'Контроль времени ответа в диалогах amoCRM: рабочее расписание, задачи и запуск сценариев.',
        ],
        'tilda' => [
            'category' => 'universal',
            'icon' => 'heroicon-o-globe-alt',
            'resource' => TildaResource::class,
            'public' => true,
            'crm_providers' => ['amocrm'],
        ],
        'distribution' => [
            'category' => 'universal',
            'icon' => 'heroicon-o-arrows-right-left',
            'resource' => DistributionResource::class,
            'public' => true,
            'crm_providers' => ['amocrm'],
        ],
        'yclients' => [
            'category' => 'industry',
            'icon' => 'heroicon-o-calendar-days',
            'resource' => YClientsResource::class,
            'public' => true,
            'crm_providers' => ['amocrm'],
        ],
        'sqns' => [
            'category' => 'industry',
            'icon' => 'heroicon-o-building-office-2',
            'resource' => SqnsResource::class,
            'public' => true,
            'crm_providers' => ['amocrm'],
            'title' => 'SQNS',
            'description' => 'Синхронизируйте клиентов и визиты между SQNS и amoCRM.',
        ],
        'vetmanager' => [
            'category' => 'industry',
            'icon' => 'heroicon-o-heart',
            'resource' => VetmanagerResource::class,
            'public' => true,
            'crm_providers' => ['amocrm'],
            'title' => 'Vetmanager',
            'description' => 'Передавайте посещения из Vetmanager в контакты и сделки amoCRM.',
        ],
        'import-excel' => [
            'category' => 'universal',
            'icon' => 'heroicon-o-table-cells',
            'resource' => ImportResource::class,
            'public' => true,
            'crm_providers' => ['amocrm'],
        ],
        'workflows' => [
            'category' => 'universal',
            'icon' => 'heroicon-o-bolt',
            'resource' => WorkflowResource::class,
            'public' => true,
            'crm_providers' => ['amocrm'],
            'trial_days' => 7,
            'requires_setting' => false,
            'open_page' => 'index',
            'title' => 'Потоки',
            'description' => 'Конструктор процессов и триггеров: события, условия, действия, история запусков и секреты.',
        ],
    ],

    'amo_auth_alert' => [
        // repeat alert window to avoid spamming when oauth stays broken
        'cooldown_minutes' => (int) env('AMO_AUTH_ALERT_COOLDOWN_MINUTES', 360),
    ],
];
