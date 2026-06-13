<?php

return [
    'public_url' => env('WORKFLOW_PUBLIC_URL'),

    'queue' => [
        'connection' => env('WORKFLOW_WEBHOOKS_QUEUE_CONNECTION'),
        'name' => env('WORKFLOW_WEBHOOKS_QUEUE', 'default'),
    ],
];
