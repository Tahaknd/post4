<?php

namespace App\Infrastructure\ClickHouse;

/**
 * The project ids that have at least one row in posthog.events, read with the admin client (the sweeper compares
 * them with Postgres). project_id leads events' sort key, so the distinct scan is cheap; the cap bounds the answer.
 */
class EventProjectIds
{
    /** More distinct projects than this in one sweep is not expected; the rest is picked up by the next run. */
    public const int LIMIT = 100_000;

    public function __construct(private readonly ClickHouseClient $clickHouse) {}

    /** @return list<int> ascending */
    public function all(): array
    {
        $rows = $this->clickHouse->select(
            'SELECT DISTINCT project_id FROM posthog.events ORDER BY project_id LIMIT '.self::LIMIT,
            settings: ['max_execution_time' => 20],
            timeout: 25,
        );

        return array_map(fn (array $row) => (int) $row['project_id'], $rows);
    }
}
