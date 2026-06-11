<?php

return [
    'queue' => [
        'connection' => env('WORKFLOW_WEBHOOKS_QUEUE_CONNECTION'),
        'name' => env('WORKFLOW_WEBHOOKS_QUEUE', 'default'),
    ],
];
