-- Materialized columns for the properties the Trends breakdown (and filters) use most, plus the scoped view
-- that exposes them. Written for task 3.8 (enrichment): `$os` and `$device_type` are filled by the capture
-- Enricher from the User-Agent, `utm_*` are sent by posthog-js as plain event properties WITHOUT a `$`
-- prefix (verified in posthog-js 1.438: campaign parameters utm_source, utm_medium, utm_campaign, ...).
-- `browser` and `country_code` already exist since 001_events.sql.
--
-- Same pattern as 001: JSONExtractString(properties, '<name>'). A missing, null or non-string value is the
-- empty string, which the Trends query turns into its "None" bucket. LowCardinality(String) because these
-- have few distinct values (an attacker can still send many distinct utm values; LowCardinality degrades
-- gracefully above ~10k, it does not fail).
--
-- Each ADD COLUMN is metadata only (no data is rewritten, new parts compute the value at insert time).
-- Existing parts do not have the column on disk until MATERIALIZE COLUMN rewrites it: ONE mutation per column
-- (one statement each), which rewrites only that column's files of every part, not the whole table. It runs
-- asynchronously in the background (the migrator does not wait: a huge table must not hold up the start of
-- the app or hit the HTTP timeout). Until a part is materialized ClickHouse evaluates the expression while
-- reading it, so every query is already correct and only a little slower. Progress:
--   SELECT command, parts_to_do, is_done FROM system.mutations WHERE table = 'events' AND NOT is_done
-- Cost to expect: reading `properties` (ZSTD) once per part per column. Re-running this file (after a crash
-- half way) is safe: ADD COLUMN IF NOT EXISTS, and MATERIALIZE COLUMN is idempotent.
ALTER TABLE events ADD COLUMN IF NOT EXISTS os LowCardinality(String) MATERIALIZED JSONExtractString(properties, '$os');
ALTER TABLE events ADD COLUMN IF NOT EXISTS device_type LowCardinality(String) MATERIALIZED JSONExtractString(properties, '$device_type');
ALTER TABLE events ADD COLUMN IF NOT EXISTS utm_source LowCardinality(String) MATERIALIZED JSONExtractString(properties, 'utm_source');
ALTER TABLE events ADD COLUMN IF NOT EXISTS utm_medium LowCardinality(String) MATERIALIZED JSONExtractString(properties, 'utm_medium');
ALTER TABLE events ADD COLUMN IF NOT EXISTS utm_campaign LowCardinality(String) MATERIALIZED JSONExtractString(properties, 'utm_campaign');

ALTER TABLE events MATERIALIZE COLUMN os;
ALTER TABLE events MATERIALIZE COLUMN device_type;
ALTER TABLE events MATERIALIZE COLUMN utm_source;
ALTER TABLE events MATERIALIZE COLUMN utm_medium;
ALTER TABLE events MATERIALIZE COLUMN utm_campaign;

-- Recreate the scoped read view with the new columns APPENDED at the end of the list (ADD COLUMN alone leaves
-- the view silently without them; see the DRIFT notes in 002_scoped_events_view.sql and
-- ScopedViewDriftCheck). Definition copied from 002 unchanged: DEFINER = CURRENT_USER SQL SECURITY DEFINER
-- and the SQL_project_id filter (this statement runs with SQL_project_id=0, see ClickHouseMigrator).
-- The read-only user's grant (`GRANT SELECT ON posthog.project_events_v`) and the row policy under
-- <databases><posthog><project_events_v> in users.d/20-readonly-user.xml are declared by NAME in XML, so
-- they apply to the replaced view too (verified in a scratch ClickHouse 25.8 and by
-- scripts/check-readonly-user.sh). The column order here MUST equal ProjectScopedReader::COLUMNS.
CREATE OR REPLACE VIEW project_events_v
DEFINER = CURRENT_USER SQL SECURITY DEFINER
AS SELECT
    uuid, project_id, event, distinct_id, properties, timestamp, created_at, current_url, browser, country_code,
    os, device_type, utm_source, utm_medium, utm_campaign
FROM events
WHERE project_id = toUInt32(getSetting('SQL_project_id'));
