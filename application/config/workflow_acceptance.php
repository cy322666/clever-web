<?php

return [
    // Opt-in: this performs real mutations in one explicitly selected technical account.
    'enabled' => (bool) env('WORKFLOW_ACCEPTANCE_ENABLED', false),
    'workflow_id' => (int) env('WORKFLOW_ACCEPTANCE_WORKFLOW_ID', 15),
    'domain' => env('WORKFLOW_ACCEPTANCE_DOMAIN', 'widgetscenario'),
    'amo_account_id' => (int) env('WORKFLOW_ACCEPTANCE_AMO_ACCOUNT_ID', 33098322),
    'time' => env('WORKFLOW_ACCEPTANCE_TIME', '03:00'),
    'timezone' => env('WORKFLOW_ACCEPTANCE_TIMEZONE', 'Europe/Kaliningrad'),
    'timeout_seconds' => 900,
    'report_directory' => storage_path('app/private/workflow-acceptance'),
    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'message_thread_id' => env('TELEGRAM_MESSAGE_THREAD_ID'),
    ],
];
