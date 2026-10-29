<?php

namespace App\Infrastructure\ClickHouse;

use InvalidArgumentException;

/**
 * The statement a migration that changes the columns of a scoped relation's base table must contain: the scoped
 * view over exactly the registry's column list (the same source ScopedViewDriftCheck compares with), DEFINER and
 * filter as in 002_scoped_events_view.sql / 004_person_distinct_ids.sql, on one line. Printed by
 * `php artisan clickhouse:view-sql [events|person_distinct_ids]`, so nobody types the column list by hand twice.
 */
final class ScopedViewStatement
{
    /** The events view (ProjectScopedReader::COLUMNS). */
    public static function sql(): string
    {
        return 'CREATE OR REPLACE VIEW '.ProjectScopedReader::VIEW.' DEFINER = CURRENT_USER SQL SECURITY DEFINER AS SELECT '
            .implode(', ', ProjectScopedReader::COLUMNS)." FROM events WHERE project_id = toUInt32(getSetting('SQL_project_id'))";
    }

    /**
     * The deduplicated person mapping view. The aggregates carry `latest_*` names inside and are renamed outside
     * (see the long comment in 004_person_distinct_ids.sql for why), so the exposed names come from
     * ScopedRelations::PERSON_DISTINCT_IDS_COLUMNS and only the two key columns pass through unchanged.
     */
    public static function personDistinctIdsSql(): string
    {
        $outer = array_map(
            fn (string $column) => in_array($column, ['project_id', 'distinct_id'], true) ? $column : "latest_{$column} AS {$column}",
            ScopedRelations::PERSON_DISTINCT_IDS_COLUMNS,
        );

        return 'CREATE OR REPLACE VIEW '.ScopedRelations::PERSON_DISTINCT_IDS_VIEW.' DEFINER = CURRENT_USER SQL SECURITY DEFINER AS SELECT '
            .implode(', ', $outer).' FROM ( SELECT project_id, distinct_id, argMax(person_id, version) AS latest_person_id, max(version) AS latest_version, '
            .'argMax(created_at, version) AS latest_created_at, argMax(is_deleted, version) AS latest_is_deleted FROM '.ScopedRelations::PERSON_DISTINCT_IDS_TABLE
            ." WHERE project_id = toUInt32(getSetting('SQL_project_id')) GROUP BY project_id, distinct_id ) WHERE latest_is_deleted = 0";
    }

    /** @param  string  $relation  `events` or `person_distinct_ids` (the base table name) */
    public static function for(string $relation): string
    {
        return match ($relation) {
            'events' => self::sql(),
            ScopedRelations::PERSON_DISTINCT_IDS_TABLE => self::personDistinctIdsSql(),
            default => throw new InvalidArgumentException("Unknown scoped relation '{$relation}'; use events or ".ScopedRelations::PERSON_DISTINCT_IDS_TABLE),
        };
    }

    private function __construct() {}
}
