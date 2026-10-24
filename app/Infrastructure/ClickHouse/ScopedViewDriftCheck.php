<?php

namespace App\Infrastructure\ClickHouse;

use Throwable;

/**
 * Compares the columns of `events`, of the scoped view (ProjectScopedReader::VIEW) and of ProjectScopedReader::COLUMNS.
 * The view lists its columns explicitly, so an `ALTER TABLE events ADD COLUMN` leaves it SILENTLY without the
 * new column (a DROP or RENAME makes reads fail, which is loud already). Run by `clickhouse:migrate` right after
 * the migrations: a migration that changes `events` must recreate the view (`CREATE OR REPLACE VIEW`, keeping
 * DEFINER and the WHERE of 002_scoped_events_view.sql) and update ProjectScopedReader::COLUMNS in the same
 * change, and this is the moment the mistake becomes visible (the same class of failure as a migration checksum
 * mismatch: found at the deploy that introduced it, not weeks later in a chart).
 */
final class ScopedViewDriftCheck
{
    public function __construct(private readonly ClickHouseClient $clickhouse) {}

    /** @return list<string> human readable problems, empty when everything agrees */
    public function problems(): array
    {
        try {
            $table = $this->columns('events');
            $view = $this->columns(ProjectScopedReader::VIEW);
        } catch (Throwable $e) {
            return ['cannot read the columns of events and '.ProjectScopedReader::VIEW.': '.mb_substr($e->getMessage(), 0, 200)];
        }

        $expected = ProjectScopedReader::COLUMNS;
        $problems = [];
        foreach ([['events table', $table], ['view '.ProjectScopedReader::VIEW, $view]] as [$what, $actual]) {
            $missing = array_values(array_diff($expected, $actual));
            $extra = array_values(array_diff($actual, $expected));
            if ($missing !== []) {
                $problems[] = "{$what} lacks the columns ProjectScopedReader::COLUMNS lists: ".implode(', ', $missing);
            }
            if ($extra !== []) {
                $problems[] = "{$what} has columns ProjectScopedReader::COLUMNS does not list: ".implode(', ', $extra);
            }
        }

        if ($problems !== []) {
            $problems[] = 'fix: recreate the view with CREATE OR REPLACE VIEW in a new migration (keep DEFINER and the WHERE of 002_scoped_events_view.sql) and update ProjectScopedReader::COLUMNS';
        }

        return $problems;
    }

    /** @return list<string> */
    private function columns(string $relation): array
    {
        return array_map(fn (array $row) => (string) $row['name'], $this->clickhouse->select('DESCRIBE TABLE '.$relation));
    }
}
