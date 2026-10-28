<?php

namespace App\Infrastructure\ClickHouse;

/**
 * The statement a migration that changes the columns of `events` must contain: the scoped view over exactly
 * ProjectScopedReader::COLUMNS (the same source ScopedViewDriftCheck compares with), DEFINER and filter as in
 * 002_scoped_events_view.sql, on one line. Printed by `php artisan clickhouse:view-sql`, so nobody types the
 * column list by hand twice.
 */
final class ScopedViewStatement
{
    public static function sql(): string
    {
        return 'CREATE OR REPLACE VIEW '.ProjectScopedReader::VIEW.' DEFINER = CURRENT_USER SQL SECURITY DEFINER AS SELECT '
            .implode(', ', ProjectScopedReader::COLUMNS)." FROM events WHERE project_id = toUInt32(getSetting('SQL_project_id'))";
    }

    private function __construct() {}
}
