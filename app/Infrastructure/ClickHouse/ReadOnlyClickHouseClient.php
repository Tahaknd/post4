<?php

namespace App\Infrastructure\ClickHouse;

use InvalidArgumentException;
use RuntimeException;

/**
 * ClickHouse client that connects as the read-only user (`posthog_ro`, profile `ro_insights`).
 * It does NOT extend the admin `ClickHouseClient`: it builds one inside (a private field) with the read-only
 * credentials and only offers `select()`: there is no `execute()` at all, DDL and INSERT belong to the admin
 * client (migrator, infrastructure) and the type system guarantees it. Query settings are limited to the
 * `RoSettings::ALLOWED` list. The server refuses writes as well (the only GRANT is SELECT ON posthog.project_events_v).
 *
 * Every read must carry the `SQL_project_id` setting: the view `posthog.project_events_v` (and the row policy
 * on it) rejects the query without the setting and shows only that project's rows with it; the user has no
 * privilege on `posthog.events`, so even a raw `select()` cannot read another project with mergeTreeIndex
 * (see `ProjectScopedReader`, the hard limits are described there). The value of this setting must be an
 * integer in the range 1..4294967295.
 */
final class ReadOnlyClickHouseClient implements ClickHouseReads
{
    /** `events.project_id` is a UInt32. */
    private const MAX_PROJECT_ID = 4294967295;

    private readonly ClickHouseClient $client;

    public function __construct(
        string $url,
        string $database,
        string $user,
        string $password,
        int $timeout = 25,
        int $connectTimeout = 10,
        int $selectRetries = 2,
        int $selectRetryBackoffMs = 100,
    ) {
        if ($password === '') {
            throw new RuntimeException('The read-only ClickHouse password is empty: set CLICKHOUSE_RO_PASSWORD (default posthog_ro, as in docker-compose.yml and docker/clickhouse/users.d/20-readonly-user.xml).');
        }

        $this->client = new ClickHouseClient($url, $database, $user, $password, $timeout, $connectTimeout, $selectRetries, $selectRetryBackoffMs);
    }

    public function select(string $sql, array $params = [], array $settings = [], ?int $timeout = null): array
    {
        foreach (array_keys($settings) as $name) {
            if (! in_array($name, RoSettings::ALLOWED, true)) {
                throw new InvalidArgumentException('ClickHouse setting '.var_export($name, true).' is not allowed for the read-only client; allowed: '.implode(', ', RoSettings::ALLOWED));
            }
        }

        if (array_key_exists(RoSettings::SQL_PROJECT_ID, $settings)) {
            $id = $settings[RoSettings::SQL_PROJECT_ID];
            if (! is_int($id) || $id < 1 || $id > self::MAX_PROJECT_ID) {
                throw new InvalidArgumentException('ClickHouse setting SQL_project_id must be an integer from 1 to '.self::MAX_PROJECT_ID.', got '.var_export($id, true));
            }
        }

        return $this->client->select($sql, $params, $settings, $timeout);
    }
}
