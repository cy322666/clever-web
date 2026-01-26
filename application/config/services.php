<?php

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
];
