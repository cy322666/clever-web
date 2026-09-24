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
            'resource' => \App\Filament\Resources\Integrations\Finder\FinderResource::class,
            'amo_widget' => \App\Models\Core\Account::DEFAULT_WIDGET,
            'public' => true,
            'title' => 'Контроль ответов',
            'description' => 'Контроль времени ответа в диалогах amoCRM: рабочее расписание, задачи и запуск сценариев.',
        ],
        'tilda' => [
            'resource' => TildaResource::class,
            'public' => true,
        ],
        'distribution' => [
            'resource' => DistributionResource::class,
            'public' => true,
        ],
        'yclients' => [
            'resource' => YClientsResource::class,
            'public' => true,
        ],
        'sqns' => [
            'resource' => SqnsResource::class,
            'public' => true,
            'title' => 'SQNS',
            'description' => 'Синхронизируйте клиентов и визиты между SQNS и amoCRM.',
        ],
        'vetmanager' => [
            'resource' => VetmanagerResource::class,
            'public' => true,
            'title' => 'Vetmanager',
            'description' => 'Передавайте посещения из Vetmanager в контакты и сделки amoCRM.',
        ],
        'sqns' => [
            'resource' => SqnsResource::class,
            'public' => true,
            'title' => 'SQNS',
            'description' => 'Синхронизируйте клиентов и визиты между SQNS и amoCRM.',
        ],
        'import-excel' => [
            'resource' => ImportResource::class,
            'public' => true,
        ],
        'workflows' => [
            'resource' => WorkflowResource::class,
            'public' => true,
            'trial_days' => 7,
            'requires_setting' => false,
            'open_page' => 'index',
            'title' => 'Потоки',
            'description' => 'Конструктор процессов и триггеров: события, условия, действия, история запусков и секреты.',
        ],
    ],

    'amo_auth_alert' => [
        // repeat alert window to avoid spamming when oauth stays broken
        'cooldown_minutes' => (int)env('AMO_AUTH_ALERT_COOLDOWN_MINUTES', 360),
    ],
];
