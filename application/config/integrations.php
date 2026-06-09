<?php

use App\Filament\Resources\Integrations\AlfaResource;
use App\Filament\Resources\Integrations\AmoDataResource;
use App\Filament\Resources\Integrations\AssistantResource;
use App\Filament\Resources\Integrations\BizonResource;
use App\Filament\Resources\Integrations\CallTranscriptionResource;
use App\Filament\Resources\Integrations\DistributionResource;
use App\Filament\Resources\Integrations\GetCourseResource;
use App\Filament\Resources\Integrations\ImportExcel\ImportResource;
use App\Filament\Resources\Integrations\TildaResource;
use App\Filament\Resources\Integrations\YClients\YClientsResource;

return [
    'definitions' => [
        'alfacrm' => [
            'resource' => AlfaResource::class,
            'public' => true,
        ],
        'bizon' => [
            'resource' => BizonResource::class,
            'public' => true,
        ],
        'getcourse' => [
            'resource' => GetCourseResource::class,
            'public' => true,
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
        'import-excel' => [
            'resource' => ImportResource::class,
            'public' => true,
        ],
        'assistant' => [
            'resource' => AssistantResource::class,
            'public' => true,
        ],
        'amo-data' => [
            'resource' => AmoDataResource::class,
            'public' => true,
        ],
        'call-transcription' => [
            'resource' => CallTranscriptionResource::class,
            'public' => true,
        ],
    ],

    'amo_auth_alert' => [
        // repeat alert window to avoid spamming when oauth stays broken
        'cooldown_minutes' => (int)env('AMO_AUTH_ALERT_COOLDOWN_MINUTES', 360),
    ],
];
