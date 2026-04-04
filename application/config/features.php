<?php

return [
    'panel_actions' => [
        'queues' => (bool)env('FEATURE_QUEUES_ACTION', true),
        'failed_jobs' => (bool)env('FEATURE_FAILED_JOBS_ACTION', true),
        'auth_logs' => (bool)env('FEATURE_AUTH_LOGS_ACTION', true),
        'apps_stats' => (bool)env('FEATURE_APP_STATS_ACTION', true),
        'backups' => (bool)env('FEATURE_BACKUPS_ACTION', true),
    ],

    'queues' => [
        'monitor_navigation' => (bool)env('FEATURE_QUEUES_NAVIGATION', true),
        'failed_jobs_page' => (bool)env('FEATURE_FAILED_JOBS_PAGE', true),
    ],
];
