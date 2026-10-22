<?php

return [
    'url' => env('CLICKHOUSE_URL', 'http://clickhouse:8123'),
    'database' => env('CLICKHOUSE_DATABASE', 'posthog'),
    'user' => env('CLICKHOUSE_USER', 'posthog'),
    'password' => env('CLICKHOUSE_PASSWORD', ''),
    'timeout' => env('CLICKHOUSE_TIMEOUT', 30),
    'connect_timeout' => env('CLICKHOUSE_CONNECT_TIMEOUT', 10),
];
