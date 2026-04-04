<?php

return [
    'enabled' => (bool)env('ALERTS_ENABLED', true),

    'dedupe_ttl_seconds' => (int)env('ALERTS_DEDUPE_TTL', 900),

    'channels' => [
        'telegram' => [
            'enabled' => (bool)env('ALERTS_TG_ENABLED', true),
            'token' => env('TELEGRAM_ALERTS_TOKEN', env('TG_DEBUG_TOKEN')),
            'chat_id' => env('TELEGRAM_ALERTS_CHAT_ID', env('TG_DEBUG_CHAT_ID')),
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

    'queue' => [
        'stuck_after_seconds' => (int)env('ALERTS_QUEUE_STUCK_AFTER_SECONDS', 900),
    ],
];
