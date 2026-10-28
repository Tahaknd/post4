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
 * Understood (keywords case-insensitive, identifiers may be `quoted`, "quoted" or prefixed with `posthog.`):
 *  - `CREATE TABLE [IF NOT EXISTS] events (...)`: the columns in order; INDEX, PROJECTION and CONSTRAINT lines
 *    of the body are not columns;
 *  - `ALTER TABLE events <action>[, <action> ...]` with ADD COLUMN (appended unless present), DROP COLUMN,
 *    RENAME COLUMN a TO b and MODIFY COLUMN (the name stays), every action of a multi-action statement;
 *  - `CREATE [OR REPLACE] VIEW project_events_v ... AS SELECT <columns> FROM events`: the last one wins.
 * Understood and without effect on the columns (a small whitelist): MATERIALIZE COLUMN/INDEX/PROJECTION,
 * ADD/DROP/CLEAR INDEX and PROJECTION, ADD/DROP CONSTRAINT, MODIFY TTL/SETTING/COMMENT, REMOVE TTL, UPDATE,
 * DELETE WHERE, `OPTIMIZE TABLE events`, `INSERT INTO events`, `DELETE FROM events`.
 * ANYTHING ELSE that targets `events` or the scoped view THROWS (a DROP or RENAME of the table, CREATE ... AS,
 * ADD COLUMN ... AFTER/FIRST, a view that is not a plain SELECT ... FROM events, an unknown ALTER action): a
 * silent mis-fold would make every column test meaningless, so extend this class on purpose instead.
 */
final class MigrationColumns
{
    /** ALTER actions on `events` that cannot change its column list. */
    private const HARMLESS_ACTIONS = '/^(?:MATERIALIZE (?:COLUMN|INDEX|PROJECTION) |(?:ADD|DROP|CLEAR) (?:INDEX|PROJECTION) |(?:ADD|DROP) CONSTRAINT |MODIFY (?:TTL|SETTING|COMMENT)\b|REMOVE TTL\b|UPDATE |DELETE WHERE )/i';

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
        return self::foldFiles()['table'];
    }

    /** @return list<string> columns the final scoped view selects, in order */
    public static function viewColumns(): array
    {
        return self::foldFiles()['view'];
    }

    /** Name of the last migration that creates or replaces the scoped view, or null. */
    public static function lastViewMigration(): ?string
    {
        return self::foldFiles()['viewFile'];
    }

    /** Name of the last migration that changes the columns of `events`, or null. */
    public static function lastColumnMigration(): ?string
    {
        return self::foldFiles()['columnFile'];
    }

    /** The final CREATE ... VIEW statement with whitespace collapsed (comments are already gone). */
    public static function viewStatement(): string
    {
        return self::foldFiles()['viewSql'];
    }

    /**
     * @param  array<string, string>  $migrations  file name => SQL, in application order
     * @return array{table: list<string>, view: list<string>, viewFile: ?string, columnFile: ?string, viewSql: string}
     */
    public static function fold(array $migrations): array
    {
        $state = ['table' => [], 'view' => [], 'viewFile' => null, 'columnFile' => null, 'viewSql' => ''];

        foreach ($migrations as $file => $sql) {
            foreach (ClickHouseMigrator::statements($sql) as $statement) {
                self::statement($state, $file, trim((string) preg_replace('/\s+/', ' ', str_replace(['`', '"'], '', $statement))));
            }
        }

        return $state;
    }

    /** @return array{table: list<string>, view: list<string>, viewFile: ?string, columnFile: ?string, viewSql: string} */
    private static function foldFiles(): array
    {
        $migrations = [];
        foreach (self::files() as $file) {
            $migrations[$file] = (string) file_get_contents(base_path('database/clickhouse/migrations/'.$file));
        }

        return self::fold($migrations);
    }

    /** @param  array{table: list<string>, view: list<string>, viewFile: ?string, columnFile: ?string, viewSql: string}  $state */
    private static function statement(array &$state, string $file, string $sql): void
    {
        $view = ProjectScopedReader::VIEW;
        $kind = '(?:CREATE(?: OR REPLACE)?(?: TEMPORARY)?(?: MATERIALIZED| LIVE| WINDOW)? (?:TABLE|VIEW)|ALTER TABLE|DROP (?:TABLE|VIEW)|TRUNCATE(?: TABLE)?|OPTIMIZE TABLE|RENAME TABLE|EXCHANGE TABLES|DELETE FROM|INSERT INTO|DETACH TABLE|ATTACH TABLE)';

        if (preg_match('/^'.$kind.' (?:IF (?:NOT )?EXISTS )?(?:posthog\.)?(\w+)/i', $sql, $m) !== 1) {
            return;   // not about a relation at all (SELECT 1, SET, ...)
        }
        $target = strtolower($m[1]);

        // RENAME and EXCHANGE name two relations: either one being `events` or the view is a structural change.
        if (preg_match('/^(?:RENAME TABLE|EXCHANGE TABLES) /i', $sql) === 1) {
            if (preg_match('/\b(?:posthog\.)?(?:events|'.$view.')\b/i', $sql) === 1) {
                throw new RuntimeException("{$file}: RENAME/EXCHANGE of events or {$view} is not understood by MigrationColumns: {$sql}");
            }

            return;
        }

        if ($target === 'events') {
            self::eventsStatement($state, $file, $sql);
        } elseif ($target === $view) {
            self::foldView($state, $file, $sql);
        }
    }

    /** @param  array{table: list<string>, view: list<string>, viewFile: ?string, columnFile: ?string, viewSql: string}  $state */
    private static function eventsStatement(array &$state, string $file, string $sql): void
    {
        if (preg_match('/^(?:OPTIMIZE TABLE|INSERT INTO|DELETE FROM) /i', $sql) === 1) {
            return;
        }

        if (preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?(?:posthog\.)?events\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE) === 1) {
            $start = $m[0][1] + strlen($m[0][0]);
            $body = self::balanced($sql, $start);
            if ($body === null) {
                throw new RuntimeException("{$file}: cannot find the end of the CREATE TABLE events column list");
            }
            $state['table'] = self::definitionNames($body);
            $state['columnFile'] = $file;

            return;
        }

        if (preg_match('/^ALTER TABLE (?:IF EXISTS )?(?:posthog\.)?events (.+)$/is', $sql, $m) === 1) {
            foreach (self::topLevel($m[1]) as $action) {
                self::alterAction($state, $file, trim($action));
            }

            return;
        }

        throw new RuntimeException("{$file}: this statement on events is not understood by MigrationColumns (extend it on purpose): {$sql}");
    }

    /** @param  array{table: list<string>, view: list<string>, viewFile: ?string, columnFile: ?string, viewSql: string}  $state */
    private static function alterAction(array &$state, string $file, string $action): void
    {
        if (preg_match('/ (?:AFTER|FIRST)\b/i', $action) === 1 && preg_match('/^(?:ADD|MODIFY) COLUMN /i', $action) === 1) {
            throw new RuntimeException("{$file}: ADD/MODIFY COLUMN ... AFTER/FIRST is not understood by MigrationColumns: {$action}");
        }

        if (preg_match('/^ADD COLUMN (?:IF NOT EXISTS )?(\w+) /i', $action, $m) === 1) {
            if (! in_array($m[1], $state['table'], true)) {
                $state['table'][] = $m[1];
            }
            $state['columnFile'] = $file;
        } elseif (preg_match('/^DROP COLUMN (?:IF EXISTS )?(\w+)$/i', $action, $m) === 1) {
            $state['table'] = array_values(array_diff($state['table'], [$m[1]]));
            $state['columnFile'] = $file;
        } elseif (preg_match('/^RENAME COLUMN (?:IF EXISTS )?(\w+) TO (\w+)$/i', $action, $m) === 1) {
            $state['table'] = array_map(fn (string $c) => $c === $m[1] ? $m[2] : $c, $state['table']);
            $state['columnFile'] = $file;
        } elseif (preg_match('/^MODIFY COLUMN (?:IF EXISTS )?\w+ /i', $action) === 1) {
            $state['columnFile'] = $file;   // the type changes, the name does not: the view must still be recreated
        } elseif (preg_match(self::HARMLESS_ACTIONS, $action) !== 1) {
            throw new RuntimeException("{$file}: this ALTER action on events is not understood by MigrationColumns: {$action}");
        }
    }

    /** @param  array{table: list<string>, view: list<string>, viewFile: ?string, columnFile: ?string, viewSql: string}  $state */
    private static function foldView(array &$state, string $file, string $sql): void
    {
        if (preg_match('/^CREATE (?:OR REPLACE )?VIEW (?:IF NOT EXISTS )?(?:posthog\.)?'.ProjectScopedReader::VIEW.' .*? AS SELECT (.+?) FROM (?:posthog\.)?events\b/i', $sql, $m) !== 1) {
            throw new RuntimeException("{$file}: this statement on the scoped view is not a plain CREATE [OR REPLACE] VIEW ... AS SELECT <columns> FROM events: {$sql}");
        }

        $state['view'] = array_map('trim', explode(',', $m[1]));
        $state['viewFile'] = $file;
        $state['viewSql'] = $sql;
    }

    /** Text between the opening parenthesis that ends at $start and its closing one, or null. */
    private static function balanced(string $sql, int $start): ?string
    {
        $depth = 1;
        $quote = false;
        for ($i = $start, $n = strlen($sql); $i < $n; $i++) {
            $c = $sql[$i];
            if ($c === "'") {
                $quote = ! $quote;
            } elseif (! $quote && $c === '(') {
                $depth++;
            } elseif (! $quote && $c === ')' && --$depth === 0) {
                return substr($sql, $start, $i - $start);
            }
        }

        return null;
    }

    /**
     * Splits on commas that are not inside parentheses or single quoted strings.
     *
     * @return list<string>
     */
    private static function topLevel(string $text): array
    {
        $parts = [];
        $depth = 0;
        $quote = false;
        $current = '';
        foreach (str_split($text) as $char) {
            if ($char === "'") {
                $quote = ! $quote;
            } elseif (! $quote && $char === '(') {
                $depth++;
            } elseif (! $quote && $char === ')') {
                $depth--;
            }
            if ($char === ',' && $depth === 0 && ! $quote) {
                $parts[] = $current;
                $current = '';

                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;

        return array_values(array_filter(array_map('trim', $parts), fn (string $p) => $p !== ''));
    }

    /**
     * Column names of a CREATE TABLE body: the first word of every top level definition that is not an INDEX,
     * PROJECTION or CONSTRAINT.
     *
     * @return list<string>
     */
    private static function definitionNames(string $body): array
    {
        $names = [];
        foreach (self::topLevel($body) as $definition) {
            if (preg_match('/^(?:INDEX|PROJECTION|CONSTRAINT)\b/i', $definition) === 1) {
                continue;
            }
            $names[] = strtok($definition, ' ') ?: '';
        }

        return array_values(array_filter($names));
    }

    private function __construct() {}
}
