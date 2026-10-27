<?php

namespace Tests\Support;

use App\Infrastructure\ClickHouse\ClickHouseReads;

/** A stand-in for the read-only client whose every read takes `$milliseconds` and answers one event name. */
final class SlowReads implements ClickHouseReads
{
    public int $calls = 0;

    public function __construct(private readonly int $milliseconds) {}

    public function select(string $sql, array $params = [], array $settings = [], ?int $timeout = null): array
    {
        $this->calls++;
        usleep($this->milliseconds * 1000);

        return [['name' => 'ev'.$this->calls, 'total' => 1]];
    }
}
