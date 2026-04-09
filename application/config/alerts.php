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
        'auto_heal' => [
            'enabled' => (bool)env('ALERTS_QUEUE_AUTO_HEAL_ENABLED', true),
            'release_after_seconds' => (int)env('ALERTS_QUEUE_RELEASE_AFTER_SECONDS', 1800),
            'max_releases_per_run' => (int)env('ALERTS_QUEUE_RELEASE_MAX', 100),
            'exclude_queues' => array_values(
                array_filter(
                    array_map(
                        static fn(string $queue): string => trim($queue),
                        explode(',', (string)env('ALERTS_QUEUE_EXCLUDE', 'amo_data')),
                    )
                )
            ),
        ],
    ],
];
