-- The distinct id -> person mapping the identity service (identity:consume) writes to the `person_distinct_ids`
-- Kafka topic, and the scoped view the read-only user reads it through. Written for task 4.3 (Milestone 4).
-- The message shape is documented in App\Infrastructure\Kafka\Identity\PersonDistinctIdRow:
--   {"project_id":12,"distinct_id":"alice","person_id":"<persons.uuid>","version":1,"is_deleted":0,
--    "created_at":"2026-10-29 12:00:00.123"}
--
-- Same pipeline as the events table in 001: Kafka engine table (stores nothing) -> materialized view -> table, with
-- the rejected messages going to events_errors (its `topic` column tells the two sources apart). Every statement
-- is idempotent (IF NOT EXISTS), so a file that stopped half way runs again from its first statement.

-- Reads the topic. Own consumer group, so it never competes with `clickhouse_events` or the identity service's
-- own group. Parse errors arrive as _error / _raw_message (kafka_handle_error_mode = 'stream') instead of
-- wedging the consumer on one bad message. The settings are the ones of kafka_events; the block size is the
-- only thing that could be smaller here (rows are tiny) and is kept equal on purpose.
CREATE TABLE IF NOT EXISTS kafka_person_distinct_ids (
    project_id UInt32,
    distinct_id String,
    person_id UUID,
    version UInt64,
    is_deleted UInt8,
    created_at DateTime64(3, 'UTC')
) ENGINE = Kafka
SETTINGS
    kafka_broker_list = 'kafka:9092',
    kafka_topic_list = 'person_distinct_ids',
    kafka_group_name = 'clickhouse_person_distinct_ids',
    kafka_format = 'JSONEachRow',
    kafka_num_consumers = 1,
    kafka_max_block_size = 65536,
    kafka_handle_error_mode = 'stream',
    date_time_input_format = 'best_effort',
    input_format_null_as_default = 0;

-- One row per (project, distinct id, version). ReplacingMergeTree(version): rows with the same sorting key collapse
-- to the highest version in a background merge, whatever order they arrived in; the same row twice (a replay of
-- the topic) collapses to one. A merge in the identity service MOVES a distinct id to another person with
-- version + 1, so the newest row says which person holds the id NOW.
--
-- is_deleted is a PLAIN column on purpose, not the second ReplacingMergeTree argument. The engine's is_deleted
-- semantic only hides or drops a tombstone when the table is read with FINAL or cleaned up with an explicit
-- OPTIMIZE ... FINAL CLEANUP, and a merge that drops the tombstone can bring back an older row of the same key
-- that sits in another part. The scoped view below must be correct on UNMERGED data anyway (rows of one key sit
-- in several parts for a while), so it decides itself, with argMax over the version, and needs no engine support.
-- The identity service always writes 0 today (a merge moves ids, it deletes none).
--
-- Sorting key (project_id, distinct_id): the lookup of a distinct id is a primary key lookup, and project_id leads,
-- exactly as in `events`, so the view's project filter prunes. No partitioning: the table has a few small rows
-- per distinct id, not a row per event.
CREATE TABLE IF NOT EXISTS person_distinct_ids (
    project_id UInt32,
    distinct_id String,
    person_id UUID,
    version UInt64,
    is_deleted UInt8,
    created_at DateTime64(3, 'UTC')
) ENGINE = ReplacingMergeTree(version)
ORDER BY (project_id, distinct_id);

-- Valid messages flow into person_distinct_ids. The guard conditions MUST stay identical to the ones of
-- person_distinct_ids_errors_mv below (a message is in exactly one of the two).
CREATE MATERIALIZED VIEW IF NOT EXISTS person_distinct_ids_mv TO person_distinct_ids AS
SELECT project_id, distinct_id, person_id, version, is_deleted, created_at
FROM kafka_person_distinct_ids
WHERE length(_error) = 0
  AND project_id > 0 AND distinct_id != ''
  AND person_id != toUUID('00000000-0000-0000-0000-000000000000')
  AND is_deleted IN (0, 1);

-- Invalid messages flow into events_errors (14 days), tagged with their topic.
CREATE MATERIALIZED VIEW IF NOT EXISTS person_distinct_ids_errors_mv TO events_errors AS
SELECT
    _topic AS topic, _partition AS partition, _offset AS offset,
    if(length(_error) > 0, _error, 'rejected: invalid project_id/distinct_id/person_id/is_deleted') AS error,
    if(length(_error) > 0, _raw_message,
       formatRowNoNewline('JSONEachRow', project_id, distinct_id, person_id, version, is_deleted, created_at)) AS raw
FROM kafka_person_distinct_ids
WHERE NOT (length(_error) = 0
  AND project_id > 0 AND distinct_id != ''
  AND person_id != toUUID('00000000-0000-0000-0000-000000000000')
  AND is_deleted IN (0, 1));

-- The scoped read view: the read-only user (posthog_ro) reads the mapping ONLY through this view and has no
-- privilege on person_distinct_ids or kafka_person_distinct_ids. Everything said about project_events_v in
-- 002_scoped_events_view.sql applies unchanged: DEFINER = CURRENT_USER SQL SECURITY DEFINER (the inner read runs
-- with this migration's admin user, the read-only user needs no grant on the table), the filter
-- project_id = toUInt32(getSetting('SQL_project_id')) (fail closed: a read without the setting fails with
-- Code 115; the migrator runs this statement with SQL_project_id=0 so the setting is known at CREATE time), and
-- the same condition as a row policy on the view in users.d/20-readonly-user.xml.
--
-- DEDUPLICATED MAPPING, NOT THE RAW TABLE: one row per (project_id, distinct_id) = the row with the HIGHEST
-- version, and none at all when that newest row is a tombstone (is_deleted = 1). argMax over the version is
-- used instead of FINAL because it is correct by construction on unmerged data and independent of what the
-- planner does with a filter next to FINAL (tested in tests/Feature/PersonDistinctIdsClickHouseTest.php with
-- several unmerged parts, replays, out of order arrival and a newest tombstone). The cost is one aggregation
-- state per distinct id of the project (hash aggregation, bounded by the per-query memory cap of the read-only
-- profile; a failure is a clean MEMORY_LIMIT_EXCEEDED, never a wrong answer). FINAL would stream the sorted parts
-- with less memory: it is the known alternative if a project ever outgrows this (a Milestone 8 question).
-- The aggregates get their own names (latest_*) inside and are renamed outside: `max(version) AS version` would
-- shadow the column in `argMax(created_at, version)` and fail as an aggregate inside an aggregate.
--
-- EXPOSED COLUMNS (explicit list, like the events view; the order equals ScopedRelations::PERSON_DISTINCT_IDS_COLUMNS):
-- project_id, distinct_id, person_id, version, created_at. is_deleted is NOT exposed (it is always 0 here). A
-- migration that changes person_distinct_ids must recreate the view and update that list; ScopedViewDriftCheck
-- and the opt-in test compare table, view and list. Print the current statement with
-- `php artisan clickhouse:view-sql person_distinct_ids`.
CREATE VIEW IF NOT EXISTS project_person_distinct_ids_v
DEFINER = CURRENT_USER SQL SECURITY DEFINER
AS SELECT
    project_id, distinct_id, latest_person_id AS person_id, latest_version AS version, latest_created_at AS created_at
FROM (
    SELECT
        project_id, distinct_id,
        argMax(person_id, version) AS latest_person_id,
        max(version) AS latest_version,
        argMax(created_at, version) AS latest_created_at,
        argMax(is_deleted, version) AS latest_is_deleted
    FROM person_distinct_ids
    WHERE project_id = toUInt32(getSetting('SQL_project_id'))
    GROUP BY project_id, distinct_id
)
WHERE latest_is_deleted = 0;
