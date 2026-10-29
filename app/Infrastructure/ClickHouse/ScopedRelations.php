<?php

namespace App\Infrastructure\ClickHouse;

/**
 * The small registry of relations ProjectScopedReader can address (name = CTE alias, view, allowed columns).
 *
 *   alias                         view                              base table (no grant for posthog_ro)
 *   project_events                project_events_v                  events
 *   project_person_distinct_ids   project_person_distinct_ids_v     person_distinct_ids
 *
 * HOW A RELATION IS ADDED (the checklist; every step has a test that fails when it is forgotten):
 *  1. a migration with the table and a `DEFINER = CURRENT_USER SQL SECURITY DEFINER` view filtered by
 *     `project_id = toUInt32(getSetting('SQL_project_id'))` (the migrator runs it with SQL_project_id=0);
 *  2. one `GRANT SELECT ON posthog.<view>` line and one row policy `<filter>` in users.d/20-readonly-user.xml
 *     (ReadOnlyUserPolicyFileTest and scripts/check-readonly-user.sh);
 *  3. an entry here, and the names of the view and of the base tables in ProjectScopedReader's lint word list
 *     (the lint refuses them in builder SQL: builders go through the CTE alias only);
 *  4. the drift check (ScopedViewDriftCheck) and the opt-in drift test compare table, view and column list.
 *
 * The events relation is ALWAYS part of a query (the reader has always prepended it and existing builder SQL must
 * stay byte-identical). Every other relation is prepended only when the builder SQL names its alias, so a query
 * that does not use it sends exactly what it sent before the relation existed.
 */
final class ScopedRelations
{
    public const PERSON_DISTINCT_IDS_ALIAS = 'project_person_distinct_ids';

    public const PERSON_DISTINCT_IDS_VIEW = 'project_person_distinct_ids_v';

    public const PERSON_DISTINCT_IDS_TABLE = 'person_distinct_ids';

    /** The Kafka engine table that feeds person_distinct_ids; never readable, named here so the lint refuses it. */
    public const KAFKA_PERSON_DISTINCT_IDS_TABLE = 'kafka_person_distinct_ids';

    /**
     * Columns of project_person_distinct_ids_v (004_person_distinct_ids.sql): the DEDUPLICATED mapping, one row per
     * (project_id, distinct_id) holding the newest version. `is_deleted` of the table is not exposed (a deleted
     * mapping is simply absent from the view).
     *
     * @var list<string>
     */
    public const PERSON_DISTINCT_IDS_COLUMNS = ['project_id', 'distinct_id', 'person_id', 'version', 'created_at'];

    /** @var list<string> */
    public const PERSON_DISTINCT_IDS_HIDDEN_COLUMNS = ['is_deleted'];

    public static function events(): ScopedRelation
    {
        return new ScopedRelation(
            ProjectScopedReader::CTE_NAME,
            ProjectScopedReader::VIEW,
            'events',
            ProjectScopedReader::COLUMNS,
            [],
            'ProjectScopedReader::COLUMNS',
        );
    }

    public static function personDistinctIds(): ScopedRelation
    {
        return new ScopedRelation(
            self::PERSON_DISTINCT_IDS_ALIAS,
            self::PERSON_DISTINCT_IDS_VIEW,
            self::PERSON_DISTINCT_IDS_TABLE,
            self::PERSON_DISTINCT_IDS_COLUMNS,
            self::PERSON_DISTINCT_IDS_HIDDEN_COLUMNS,
            'ScopedRelations::PERSON_DISTINCT_IDS_COLUMNS',
        );
    }

    /** @return list<ScopedRelation> the events relation first */
    public static function all(): array
    {
        return [self::events(), self::personDistinctIds()];
    }

    /**
     * The `WITH ... ` prefix for this builder SQL: the events CTE, then the CTE of every other relation the SQL names.
     * For SQL that names only `project_events` it is exactly {@see ProjectScopedReader::cte()}.
     */
    public static function prefixFor(string $sql): string
    {
        $definitions = [self::events()->cteDefinition()];
        foreach (array_slice(self::all(), 1) as $relation) {
            if ($relation->isReferencedIn($sql)) {
                $definitions[] = $relation->cteDefinition();
            }
        }

        return 'WITH '.implode(', ', $definitions).' ';
    }

    private function __construct() {}
}
