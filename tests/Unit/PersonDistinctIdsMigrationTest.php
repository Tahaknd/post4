<?php

use App\Infrastructure\ClickHouse\ClickHouseMigrator;
use App\Infrastructure\ClickHouse\ScopedRelations;
use App\Infrastructure\ClickHouse\ScopedViewStatement;

/*
 * Offline guard for database/clickhouse/migrations/004_person_distinct_ids.sql (the opt-in clickhouse group applies it
 * to a real server and feeds it through the real Kafka topic). It is checksummed once applied: these tests pin its
 * shape so a later migration cannot quietly drift away from it.
 */

/** @return array<string, string> statement kind and name => whitespace collapsed statement */
function personMigrationStatements(string $file = '004_person_distinct_ids.sql'): array
{
    $statements = [];
    foreach (ClickHouseMigrator::statements((string) file_get_contents(base_path('database/clickhouse/migrations/'.$file))) as $statement) {
        $flat = trim((string) preg_replace('/\s+/', ' ', $statement));
        if (preg_match('/^CREATE (?:MATERIALIZED )?(?:TABLE|VIEW) IF NOT EXISTS (\w+)/', $flat, $m) === 1) {
            $statements[$m[1]] = $flat;
        }
    }

    return $statements;
}

it('creates the kafka table, the table, both materialized views and the scoped view, each idempotently', function () {
    $sql = (string) file_get_contents(base_path('database/clickhouse/migrations/004_person_distinct_ids.sql'));
    $statements = ClickHouseMigrator::statements($sql);

    expect($statements)->toHaveCount(5)
        ->and(array_keys(personMigrationStatements()))->toBe([
            'kafka_person_distinct_ids', 'person_distinct_ids', 'person_distinct_ids_mv', 'person_distinct_ids_errors_mv', 'project_person_distinct_ids_v',
        ]);
});

it('is a ReplacingMergeTree over version ordered by project and distinct id, with is_deleted as a plain column', function () {
    $table = personMigrationStatements()['person_distinct_ids'];

    expect($table)->toContain('ENGINE = ReplacingMergeTree(version)')
        ->and($table)->not->toContain('ReplacingMergeTree(version, is_deleted)')
        ->and($table)->toContain('ORDER BY (project_id, distinct_id)')
        ->and($table)->toContain("created_at DateTime64(3, 'UTC')")
        ->and($table)->toContain('person_id UUID')
        ->and($table)->toContain('version UInt64')
        ->and($table)->toContain('is_deleted UInt8')
        ->and($table)->toContain('project_id UInt32');
});

it('has the columns the registry expects: the exposed ones plus the deliberately hidden ones', function () {
    $table = personMigrationStatements()['person_distinct_ids'];
    preg_match('/\((.+)\) ENGINE/', $table, $m);
    $columns = array_map(fn (string $definition) => explode(' ', trim($definition))[0], explode(',', preg_replace('/\([^)]*\)/', '', $m[1])));

    expect($columns)->toEqualCanonicalizing(ScopedRelations::personDistinctIds()->tableColumns());
});

it('declares the same columns on the kafka table as on the table, in the same order', function () {
    $statements = personMigrationStatements();
    $names = function (string $statement): array {
        preg_match('/\((.+?)\) ENGINE/', $statement, $m);

        return array_map(fn (string $d) => explode(' ', trim($d))[0], explode(',', preg_replace('/\([^)]*\)/', '', $m[1])));
    };

    expect($names($statements['kafka_person_distinct_ids']))->toBe($names($statements['person_distinct_ids']));
});

it('gives the kafka table the same robustness settings as kafka_events, with its own topic and consumer group', function () {
    $settings = function (string $statement): array {
        preg_match('/SETTINGS (.+)$/', $statement, $m);
        $pairs = [];
        foreach (explode(',', $m[1]) as $pair) {
            [$key, $value] = array_map('trim', explode('=', $pair, 2));
            $pairs[$key] = $value;
        }

        return $pairs;
    };
    $events = $settings(personMigrationStatements('001_events.sql')['kafka_events']);
    $person = $settings(personMigrationStatements()['kafka_person_distinct_ids']);

    expect($person['kafka_topic_list'])->toBe("'person_distinct_ids'")
        ->and($person['kafka_group_name'])->toBe("'clickhouse_person_distinct_ids'")
        ->and($person['kafka_group_name'])->not->toBe($events['kafka_group_name'])
        ->and($person['kafka_handle_error_mode'])->toBe("'stream'")
        ->and($person['kafka_format'])->toBe("'JSONEachRow'");

    unset($events['kafka_topic_list'], $events['kafka_group_name'], $person['kafka_topic_list'], $person['kafka_group_name']);
    expect($person)->toBe($events);
});

it('splits every message between the table and events_errors with one and the same condition', function () {
    $statements = personMigrationStatements();
    preg_match('/ WHERE (length\(_error\) = 0 .+)$/', $statements['person_distinct_ids_mv'], $valid);
    preg_match('/ WHERE NOT \((length\(_error\) = 0 .+)\)$/', $statements['person_distinct_ids_errors_mv'], $rejected);

    // the errors view is exactly the negation of the condition of the valid one
    expect($valid)->toHaveCount(2)
        ->and($rejected)->toHaveCount(2)
        ->and($rejected[1])->toBe($valid[1])
        ->and($statements['person_distinct_ids_mv'])->toContain('TO person_distinct_ids AS')
        ->and($statements['person_distinct_ids_errors_mv'])->toContain('TO events_errors AS')
        ->and($statements['person_distinct_ids_errors_mv'])->toContain('_topic AS topic');
});

it('creates the scoped view as a DEFINER view with the SQL_project_id filter, deduplicated by argMax over version', function () {
    $view = personMigrationStatements()['project_person_distinct_ids_v'];

    expect($view)->toContain('CREATE VIEW IF NOT EXISTS project_person_distinct_ids_v DEFINER = CURRENT_USER SQL SECURITY DEFINER AS SELECT')
        ->and($view)->toContain("WHERE project_id = toUInt32(getSetting('SQL_project_id'))")
        ->and($view)->toContain('argMax(person_id, version)')
        ->and($view)->toContain('argMax(is_deleted, version)')
        ->and($view)->toContain('WHERE latest_is_deleted = 0')
        ->and($view)->toContain('FROM person_distinct_ids WHERE')
        ->and($view)->not->toContain(' FINAL');
});

it('prints, from the registry, exactly the view body the migration contains', function () {
    $migration = personMigrationStatements()['project_person_distinct_ids_v'];
    $printed = ScopedViewStatement::personDistinctIdsSql();
    $afterAs = fn (string $sql): string => substr($sql, strpos($sql, ' AS SELECT ') + strlen(' AS SELECT '));

    expect($printed)->toStartWith('CREATE OR REPLACE VIEW project_person_distinct_ids_v DEFINER = CURRENT_USER SQL SECURITY DEFINER AS SELECT')
        ->and(ScopedViewStatement::for('person_distinct_ids'))->toBe($printed)
        ->and(ScopedViewStatement::for('events'))->toBe(ScopedViewStatement::sql())
        ->and($afterAs($printed))->toBe($afterAs($migration))
        ->and(fn () => ScopedViewStatement::for('nope'))->toThrow(InvalidArgumentException::class);
});

it('exposes exactly the registry columns in the view select list, in order', function () {
    $migration = personMigrationStatements()['project_person_distinct_ids_v'];
    preg_match('/AS SELECT (.+?) FROM \(/', $migration, $m);
    $listed = array_map(fn (string $c) => trim(preg_replace('/^.* AS /', '', $c)), explode(',', $m[1]));

    expect($listed)->toBe(ScopedRelations::PERSON_DISTINCT_IDS_COLUMNS);
});

it('prints the person view statement with the artisan command', function () {
    $this->artisan('clickhouse:view-sql', ['relation' => 'person_distinct_ids'])
        ->expectsOutput(ScopedViewStatement::personDistinctIdsSql().';')
        ->assertExitCode(0);
    $this->artisan('clickhouse:view-sql', ['relation' => 'nope'])->assertExitCode(2);
});
