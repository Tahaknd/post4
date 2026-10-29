<?php

namespace App\Infrastructure\ClickHouse;

use Throwable;

/**
 * For every relation of {@see ScopedRelations} (events, the person mapping) compares the columns of its base table,
 * of its scoped view and of the registered column list. A view lists its columns explicitly, so an
 * `ALTER TABLE ... ADD COLUMN` leaves it SILENTLY without the new column (a DROP or RENAME makes reads fail, which is
 * loud already). Run by `clickhouse:migrate` right after the migrations: a migration that changes a base table must
 * recreate the view (`CREATE OR REPLACE VIEW`, keeping DEFINER and the WHERE of 002_scoped_events_view.sql /
 * 004_person_distinct_ids.sql) and update the registered column list in the same change, and this is the moment
 * the mistake becomes visible (the same class of failure as a migration checksum mismatch: found at the deploy
 * that introduced it, not weeks later in a chart).
 *
 * The table side is compared with the exposed columns PLUS the columns the view hides on purpose (for
 * person_distinct_ids: `is_deleted`), so a hidden column is a decision written down in the registry, never an
 * accident.
 */
final class ScopedViewDriftCheck
{
    public function __construct(private readonly ClickHouseClient $clickhouse) {}

    /** @return list<string> human readable problems, empty when everything agrees */
    public function problems(): array
    {
        $problems = [];
        foreach (ScopedRelations::all() as $relation) {
            try {
                $table = $this->columns($relation->table);
                $view = $this->columns($relation->view);
            } catch (Throwable $e) {
                return ['cannot read the columns of '.$relation->table.' and '.$relation->view.': '.mb_substr($e->getMessage(), 0, 200)];
            }

            foreach ([[$relation->table.' table', $table, $relation->tableColumns()], ['view '.$relation->view, $view, $relation->columns]] as [$what, $actual, $expected]) {
                $missing = array_values(array_diff($expected, $actual));
                $extra = array_values(array_diff($actual, $expected));
                if ($missing !== []) {
                    $problems[] = "{$what} lacks the columns {$relation->columnsName} lists: ".implode(', ', $missing);
                }
                if ($extra !== []) {
                    $problems[] = "{$what} has columns {$relation->columnsName} does not list: ".implode(', ', $extra);
                }
            }
        }

        if ($problems !== []) {
            $problems[] = 'fix: recreate the view with CREATE OR REPLACE VIEW in a new migration (keep DEFINER and the WHERE of the original view migration) and update the column list named above (`php artisan clickhouse:view-sql events|person_distinct_ids` prints the statement for the current list)';
        }

        return $problems;
    }

    /** @return list<string> */
    private function columns(string $relation): array
    {
        return array_map(fn (array $row) => (string) $row['name'], $this->clickhouse->select('DESCRIBE TABLE '.$relation));
    }
}
