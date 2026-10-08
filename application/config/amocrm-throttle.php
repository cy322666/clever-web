<?php

return [
    // Shared by HTTP, SDK, queue workers and web processes. Never use array/file cache.
    'redis_connection' => env('AMOCRM_THROTTLE_REDIS_CONNECTION', 'amocrm_throttle'),
    // Keep headroom below amoCRM's 7 requests/s per integration and 50 per account.
    'integration_interval_ms' => 200,
    'account_interval_ms' => 25,
    'max_wait_seconds' => 30,
    'fallback_cooldown_seconds' => 2,
];
