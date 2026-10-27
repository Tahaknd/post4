<?php

namespace Tests\Support;

use App\Infrastructure\ClickHouse\ClickHouseMigrator;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use RuntimeException;

/**
 * Folds ALL ClickHouse migrations (database/clickhouse/migrations/*.sql, in file name order) into what they
 * leave behind, WITHOUT a server: the final column list of `events` and the final column list of the scoped
 * view. The static tests compare these with ProjectScopedReader::COLUMNS, so a new migration that adds a
 * column needs no test surgery: it only has to be consistent (and the tests tell the author what is missing).
 *
 * Understood: `CREATE TABLE [IF NOT EXISTS] events (...)` (columns in order), and per statement
 * `ALTER TABLE events ADD COLUMN [IF NOT EXISTS] <name> ...` (appended unless it exists),
 * `DROP COLUMN [IF EXISTS] <name>`, `RENAME COLUMN <a> TO <b>`, and `CREATE [OR REPLACE] VIEW project_events_v
 * ... AS SELECT <columns> FROM events` (the last one wins). `ADD COLUMN ... AFTER/FIRST` is rejected loudly
 * (the fold would get the order wrong); extend this class on purpose if that is ever needed.
 */
final class MigrationColumns
{
    /** @return list<string> migration file names in application order */
    public static function files(): array
    {
        $files = glob(base_path('database/clickhouse/migrations/*.sql')) ?: [];
        sort($files);

        return array_map('basename', $files);
    }

    /** @return list<string> columns of `events` after every migration, in order */
    public static function tableColumns(): array
    {
        return self::fold()['table'];
    }

    /** @return list<string> columns the final scoped view selects, in order */
    public static function viewColumns(): array
    {
        return self::fold()['view'];
    }

    /** Name of the last migration that creates or replaces the scoped view, or null. */
    public static function lastViewMigration(): ?string
    {
        return self::fold()['viewFile'];
    }

    /** Name of the last migration that changes the columns of `events`, or null. */
    public static function lastColumnMigration(): ?string
    {
        return self::fold()['columnFile'];
    }

    /** The final CREATE ... VIEW statement with whitespace collapsed (comments are already gone). */
    public static function viewStatement(): string
    {
        return self::fold()['viewSql'];
    }

    /**
     * @return array{table: list<string>, view: list<string>, viewFile: ?string, columnFile: ?string, viewSql: string}
     */
    private static function fold(): array
    {
        $table = [];
        $view = [];
        $viewFile = null;
        $columnFile = null;
        $viewSql = '';

        foreach (self::files() as $file) {
            $sql = (string) file_get_contents(base_path('database/clickhouse/migrations/'.$file));
            foreach (ClickHouseMigrator::statements($sql) as $statement) {
                $flat = trim((string) preg_replace('/\s+/', ' ', $statement));

                if (preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?(?:posthog\.)?events \((.*)\) ENGINE/s', $flat, $m) === 1) {
                    $table = self::definitionNames($m[1]);
                    $columnFile = $file;
                } elseif (preg_match('/^ALTER TABLE (?:posthog\.)?events ADD COLUMN (?:IF NOT EXISTS )?(\w+) /', $flat, $m) === 1) {
                    if (preg_match('/ (AFTER|FIRST)\b/', $flat) === 1) {
                        throw new RuntimeException("{$file}: ADD COLUMN ... AFTER/FIRST is not understood by MigrationColumns");
                    }
                    if (! in_array($m[1], $table, true)) {
                        $table[] = $m[1];
                    }
                    $columnFile = $file;
                } elseif (preg_match('/^ALTER TABLE (?:posthog\.)?events DROP COLUMN (?:IF EXISTS )?(\w+)/', $flat, $m) === 1) {
                    $table = array_values(array_diff($table, [$m[1]]));
                    $columnFile = $file;
                } elseif (preg_match('/^ALTER TABLE (?:posthog\.)?events RENAME COLUMN (?:IF EXISTS )?(\w+) TO (\w+)/', $flat, $m) === 1) {
                    $table = array_map(fn (string $c) => $c === $m[1] ? $m[2] : $c, $table);
                    $columnFile = $file;
                } elseif (preg_match('/^CREATE (?:OR REPLACE )?VIEW (?:IF NOT EXISTS )?(?:posthog\.)?'.ProjectScopedReader::VIEW.' .*? AS SELECT (.+?) FROM (?:posthog\.)?events\b/', $flat, $m) === 1) {
                    $view = array_map('trim', explode(',', $m[1]));
                    $viewFile = $file;
                    $viewSql = $flat;
                }
            }
        }

        return ['table' => $table, 'view' => $view, 'viewFile' => $viewFile, 'columnFile' => $columnFile, 'viewSql' => $viewSql];
    }

    /**
     * Column names of a CREATE TABLE body: the first word of every top level comma separated definition.
     *
     * @return list<string>
     */
    private static function definitionNames(string $body): array
    {
        $names = [];
        $depth = 0;
        $current = '';
        foreach (str_split($body) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }
            if ($char === ',' && $depth === 0) {
                $names[] = $current;
                $current = '';

                continue;
            }
            $current .= $char;
        }
        $names[] = $current;

        return array_values(array_filter(array_map(fn (string $d) => trim(strtok(trim($d), ' ') ?: ''), $names)));
    }

    private function __construct() {}
}
