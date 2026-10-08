<?php

$appUrl = rtrim((string) (env('APP_URL') ?: 'http://localhost'), '/');
$widgetRedirectUri = static fn (string $key, string $path): string =>
    (string) (env($key) ?: $appUrl.$path);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'amocrm' => [
        'app_name'     => env('AMO_APP_NAME'),
        'client_id'    => env('AMO_CLIENT_ID'),
        'client_secret'=> env('AMO_CLIENT_SECRET'),
        'description'  => env('AMO_DESCRIPTION'),
        'redirect_uri' => env('AMO_REDIRECT_URI'),
        'secrets_uri'  => env('AMO_SECRETS_URI'),
        'widgets' => [
            // Optional per-widget oauth credentials.
            'finder' => [
                'client_id' => env('AMO_FINDER_CLIENT_ID'),
                'client_secret' => env('AMO_FINDER_CLIENT_SECRET'),
                'redirect_uri' => $widgetRedirectUri('AMO_FINDER_REDIRECT_URI', '/api/amocrm/install/finder'),
                'fallback_to_platform_credentials' => false,
            ],
            'import-excel' => [
                'client_id' => env('AMO_IMPORT_EXCEL_CLIENT_ID'),
                'client_secret' => env('AMO_IMPORT_EXCEL_CLIENT_SECRET'),
                'redirect_uri' => $widgetRedirectUri('AMO_IMPORT_EXCEL_REDIRECT_URI', '/api/amocrm/install/excel'),
            ],
            'yclients' => [
                'client_id' => env('AMO_YCLIENTS_CLIENT_ID'),
                'client_secret' => env('AMO_YCLIENTS_CLIENT_SECRET'),
                'redirect_uri' => $widgetRedirectUri('AMO_YCLIENTS_REDIRECT_URI', '/api/amocrm/install/yclients'),
            ],
            'tilda' => [
                'client_id' => env('AMO_TILDA_CLIENT_ID'),
                'client_secret' => env('AMO_TILDA_CLIENT_SECRET'),
                'redirect_uri' => $widgetRedirectUri('AMO_TILDA_REDIRECT_URI', '/api/amocrm/install/tilda'),
            ],
            'distribution' => [
                'client_id' => env('AMO_DISTRIBUTION_CLIENT_ID'),
                'client_secret' => env('AMO_DISTRIBUTION_CLIENT_SECRET'),
            ],
            'workflows' => [
                'client_id' => env('AMO_WORKFLOWS_CLIENT_ID'),
                'client_secret' => env('AMO_WORKFLOWS_CLIENT_SECRET'),
                'redirect_uri' => $widgetRedirectUri('AMO_WORKFLOWS_REDIRECT_URI', '/api/amocrm/install/flow'),
            ],
            'sqns' => [
                'client_id' => env('AMO_SQNS_CLIENT_ID'),
                'client_secret' => env('AMO_SQNS_CLIENT_SECRET'),
                'redirect_uri' => $widgetRedirectUri('AMO_SQNS_REDIRECT_URI', '/api/amocrm/install/sqns'),
            ],
            'vetmanager' => [
                'client_id' => env('AMO_VETMANAGER_CLIENT_ID'),
                'client_secret' => env('AMO_VETMANAGER_CLIENT_SECRET'),
                'redirect_uri' => env('AMO_VETMANAGER_REDIRECT_URI', env('AMO_REDIRECT_URI')),
            ],
        ],
    ],
    'vetmanager' => [
        'allowed_host_suffixes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'VETMANAGER_ALLOWED_HOST_SUFFIXES',
                '.vetmanager.ru,.vetmanager2.ru,.vetmanager.cloud,.vetmanager2.cloud'
            ))
        ))),
    ],
    'yclients' => [
        'api_url' => env(
            'YCLIENTS_API_URL',
            'https://api.yclients.ru/api/v1'
        ),
        'marketplace_activation_url' => env(
            'YCLIENTS_MARKETPLACE_ACTIVATION_URL',
            'https://api.yclients.ru/marketplace/partner/callback'
        ),
        'marketplace_application_id' => env('YCLIENTS_MARKETPLACE_APPLICATION_ID'),
        'marketplace_partner_token' => env('YCLIENTS_MARKETPLACE_PARTNER_TOKEN'),
        'marketplace_user_token' => env('YCLIENTS_MARKETPLACE_USER_TOKEN'),
    ],
    'yandex' => [
        'local_storage_path'  => storage_path('app/public/'),
        'yandex_storage_path' => 'amoCRM/Documents/',
    ],
    'yandex_gpt' => [
        'api_key' => env('YANDEX_GPT_API_KEY'),
        'folder_id' => env('YANDEX_GPT_FOLDER_ID'),
        'model' => env('YANDEX_GPT_MODEL', 'yandexgpt'),
    ],
    'yandex_speechkit' => [
        'api_key' => env('YANDEX_SPEECHKIT_API_KEY'),
        'folder_id' => env('YANDEX_SPEECHKIT_FOLDER_ID'),
        'language' => env('YANDEX_SPEECHKIT_LANGUAGE', 'ru-RU'),
        'format' => env('YANDEX_SPEECHKIT_FORMAT', 'oggopus'),
        'sample_rate' => env('YANDEX_SPEECHKIT_SAMPLE_RATE', 48000),
    ],

    'telegram' => [
        'token' => env('TELEGRAM_ALERTS_TOKEN', env('TG_DEBUG_TOKEN')),
        'chat_id' => env('TELEGRAM_ALERTS_CHAT_ID', env('TG_DEBUG_CHAT_ID')),
    ],

    'clever_bayers_invoice_telegram' => [
        'token' => env('CLEVER_BAYERS_INVOICE_TG_TOKEN'),
        'chat_id' => env('CLEVER_BAYERS_INVOICE_TG_CHAT_ID'),
    ],
];
