<?php

namespace App\Infrastructure\ClickHouse;

/**
 * Contract for reading rows from ClickHouse. The admin `ClickHouseClient` and the read-only
 * `ReadOnlyClickHouseClient` implement it; the query layer depends only on this interface, so the
 * ability to write stays out of reach by the type system.
 */
interface ClickHouseReads
{
    /**
     * @param  array<string, mixed>  $params  Bound parameters
     * @param  array<string, bool|int|float|string>  $settings  Fixed query settings (NOT user input)
     * @param  int|null  $timeout  Per-call HTTP timeout (seconds); null means the client default
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = [], array $settings = [], ?int $timeout = null): array;
}
