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
            'logo' => 'logo/clever_mini_logo.png',
            'resource' => \App\Filament\Resources\Integrations\Finder\FinderResource::class,
            'amo_widget' => \App\Models\Core\Account::DEFAULT_WIDGET,
            'public' => true,
            'crm_providers' => ['amocrm'],
            'title' => 'Контроль ответов',
            'description' => 'Контроль времени ответа в диалогах amoCRM: рабочее расписание, задачи и запуск сценариев.',
        ],
        'tilda' => [
            'category' => 'universal',
            'logo' => 'logo/integrations/20260928/tilda.svg',
            'resource' => TildaResource::class,
            'public' => true,
            'crm_providers' => ['amocrm'],
        ],
        'distribution' => [
            'category' => 'universal',
            'logo' => 'logo/clever_mini_logo.png',
            'resource' => DistributionResource::class,
            'public' => true,
            'crm_providers' => ['amocrm'],
        ],
        'yclients' => [
            'category' => 'industry',
            'logo' => 'logo/integrations/20260928/yclients.png',
            'resource' => YClientsResource::class,
            'public' => true,
            'crm_providers' => ['amocrm'],
        ],
        'sqns' => [
            'category' => 'industry',
            'logo' => 'logo/integrations/20260928/sqns.svg',
            'resource' => SqnsResource::class,
            'public' => true,
            'crm_providers' => ['amocrm'],
            'title' => 'SQNS',
            'description' => 'Синхронизируйте клиентов и визиты между SQNS и amoCRM.',
        ],
        'vetmanager' => [
            'category' => 'industry',
            'logo' => 'logo/integrations/20260928/vetmanager.svg',
            'resource' => VetmanagerResource::class,
            'public' => true,
            'crm_providers' => ['amocrm'],
            'title' => 'Ветменеджер',
            'description' => 'Передавайте посещения из Ветменеджера в контакты и сделки amoCRM.',
        ],
        'import-excel' => [
            'category' => 'universal',
            'logo' => 'logo/integrations/20260928/excel.svg',
            'resource' => ImportResource::class,
            'public' => true,
            'crm_providers' => ['amocrm'],
        ],
        'workflows' => [
            'category' => 'universal',
            'logo' => 'logo/clever_mini_logo.png',
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
