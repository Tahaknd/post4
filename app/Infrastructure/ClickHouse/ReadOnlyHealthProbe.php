<?php

namespace App\Infrastructure\ClickHouse;

use App\Domain\Dashboards\Refresh\ClickHouseHealth;
use Throwable;

/**
 * The health the scheduled refresh cares about: can the read-only user (the one every insight uses) get an answer to a
 * trivial query within a few seconds. Any failure is "not healthy"; the reason is not needed (capture:status has it).
 */
final class ReadOnlyHealthProbe implements ClickHouseHealth
{
    private const int TIMEOUT_SECONDS = 3;

    public function __construct(private readonly ReadOnlyClickHouseClient $client) {}

    public function isHealthy(): bool
    {
        try {
            return $this->client->select('SELECT 1 AS ok', timeout: self::TIMEOUT_SECONDS) !== [];
        } catch (Throwable) {
            return false;
        }
    }
}
