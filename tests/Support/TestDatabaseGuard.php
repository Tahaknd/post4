<?php

namespace Tests\Support;

/**
 * Decides whether the database a test run resolved to is one tests may use. TestCase::refreshApplication() asks it
 * before any test (and before DatabaseTruncation's migrate:fresh) can touch the connection.
 *
 * Two databases are acceptable, and nothing else:
 *   - the in-memory SQLite database of the default phpunit.xml;
 *   - the throwaway Postgres of the opt-in phpunit.pgsql.xml, and only when ALL of these hold: TESTS_ALLOW_PGSQL=1
 *     is set, the host is exactly `postgres-test` (the compose service on tmpfs, profile `test`), the database name
 *     ends in `_test`, and nothing can redirect the connection elsewhere (no DB_URL, no read/write host lists).
 *     Every one of them has to be pinned by phpunit.pgsql.xml: a developer's shell or .env cannot satisfy them by
 *     accident, because the live stack's host is `postgres` and its database is `posthog`.
 */
final class TestDatabaseGuard
{
    public const string PGSQL_HOST = 'postgres-test';

    public const string PGSQL_DATABASE_SUFFIX = '_test';

    /**
     * @param  array<string, mixed>  $connection  the resolved config of the default connection
     * @return string|null why the database is refused, or null when it is acceptable
     */
    public static function problem(string $driver, array $connection, mixed $allowPgsql): ?string
    {
        $database = $connection['database'] ?? null;

        if ($driver === 'sqlite') {
            return $database === ':memory:' ? null : "database={$driver}/".self::describe($database);
        }

        if ($driver !== 'pgsql') {
            return "database={$driver}/".self::describe($database);
        }

        $problems = [];

        if ((string) $allowPgsql !== '1') {
            $problems[] = 'TESTS_ALLOW_PGSQL is not 1';
        }

        if (($connection['host'] ?? null) !== self::PGSQL_HOST) {
            $problems[] = 'pgsql host is not exactly '.self::PGSQL_HOST;
        }

        if (! is_string($database) || ! str_ends_with($database, self::PGSQL_DATABASE_SUFFIX) || strlen($database) === strlen(self::PGSQL_DATABASE_SUFFIX)) {
            $problems[] = 'pgsql database name does not end in '.self::PGSQL_DATABASE_SUFFIX;
        }

        foreach (['url', 'read', 'write'] as $redirect) {
            if (! empty($connection[$redirect])) {
                $problems[] = "pgsql connection sets {$redirect}, which can point it at another server";
            }
        }

        return $problems === [] ? null : 'database=pgsql/'.self::describe($database).' ('.implode(', ', $problems).')';
    }

    private static function describe(mixed $database): string
    {
        return is_scalar($database) ? (string) $database : get_debug_type($database);
    }

    private function __construct() {}
}
