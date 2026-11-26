<?php

return [
    'enabled' => (bool)env('ALERTS_ENABLED', true),

    'dedupe_ttl_seconds' => (int)env('ALERTS_DEDUPE_TTL', 900),

    'cache_store' => env('ALERTS_CACHE_STORE', 'monitoring'),

    'integration_errors' => [
        'enabled' => (bool) env('ALERTS_INTEGRATION_ERRORS_ENABLED', env('APP_ENV') !== 'testing'),
        'cooldown_seconds' => (int) env('ALERTS_INTEGRATION_ERRORS_COOLDOWN', 1800),
    ],

    'channels' => [
        'telegram' => [
            'enabled' => (bool)env('ALERTS_TG_ENABLED', true),
            'token' => env('TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('TELEGRAM_CHAT_ID'),
        ],

        'mail' => [
            'enabled' => (bool)env('ALERTS_MAIL_ENABLED', false),
            'to' => array_values(
                array_filter(
                    array_map(
                        static fn(string $email): string => trim($email),
                        explode(',', (string)env('ALERTS_MAIL_TO', '')),
                    )
                )
            ),
        ],
    ],
];
