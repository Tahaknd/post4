<?php

namespace Tests\Support;

use App\Infrastructure\ClickHouse\ClickHouseReads;
use Throwable;

/**
 * A stand-in for the read-only client: replays queued answers (rows, or a Throwable to throw) and records
 * every read that reached "ClickHouse" in `$calls` (sql, params, settings, timeout). Without a queued answer
 * it returns no rows.
 */
final class ScriptedReads implements ClickHouseReads
{
    /** @var list<array{sql: string, params: array<string, mixed>, settings: array<string, mixed>, timeout: ?int}> */
    public array $calls = [];

    /** @param  list<array<int, array<string, mixed>>|Throwable>  $answers */
    public function __construct(private array $answers = []) {}

    public function select(string $sql, array $params = [], array $settings = [], ?int $timeout = null): array
    {
        $this->calls[] = compact('sql', 'params', 'settings', 'timeout');
        $next = array_shift($this->answers) ?? [];
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }
}
