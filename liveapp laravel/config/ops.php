<?php

return [
    'request_debug_logger_enabled' => (bool) env('OPS_REQUEST_DEBUG_LOGGER_ENABLED', false),

    'metrics' => [
        // Optional shared key for metrics endpoint: send as X-Metrics-Key header.
        'key' => env('OPS_METRICS_KEY'),
    ],

    'health' => [
        // If true, include exception messages in dependency payload.
        'expose_errors' => (bool) env('OPS_HEALTH_EXPOSE_ERRORS', false),
    ],
];
