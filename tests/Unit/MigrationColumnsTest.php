<?php

use Tests\Support\MigrationColumns;

/** Folds inline migrations: name => SQL, in order. */
function foldSql(array $migrations): array
{
    return MigrationColumns::fold($migrations);
}

const FOLD_CREATE = 'CREATE TABLE IF NOT EXISTS events (uuid UUID, project_id UInt32, event LowCardinality(String), properties String CODEC(ZSTD(3)), '
    .'browser LowCardinality(String) MATERIALIZED JSONExtractString(properties, \'$browser\')) ENGINE = ReplacingMergeTree ORDER BY (project_id, uuid);';

it('folds CREATE TABLE columns in order and ignores INDEX, PROJECTION, CONSTRAINT and the engine', function () {
    $state = foldSql(['001.sql' => 'CREATE TABLE IF NOT EXISTS events (
        uuid UUID,
        `project_id` UInt32,
        "event" String DEFAULT concat(\'a\', \',\', \'b\'),
        INDEX idx_event event TYPE bloom_filter GRANULARITY 1,
        PROJECTION p (SELECT project_id ORDER BY project_id),
        CONSTRAINT c CHECK project_id > 0,
        price Decimal(10, 2)
    ) ENGINE = MergeTree ORDER BY uuid;']);

    expect($state['table'])->toBe(['uuid', 'project_id', 'event', 'price'])->and($state['columnFile'])->toBe('001.sql');
});

it('is case-insensitive and understands quoted and posthog-prefixed identifiers', function () {
    $state = foldSql([
        '001.sql' => FOLD_CREATE,
        '002.sql' => 'alter table `posthog`.`events` add column if not exists "os" String MATERIALIZED \'x\'; ALTER TABLE posthog.events Drop Column If Exists browser;',
    ]);

    expect($state['table'])->toBe(['uuid', 'project_id', 'event', 'properties', 'os']);
});

it('folds every action of a multi-action ALTER (ADD, DROP, RENAME, MODIFY)', function () {
    $state = foldSql([
        '001.sql' => FOLD_CREATE,
        '002.sql' => 'ALTER TABLE events ADD COLUMN a String DEFAULT concat(\'x\', \',y\'), ADD COLUMN IF NOT EXISTS b UInt8, DROP COLUMN browser, RENAME COLUMN event TO event_name, MODIFY COLUMN b UInt16, ADD COLUMN a String;',
    ]);

    expect($state['table'])->toBe(['uuid', 'project_id', 'event_name', 'properties', 'a', 'b'])
        ->and($state['columnFile'])->toBe('002.sql');
});

it('counts MODIFY COLUMN as a column change without changing the list', function () {
    $state = foldSql(['001.sql' => FOLD_CREATE, '002.sql' => 'ALTER TABLE events MODIFY COLUMN properties String CODEC(ZSTD(1));']);

    expect($state['table'])->toBe(['uuid', 'project_id', 'event', 'properties', 'browser'])->and($state['columnFile'])->toBe('002.sql');
});

it('lets harmless statements through without touching the columns', function (string $sql) {
    $state = foldSql(['001.sql' => FOLD_CREATE, '002.sql' => $sql]);

    expect($state['table'])->toBe(['uuid', 'project_id', 'event', 'properties', 'browser'])->and($state['columnFile'])->toBe('001.sql');
})->with([
    'materialize column' => 'ALTER TABLE events MATERIALIZE COLUMN browser;',
    'materialize and index' => 'ALTER TABLE events ADD INDEX i event TYPE set(10) GRANULARITY 1, MATERIALIZE INDEX i;',
    'projection' => 'ALTER TABLE events ADD PROJECTION p (SELECT project_id ORDER BY project_id);',
    'constraint' => 'ALTER TABLE events ADD CONSTRAINT c CHECK project_id > 0;',
    'ttl and settings' => 'ALTER TABLE events MODIFY TTL timestamp + INTERVAL 3 YEAR; ALTER TABLE events MODIFY SETTING index_granularity = 4096;',
    'update' => "ALTER TABLE events UPDATE event = 'x' WHERE event = 'y';",
    'delete where' => "ALTER TABLE events DELETE WHERE event = 'y';",
    'optimize' => 'OPTIMIZE TABLE events FINAL;',
    'insert' => 'INSERT INTO events (uuid) SELECT generateUUIDv4();',
    'delete from' => 'DELETE FROM events WHERE project_id = 1;',
    'a materialized view that reads events' => 'CREATE MATERIALIZED VIEW IF NOT EXISTS events_mv TO events AS SELECT uuid FROM kafka_events;',
    'other tables' => 'CREATE TABLE IF NOT EXISTS events_errors (x String) ENGINE = Memory; ALTER TABLE events_errors ADD COLUMN y String;',
    'a statement that is not about a relation' => 'SELECT 1;',
]);

it('keeps the last CREATE [OR REPLACE] VIEW and records its file and statement', function () {
    $state = foldSql([
        '001.sql' => FOLD_CREATE,
        '002.sql' => 'CREATE VIEW IF NOT EXISTS project_events_v DEFINER = CURRENT_USER SQL SECURITY DEFINER AS SELECT uuid, project_id FROM events WHERE project_id = 1;',
        '003.sql' => "create or replace view `posthog`.`project_events_v`\nDEFINER = CURRENT_USER SQL SECURITY DEFINER\nAS SELECT\n  uuid,\n  project_id, `event`\nFROM posthog.events WHERE project_id = 1;",
    ]);

    expect($state['view'])->toBe(['uuid', 'project_id', 'event'])
        ->and($state['viewFile'])->toBe('003.sql')
        ->and($state['viewSql'])->toStartWith('create or replace view posthog.project_events_v DEFINER');
});

it('throws on a statement about events or the scoped view that it does not understand', function (string $sql) {
    expect(fn () => foldSql(['001.sql' => FOLD_CREATE, '002.sql' => $sql]))->toThrow(RuntimeException::class);
})->with([
    'drop table' => 'DROP TABLE events;',
    'drop table if exists' => 'DROP TABLE IF EXISTS posthog.events;',
    'truncate' => 'TRUNCATE TABLE events;',
    'rename table' => 'RENAME TABLE events TO events_old;',
    'rename onto events' => 'RENAME TABLE events_new TO events;',
    'exchange' => 'EXCHANGE TABLES events AND events_new;',
    'create as' => 'CREATE TABLE events AS other_table ENGINE = MergeTree ORDER BY uuid;',
    'add column after' => 'ALTER TABLE events ADD COLUMN x String AFTER event;',
    'add column first' => 'ALTER TABLE events ADD COLUMN x String FIRST;',
    'modify column after' => 'ALTER TABLE events MODIFY COLUMN x String AFTER event;',
    'unknown action' => 'ALTER TABLE events FREEZE;',
    'unknown action inside a multi-action alter' => 'ALTER TABLE events ADD COLUMN x String, ATTACH PARTITION 202610;',
    'view that selects star' => 'CREATE OR REPLACE VIEW project_events_v AS SELECT * FROM kafka_other;',
    'view dropped' => 'DROP VIEW project_events_v;',
    'view modified' => 'ALTER TABLE project_events_v MODIFY QUERY SELECT 1;',
]);

it('folds the real migrations of the repository', function () {
    expect(MigrationColumns::files())->not->toBeEmpty()
        ->and(MigrationColumns::tableColumns())->toContain('uuid', 'browser', 'os')
        ->and(MigrationColumns::viewColumns())->toBe(MigrationColumns::tableColumns());
});
