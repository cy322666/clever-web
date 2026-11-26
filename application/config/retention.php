<?php

return [
    'api_requests_days' => (int) env('API_REQUESTS_RETENTION_DAYS', 3),
    'api_errors_days' => (int) env('API_REQUESTS_ERROR_RETENTION_DAYS', 30),
    'import_records_days' => 10,
];
