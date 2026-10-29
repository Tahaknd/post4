<?php

namespace App\Infrastructure\ClickHouse;

/**
 * One relation the read-only user may read, as ProjectScopedReader sees it: builder SQL addresses it by its CTE
 * `alias`, the reader prepends `alias AS (SELECT <columns> FROM posthog.<view>)`, and the DEFINER view (not this
 * object) does the tenant filtering on the server. The registry of them is {@see ScopedRelations}.
 *
 * `columns` is the EXPLICIT list of the CTE and of the view (never `SELECT *`: it would skip MATERIALIZED columns
 * and hide drift). `hiddenColumns` are columns of the BASE TABLE the view deliberately does not expose, so the
 * drift check can tell "the table gained a column nobody decided about" from "this one is hidden on purpose".
 */
final readonly class ScopedRelation
{
    /**
     * @param  string  $alias  the CTE name builder SQL selects from (`project_events`)
     * @param  string  $view  the DEFINER view in `posthog` the CTE reads (`project_events_v`)
     * @param  string  $table  the base table in `posthog` the view reads; posthog_ro has NO privilege on it
     * @param  list<string>  $columns  columns of the view and of the CTE, in view order
     * @param  list<string>  $hiddenColumns  columns of the table that the view does not expose
     * @param  string  $columnsName  how messages name the column list ("ProjectScopedReader::COLUMNS")
     */
    public function __construct(
        public string $alias,
        public string $view,
        public string $table,
        public array $columns,
        public array $hiddenColumns,
        public string $columnsName,
    ) {}

    /** `project_events AS (SELECT a, b FROM posthog.project_events_v)` */
    public function cteDefinition(): string
    {
        return $this->alias.' AS (SELECT '.implode(', ', $this->columns).' FROM posthog.'.$this->view.')';
    }

    /**
     * Every column the base table must have: the exposed ones and the deliberately hidden ones.
     *
     * @return list<string>
     */
    public function tableColumns(): array
    {
        return [...$this->columns, ...$this->hiddenColumns];
    }

    /** Whether builder SQL names the CTE (a word, any case: a false positive only adds an unused CTE). */
    public function isReferencedIn(string $sql): bool
    {
        return preg_match('/\b'.preg_quote($this->alias, '/').'\b/i', $sql) === 1;
    }
}
