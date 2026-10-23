<?php

return [
    'url' => env('CLICKHOUSE_URL', 'http://clickhouse:8123'),
    'database' => env('CLICKHOUSE_DATABASE', 'posthog'),
    'user' => env('CLICKHOUSE_USER', 'posthog'),
    'password' => env('CLICKHOUSE_PASSWORD', ''),
    // Read-only user (docker/clickhouse/users.d/20-readonly-user.xml) used by the query layer.
    'readonly' => [
        'user' => env('CLICKHOUSE_RO_USER', 'posthog_ro'),
        'password' => env('CLICKHOUSE_RO_PASSWORD', ''),
    ],
    'timeout' => env('CLICKHOUSE_TIMEOUT', 30),
    'connect_timeout' => env('CLICKHOUSE_CONNECT_TIMEOUT', 10),
];
