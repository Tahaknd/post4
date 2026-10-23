<?php

return [
    'url' => env('CLICKHOUSE_URL', 'http://clickhouse:8123'),
    'database' => env('CLICKHOUSE_DATABASE', 'posthog'),
    'user' => env('CLICKHOUSE_USER', 'posthog'),
    'password' => env('CLICKHOUSE_PASSWORD', ''),
    // Read-only user (docker/clickhouse/users.d/20-readonly-user.xml) used by the query layer. The user
    // name is a CONSTANT: the XML hard-codes it, so an env knob would only break authentication. The
    // password default matches docker-compose.yml and the XML. Timeout chain: server execution time
    // default 20 s, constraint cap 22 s, this HTTP timeout 25 s.
    'readonly' => [
        'user' => 'posthog_ro',
        'password' => env('CLICKHOUSE_RO_PASSWORD', 'posthog_ro'),
        'timeout' => (int) env('CLICKHOUSE_RO_TIMEOUT', 25),
    ],
    'timeout' => env('CLICKHOUSE_TIMEOUT', 30),
    'connect_timeout' => env('CLICKHOUSE_CONNECT_TIMEOUT', 10),
];
