<?php

use App\Infrastructure\ClickHouse\ClickHouseMigrator;
use App\Infrastructure\ClickHouse\IngestStatusQueries;
use App\Infrastructure\ClickHouse\PersonDistinctIdProjectIds;
use App\Infrastructure\ClickHouse\RoSettings;
use App\Infrastructure\ClickHouse\ScopedRelations;
use App\Infrastructure\ClickHouse\ScopedViewDriftCheck;
use App\Infrastructure\Kafka\Identity\PersonDistinctIdRow;
use App\Infrastructure\Kafka\Identity\RdKafkaRowWriter;
use App\Jobs\PurgeProjectPersonDistinctIds;
use Tests\Support\ClickHouseIntegration as CH;

/*
 * The distinct id -> person mapping against a real ClickHouse 25.8 and the real Kafka topic: migration 004, the Kafka
 * engine table and its materialized views, the deduplicating DEDICATED scoped view, the tenant isolation of that view
 * for posthog_ro, and the purge of a deleted project's rows. Opt-in (group "clickhouse", see
 * ClickHouseTenantIsolationTest for how to run it). Uses its own fixed project ids (CH::PERSON_*) and deletes
 * exactly those rows afterwards, also after a failed test.
 *
 * "Unmerged" matters here: a ReplacingMergeTree collapses rows of one key only in a background merge, so the view
 * is tried while merges are STOPPED (SYSTEM STOP MERGES, always started again) and again after OPTIMIZE ... FINAL;
 * both must give the same answer.
 */

pest()->group('clickhouse');

/** @return list<int> */
function intList(array $values): array
{
    return array_map('intval', $values);
}

/** Person uuids that are easy to read in a failure. */
function pid(int $n): string
{
    return sprintf('00000000-0000-4000-8000-%012d', $n);
}

/** Rows of the reader's view for a project as [distinct_id, person_id, version], by distinct id. */
function mapping(CH $ch, int $project): array
{
    return array_map(
        fn (array $r) => [$r['distinct_id'], $r['person_id'], (int) $r['version']],
        $ch->reader->select($project, 'SELECT distinct_id, toString(person_id) AS person_id, version FROM project_person_distinct_ids ORDER BY distinct_id'),
    );
}

beforeEach(function () {
    $this->ch = CH::connect();
    $this->ch->deletePersonRowsOf(CH::PERSON_IDS);
});

afterEach(function () {
    // never leave merges stopped, whatever the test did
    $this->ch->admin->execute('SYSTEM START MERGES posthog.person_distinct_ids');
});

afterAll(function () {
    CH::cleanupPersonAfterAll();
});

// --- migration 004 --------------------------------------------------------------------------------------------------

it('has applied migration 004: it is recorded with the checksum of the file and the migrator finds nothing pending', function () {
    $file = base_path('database/clickhouse/migrations/004_person_distinct_ids.sql');
    $recorded = $this->ch->admin->select('SELECT checksum FROM posthog.schema_migrations WHERE version = {v:String}', ['v' => '004_person_distinct_ids.sql']);

    $migrator = new ClickHouseMigrator($this->ch->admin, base_path('database/clickhouse/migrations'), 5, ['SQL_project_id' => 0]);

    expect($recorded)->toHaveCount(1)
        ->and($recorded[0]['checksum'])->toBe(hash('sha256', (string) file_get_contents($file)))
        ->and($migrator->migrate())->toBe([]);
});

it('is idempotent: running every statement of migration 004 again changes nothing and fails nowhere', function () {
    $before = $this->ch->admin->select("SELECT name, engine, create_table_query FROM system.tables WHERE database = 'posthog' AND name IN ('kafka_person_distinct_ids', 'person_distinct_ids', 'person_distinct_ids_mv', 'person_distinct_ids_errors_mv', 'project_person_distinct_ids_v') ORDER BY name");

    foreach (ClickHouseMigrator::statements((string) file_get_contents(base_path('database/clickhouse/migrations/004_person_distinct_ids.sql'))) as $statement) {
        $this->ch->admin->execute($statement, settings: ['SQL_project_id' => 0]);
    }

    $after = $this->ch->admin->select("SELECT name, engine, create_table_query FROM system.tables WHERE database = 'posthog' AND name IN ('kafka_person_distinct_ids', 'person_distinct_ids', 'person_distinct_ids_mv', 'person_distinct_ids_errors_mv', 'project_person_distinct_ids_v') ORDER BY name");

    expect($before)->toHaveCount(5)->and($after)->toBe($before);
});

it('created the table as a ReplacingMergeTree(version) ordered by project and distinct id, and the Kafka table on its own group', function () {
    $tables = array_column($this->ch->admin->select("SELECT name, engine, engine_full, sorting_key FROM system.tables WHERE database = 'posthog' AND name IN ('kafka_person_distinct_ids', 'person_distinct_ids', 'person_distinct_ids_mv', 'person_distinct_ids_errors_mv')"), null, 'name');
    $columns = $this->ch->admin->select("SELECT name, type FROM system.columns WHERE database = 'posthog' AND table = 'person_distinct_ids' ORDER BY position");

    expect($tables['person_distinct_ids']['engine'])->toBe('ReplacingMergeTree')
        ->and($tables['person_distinct_ids']['engine_full'])->toContain('ReplacingMergeTree(version)')
        ->and($tables['person_distinct_ids']['sorting_key'])->toBe('project_id, distinct_id')
        ->and($tables['kafka_person_distinct_ids']['engine'])->toBe('Kafka')
        ->and($tables['kafka_person_distinct_ids']['engine_full'])->toContain("kafka_topic_list = 'person_distinct_ids'")->toContain("kafka_group_name = 'clickhouse_person_distinct_ids'")->toContain("kafka_handle_error_mode = 'stream'")
        ->and($tables['person_distinct_ids_mv']['engine'])->toBe('MaterializedView')
        ->and($tables['person_distinct_ids_errors_mv']['engine'])->toBe('MaterializedView')
        ->and(array_column($columns, 'type', 'name'))->toBe([
            'project_id' => 'UInt32', 'distinct_id' => 'String', 'person_id' => 'UUID', 'version' => 'UInt64', 'is_deleted' => 'UInt8', 'created_at' => "DateTime64(3, 'UTC')",
        ]);
});

it('keeps the registry column list, the table and the scoped view in agreement (drift guard)', function () {
    $relation = ScopedRelations::personDistinctIds();
    $table = array_column($this->ch->admin->select('DESCRIBE TABLE posthog.person_distinct_ids'), 'name');
    $view = array_column($this->ch->admin->select('DESCRIBE TABLE posthog.'.$relation->view), 'name');

    expect($view)->toBe(ScopedRelations::PERSON_DISTINCT_IDS_COLUMNS)
        ->and($table)->toEqualCanonicalizing($relation->tableColumns())
        // the table's columns are the view's plus exactly the ones hidden on purpose
        ->and(array_values(array_diff($table, $view)))->toBe(ScopedRelations::PERSON_DISTINCT_IDS_HIDDEN_COLUMNS)
        ->and((new ScopedViewDriftCheck($this->ch->admin))->problems())->toBe([]);
});

it('created the view as a DEFINER view that filters on SQL_project_id and has its own row policy', function () {
    $view = $this->ch->admin->select('SELECT create_table_query AS q FROM system.tables WHERE database = {db:String} AND name = {v:String}', ['db' => 'posthog', 'v' => ScopedRelations::PERSON_DISTINCT_IDS_VIEW]);
    $policies = $this->ch->admin->select("SELECT select_filter FROM system.row_policies WHERE database = 'posthog' AND table = {v:String}", ['v' => ScopedRelations::PERSON_DISTINCT_IDS_VIEW]);

    expect($view)->toHaveCount(1)
        ->and($view[0]['q'])->toContain('SQL SECURITY DEFINER')->toContain('DEFINER = ')->toContain("project_id = toUInt32(getSetting('SQL_project_id'))")
        ->and($policies)->not->toBeEmpty()
        ->and($policies[0]['select_filter'])->toContain('SQL_project_id');
});

// --- the deduplicating view ----------------------------------------------------------------------------------------

it('shows the newest version of every id, whatever order the rows arrived in, hides tombstones, and agrees before and after a merge', function () {
    $a = CH::PERSON_A;
    $t = '2026-03-10 10:00:00.123';
    $this->ch->admin->execute('SYSTEM STOP MERGES posthog.person_distinct_ids');

    $this->ch->insertPersonRows([
        [$a, 'a', pid(1), 0, 0, $t],  // a: first linked to person 1 ...
        [$a, 'a', pid(2), 1, 0, $t],  // ... then moved by a merge to person 2 (version 1)
        [$a, 'a', pid(2), 1, 0, $t],  // the same row again: a replay of the topic
        [$a, 'b', pid(5), 2, 0, $t],  // b: version 2 arrives FIRST ...
        [$a, 'b', pid(4), 1, 0, $t],  // ... the older version 1 LATER (out of order)
        [$a, 'c', pid(1), 0, 0, $t],  // c: live, then a newest tombstone
        [$a, 'c', pid(1), 1, 1, $t],
        [$a, 'd', pid(1), 0, 0, $t],  // d: live, tombstone, live again (version 2 wins)
        [$a, 'd', pid(1), 1, 1, $t],
        [$a, 'd', pid(3), 2, 0, $t],
        [$a, 'e', pid(1), 0, 1, $t],  // e: only a tombstone
    ], separateParts: true);

    // Non vacuous: every row is physically there, in several parts (nothing was merged).
    $parts = (int) $this->ch->admin->select("SELECT count() AS n FROM system.parts WHERE database = 'posthog' AND table = 'person_distinct_ids' AND active AND partition_id = 'all'")[0]['n'];
    expect($this->ch->personRowCount([$a]))->toBe(11)->and($parts)->toBeGreaterThanOrEqual(11);

    $expected = [['a', pid(2), 1], ['b', pid(5), 2], ['d', pid(3), 2]];
    expect(mapping($this->ch, $a))->toBe($expected);

    // The same answer through filters on the person: an old person never shows up, a tombstoned id never matches.
    $byPerson = fn (int $n) => $this->ch->reader->select($a, 'SELECT distinct_id FROM project_person_distinct_ids WHERE person_id = {p:UUID} ORDER BY distinct_id', ['p' => pid($n)]);
    expect(array_column($byPerson(1), 'distinct_id'))->toBe([])
        ->and(array_column($byPerson(2), 'distinct_id'))->toBe(['a'])
        ->and(array_column($byPerson(4), 'distinct_id'))->toBe([])
        ->and(array_column($byPerson(5), 'distinct_id'))->toBe(['b']);
    $count = $this->ch->reader->select($a, 'SELECT count() AS n, uniqExact(person_id) AS p, max(version) AS v FROM project_person_distinct_ids')[0];
    expect([(int) $count['n'], (int) $count['p'], (int) $count['v']])->toBe([3, 3, 2]);

    // After the merge the physical table is smaller and the view says exactly the same.
    $this->ch->admin->execute('SYSTEM START MERGES posthog.person_distinct_ids');
    $this->ch->admin->execute('OPTIMIZE TABLE posthog.person_distinct_ids FINAL', timeout: 60);
    expect($this->ch->personRowCount([$a]))->toBeLessThan(11)
        ->and(mapping($this->ch, $a))->toBe($expected);
});

it('gives the same answer for a replay of the whole history as for the history once', function () {
    $a = CH::PERSON_A;
    $t = '2026-03-10 10:00:00.000';
    $history = [[$a, 'x', pid(1), 0, 0, $t], [$a, 'x', pid(2), 1, 0, $t], [$a, 'y', pid(2), 0, 0, $t]];

    $this->ch->insertPersonRows($history, separateParts: true);
    $once = mapping($this->ch, $a);
    $this->ch->insertPersonRows($history, separateParts: true);

    expect($once)->toBe([['x', pid(2), 1], ['y', pid(2), 0]])
        ->and(mapping($this->ch, $a))->toBe($once);
});

it('exposes created_at of the newest row with its milliseconds', function () {
    $this->ch->insertPersonRows([
        [CH::PERSON_A, 'ms', pid(1), 0, 0, '2026-10-29 12:00:00.123'],
        [CH::PERSON_A, 'ms', pid(2), 1, 0, '2026-10-29 12:05:00.456'],
    ], separateParts: true);

    $row = $this->ch->reader->select(CH::PERSON_A, 'SELECT toString(created_at) AS c, version FROM project_person_distinct_ids WHERE distinct_id = {d:String}', ['d' => 'ms'])[0];

    expect($row['c'])->toBe('2026-10-29 12:05:00.456')->and((int) $row['version'])->toBe(1);
});

// --- tenant isolation ------------------------------------------------------------------------------------------------

function seedTwoTenants(CH $ch): void
{
    $t = '2026-03-10 10:00:00.000';
    $ch->insertPersonRows([
        [CH::PERSON_A, 'u1', pid(10), 0, 0, $t], [CH::PERSON_A, 'u2', pid(10), 0, 0, $t], [CH::PERSON_A, 'only-a', pid(11), 0, 0, $t],
        [CH::PERSON_B, 'u1', pid(20), 0, 0, $t], [CH::PERSON_B, 'only-b', pid(21), 0, 0, $t],
    ], separateParts: true);
}

it('shows each project only its own mapping through the scoped reader, and a project without rows nothing', function () {
    seedTwoTenants($this->ch);

    expect(mapping($this->ch, CH::PERSON_A))->toBe([['only-a', pid(11), 0], ['u1', pid(10), 0], ['u2', pid(10), 0]])
        ->and(mapping($this->ch, CH::PERSON_B))->toBe([['only-b', pid(21), 0], ['u1', pid(20), 0]])
        ->and(mapping($this->ch, CH::PERSON_C))->toBe([]);

    $ids = $this->ch->reader->select(CH::PERSON_B, 'SELECT groupUniqArray(project_id) AS ids FROM project_person_distinct_ids')[0]['ids'];
    expect(intList($ids))->toBe([CH::PERSON_B]);
});

it('keeps UNION, subquery and self join shapes of the mapping inside the project', function () {
    seedTwoTenants($this->ch);

    $union = $this->ch->reader->select(CH::PERSON_A, 'SELECT project_id, count() AS n FROM (SELECT project_id FROM project_person_distinct_ids UNION ALL SELECT project_id FROM project_person_distinct_ids) GROUP BY project_id');
    $subquery = $this->ch->reader->select(CH::PERSON_B, 'SELECT uniqExact(project_id) AS p, count() AS n FROM project_person_distinct_ids WHERE distinct_id IN (SELECT distinct_id FROM project_person_distinct_ids)');
    $join = $this->ch->reader->select(CH::PERSON_A, 'SELECT a.project_id AS project_id, count() AS n FROM project_person_distinct_ids AS a INNER JOIN project_person_distinct_ids AS b ON a.distinct_id = b.distinct_id GROUP BY a.project_id');

    expect(intList([$union[0]['project_id'], $union[0]['n']]))->toBe([CH::PERSON_A, 6])
        ->and(intList([$subquery[0]['p'], $subquery[0]['n']]))->toBe([1, 2])
        ->and(intList([$join[0]['project_id'], $join[0]['n']]))->toBe([CH::PERSON_A, 3]);
});

it('joins events to the mapping inside one project only: the same distinct id means another person in another project', function () {
    seedTwoTenants($this->ch);
    foreach ([CH::PERSON_A, CH::PERSON_B] as $project) {
        $this->ch->admin->execute(
            'INSERT INTO posthog.events (uuid, project_id, event, distinct_id, properties, timestamp) SELECT generateUUIDv4(), {p:UInt32}, \'e\', \'u1\', \'{}\', now64(3)',
            ['p' => $project],
        );
    }

    $a = $this->ch->reader->select(CH::PERSON_A, 'SELECT toString(p.person_id) AS person, count() AS n FROM project_events AS e INNER JOIN project_person_distinct_ids AS p ON e.distinct_id = p.distinct_id GROUP BY person');
    $b = $this->ch->reader->select(CH::PERSON_B, 'SELECT toString(p.person_id) AS person, count() AS n FROM project_events AS e INNER JOIN project_person_distinct_ids AS p ON e.distinct_id = p.distinct_id GROUP BY person');

    expect(array_column($a, 'person'))->toBe([pid(10)])->and(array_column($b, 'person'))->toBe([pid(20)])
        ->and(intList(array_column($a, 'n')))->toBe([1])->and(intList(array_column($b, 'n')))->toBe([1]);
});

it('shows a crafted raw query over the view nothing of another project (WHERE, UNION, subquery, JOIN)', function () {
    seedTwoTenants($this->ch);
    $ro = fn (string $sql) => $this->ch->ro->select($sql, settings: [RoSettings::SQL_PROJECT_ID => CH::PERSON_A]);
    $view = 'posthog.'.ScopedRelations::PERSON_DISTINCT_IDS_VIEW;

    expect((int) $ro("SELECT count() AS n FROM {$view} WHERE project_id = ".CH::PERSON_B)[0]['n'])->toBe(0)
        ->and((int) $ro("SELECT count() AS n FROM {$view} WHERE project_id != ".CH::PERSON_A)[0]['n'])->toBe(0)
        ->and((int) $ro("SELECT count() AS n FROM {$view} WHERE project_id = ".CH::PERSON_A.' OR 1 = 1')[0]['n'])->toBe(3)
        ->and((int) $ro("SELECT count() AS n FROM {$view} WHERE distinct_id = 'only-b'")[0]['n'])->toBe(0)
        ->and((int) $ro("SELECT count() AS n FROM (SELECT project_id FROM {$view} UNION ALL SELECT project_id FROM {$view}) WHERE project_id = ".CH::PERSON_B)[0]['n'])->toBe(0)
        ->and((int) $ro("SELECT count() AS n FROM {$view} WHERE distinct_id IN (SELECT distinct_id FROM {$view} WHERE project_id = ".CH::PERSON_B.')')[0]['n'])->toBe(0)
        ->and((int) $ro("SELECT count() AS n FROM {$view} AS a LEFT JOIN {$view} AS b ON a.distinct_id = b.distinct_id AND b.project_id = ".CH::PERSON_B)[0]['n'])->toBe(3);
});

it('fails closed: a direct read of the person view WITHOUT SQL_project_id is rejected by the server', function () {
    expect(fn () => $this->ch->ro->select('SELECT count() AS n FROM posthog.project_person_distinct_ids_v'))
        ->toThrow(RuntimeException::class, 'UNKNOWN_SETTING');
    expect(fn () => $this->ch->ro->select('SELECT project_id, count() AS n FROM posthog.project_person_distinct_ids_v GROUP BY project_id'))
        ->toThrow(RuntimeException::class, 'SQL_project_id');
});

it('denies posthog_ro the base table, the Kafka table and every table function road to them', function () {
    seedTwoTenants($this->ch);
    $id = [RoSettings::SQL_PROJECT_ID => CH::PERSON_A];

    foreach ([
        'SELECT count() FROM posthog.person_distinct_ids',
        'SELECT count() FROM person_distinct_ids',
        'SELECT count() FROM posthog.kafka_person_distinct_ids',
        'SELECT count() FROM kafka_person_distinct_ids',
        'SELECT count() FROM posthog.person_distinct_ids_mv',
        "SELECT DISTINCT project_id FROM mergeTreeIndex('posthog', 'person_distinct_ids')",
        "SELECT DISTINCT project_id FROM mergeTreeIndex(currentDatabase(), concat('person_distinct', '_ids'))",
        'SELECT count() FROM view(SELECT * FROM posthog.person_distinct_ids)',
        'SELECT count() FROM (SELECT project_id FROM posthog.person_distinct_ids UNION ALL SELECT project_id FROM posthog.person_distinct_ids)',
    ] as $sql) {
        expect(fn () => $this->ch->ro->select($sql, settings: $id))->toThrow(RuntimeException::class, 'ACCESS_DENIED');
    }

    expect(fn () => $this->ch->ro->select("SELECT count() FROM merge('posthog', '^person_distinct_ids\$')", settings: $id))->toThrow(RuntimeException::class, 'CANNOT_EXTRACT_TABLE_STRUCTURE')
        ->and(fn () => $this->ch->ro->select("SELECT * FROM mergeTreeIndex('posthog', 'project_person_distinct_ids_v')", settings: $id))->toThrow(RuntimeException::class);

    // ... and the admin really can read all of them: the denial is the grant, not an empty table.
    $leak = $this->ch->admin->select("SELECT DISTINCT project_id FROM mergeTreeIndex('posthog', 'person_distinct_ids') ORDER BY project_id");
    expect(intList(array_column($leak, 'project_id')))->toContain(CH::PERSON_A, CH::PERSON_B);
});

it('grants posthog_ro exactly one SELECT on each of the two scoped views and nothing else', function () {
    $grants = $this->ch->admin->select('SELECT access_type, database, table FROM system.grants WHERE user_name = {u:String} ORDER BY table', ['u' => 'posthog_ro']);

    expect($grants)->toBe([
        ['access_type' => 'SELECT', 'database' => 'posthog', 'table' => 'project_events_v'],
        ['access_type' => 'SELECT', 'database' => 'posthog', 'table' => 'project_person_distinct_ids_v'],
    ]);
});

it('KNOWN LIMITATION: a query level SETTINGS SQL_project_id overrides the person view filter too, and only the reader lint closes it', function () {
    seedTwoTenants($this->ch);

    // The URL argument says project A, the SQL itself says B: the query-level setting wins on the RAW client.
    $rows = $this->ch->ro->select(
        'SELECT project_id, count() AS n FROM posthog.project_person_distinct_ids_v GROUP BY project_id SETTINGS SQL_project_id = '.CH::PERSON_B,
        settings: [RoSettings::SQL_PROJECT_ID => CH::PERSON_A],
    );
    expect(intList([$rows[0]['project_id'], $rows[0]['n']]))->toBe([CH::PERSON_B, 2]);

    // The scoped reader refuses every shape of it, before anything is sent.
    foreach ([
        'SELECT count() FROM project_person_distinct_ids SETTINGS SQL_project_id = '.CH::PERSON_B,
        'SELECT count() FROM project_person_distinct_ids settings SQL_project_id = '.CH::PERSON_B.', max_threads = 1',
        'SELECT count() FROM project_events AS e INNER JOIN project_person_distinct_ids AS p ON e.distinct_id = p.distinct_id SETTINGS SQL_project_id = '.CH::PERSON_B,
        'SELECT count() FROM project_person_distinct_ids_v',
        'SELECT count() FROM person_distinct_ids',
        'SELECT count() FROM kafka_person_distinct_ids',
        "SELECT count() FROM mergeTreeIndex(currentDatabase(), concat('person_', 'distinct_ids'))",
    ] as $sql) {
        expect(fn () => $this->ch->reader->select(CH::PERSON_A, $sql))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => $this->ch->reader->select(CH::PERSON_A, 'SELECT count() FROM project_person_distinct_ids', [], [RoSettings::SQL_PROJECT_ID => CH::PERSON_B]))->toThrow(InvalidArgumentException::class);
});

it('prunes the primary key of the base table through the view, also with an outer distinct_id condition', function () {
    seedTwoTenants($this->ch);

    $plan = $this->ch->ro->select(
        "EXPLAIN indexes = 1 SELECT person_id FROM posthog.project_person_distinct_ids_v WHERE distinct_id = 'u1'",
        settings: [RoSettings::SQL_PROJECT_ID => CH::PERSON_A],
    );
    $text = implode("\n", array_column($plan, 'explain'));

    expect($text)->toContain('project_id in ['.CH::PERSON_A.', '.CH::PERSON_A.']')->toContain("distinct_id in ['u1', 'u1']");
});

// --- ingestion from the real Kafka topic ---------------------------------------------------------------------------------

/** Waits (at most 90 s) until $check() is true: the Kafka engine flushes about every 8 s. */
function eventually(Closure $check, string $what): void
{
    $deadline = microtime(true) + 90;
    while (! $check()) {
        if (microtime(true) > $deadline) {
            test()->fail("timed out after 90 s waiting for {$what}");
        }
        usleep(500_000);
    }
}

it('ingests the topic into the table with the materialized view: out of order, replayed, tombstoned and rejected messages', function () {
    $project = CH::PERSON_KAFKA;
    $row = fn (string $id, string $person, int $version, int $deleted = 0, string $createdAt = '2026-10-29 12:00:00.123', ?int $projectId = null) => PersonDistinctIdRow::message([
        'project_id' => $projectId ?? $project, 'distinct_id' => $id, 'person_id' => $person, 'version' => $version, 'is_deleted' => $deleted, 'created_at' => $createdAt,
    ]);

    // The app's own producer path (what identity:consume uses): idempotent, waits for the delivery reports.
    $writer = new RdKafkaRowWriter((string) (getenv('IDENTITY_E2E_KAFKA') ?: 'kafka:9092'));
    $writer->publish('person_distinct_ids', [
        $row('k1', pid(2), 1, createdAt: '2026-10-29 12:05:00.456'),   // version 1 first ...
        $row('k1', pid(1), 0),                                          // ... version 0 after it (same key, same partition, wrong order)
        $row('k1', pid(2), 1, createdAt: '2026-10-29 12:05:00.456'),   // a replay of version 1
        $row('k2', pid(3), 0),
        $row('k3', pid(3), 0),
        $row('k3', pid(3), 1, deleted: 1),                              // tombstone
    ]);
    // Messages the table must NOT take: malformed JSON, the nil person uuid, project 0, an empty distinct id, an invalid is_deleted.
    $writer->publish('person_distinct_ids', [
        ['key' => 'bad-json', 'payload' => '{"project_id": 991004, "distinct_id": "x", "marker": "ch-person-test-json" '],
        ['key' => 'bad-nil', 'payload' => $row('nil', '00000000-0000-0000-0000-000000000000', 0)['payload']],
        ['key' => 'bad-project', 'payload' => $row('ch-person-test-p0', pid(9), 0, projectId: 0)['payload']],
        ['key' => 'bad-empty', 'payload' => $row('', pid(9), 0)['payload']],
        ['key' => 'bad-deleted', 'payload' => $row('d2', pid(9), 0, deleted: 2)['payload']],
    ]);

    // The valid ones arrive: k1, k2 and k3 have a row each at least (rows of one key in one Kafka block collapse on
    // insert, so the physical count is 3 to 6), and the scoped view shows k1 and k2 (k3 is tombstoned).
    eventually(fn () => $this->ch->personRowCount([$project]) >= 3, 'the valid messages in person_distinct_ids');
    eventually(fn () => count(mapping($this->ch, $project)) === 2, 'the scoped view to show k1 and k2');

    expect(mapping($this->ch, $project))->toBe([['k1', pid(2), 1], ['k2', pid(3), 0]]);
    $k1 = $this->ch->reader->select($project, 'SELECT toString(created_at) AS c FROM project_person_distinct_ids WHERE distinct_id = {d:String}', ['d' => 'k1'])[0]['c'];
    expect($k1)->toBe('2026-10-29 12:05:00.456');

    // The rejected ones land in events_errors (tagged with the topic) and nowhere else.
    eventually(fn () => (int) $this->ch->admin->select("SELECT count() AS n FROM posthog.events_errors WHERE topic = 'person_distinct_ids' AND (raw LIKE '%ch-person-test-%' OR raw LIKE '%\"project_id\":991004,%')")[0]['n'] >= 5, 'the five rejected messages in events_errors');
    $errors = $this->ch->admin->select("SELECT error, raw FROM posthog.events_errors WHERE topic = 'person_distinct_ids' AND (raw LIKE '%ch-person-test-%' OR raw LIKE '%\"project_id\":991004,%')");
    expect(count($errors))->toBe(5)
        ->and(implode("\n", array_column($errors, 'error')))->toContain('rejected: invalid project_id/distinct_id/person_id/is_deleted');

    $rejectedIds = $this->ch->admin->select("SELECT count() AS n FROM posthog.person_distinct_ids WHERE project_id = {p:UInt32} AND distinct_id IN ('nil', 'ch-person-test-p0', '', 'd2', 'x')", ['p' => $project]);
    expect((int) $rejectedIds[0]['n'])->toBe(0)
        ->and((int) $this->ch->admin->select('SELECT count() AS n FROM posthog.person_distinct_ids WHERE project_id = 0')[0]['n'])->toBe(0);
});

it('leaves capture:status queries working: the events consumer is still the one it reports', function () {
    $facts = (new IngestStatusQueries($this->ch->admin))->fetch(60);

    expect($facts['consumers'])->toBe(1)
        ->and($facts['errors_last_hour'])->toBeInt()
        ->and($facts['events_total'])->toBeInt();
});

// --- the purge of a deleted project ---------------------------------------------------------------------------------------

it('purges every mapping row of a deleted project, leaves the other project alone, and the sweeper can see the ids', function () {
    $t = '2026-03-10 10:00:00.000';
    $this->ch->insertPersonRows([
        [CH::PERSON_PURGE_A, 'p1', pid(30), 0, 0, $t], [CH::PERSON_PURGE_A, 'p1', pid(31), 1, 0, $t], [CH::PERSON_PURGE_A, 'p2', pid(30), 0, 1, $t],
        [CH::PERSON_PURGE_B, 'p1', pid(40), 0, 0, $t], [CH::PERSON_PURGE_B, 'p2', pid(40), 0, 0, $t],
    ], separateParts: true);
    expect($this->ch->personRowCount([CH::PERSON_PURGE_A]))->toBe(3)->and($this->ch->personRowCount([CH::PERSON_PURGE_B]))->toBe(2);

    // The orphan sweeper's reader sees both ids (neither project exists in Postgres).
    expect((new PersonDistinctIdProjectIds($this->ch->admin))->all())->toContain(CH::PERSON_PURGE_A, CH::PERSON_PURGE_B);

    $runs = 0;
    do {
        (new PurgeProjectPersonDistinctIds(CH::PERSON_PURGE_A, 1))->withFakeQueueInteractions()->handle($this->ch->admin);
        $runs++;
    } while ($this->ch->personRowCount([CH::PERSON_PURGE_A]) > 0 && $runs < 5);

    expect($this->ch->personRowCount([CH::PERSON_PURGE_A]))->toBe(0)
        ->and($this->ch->personRowCount([CH::PERSON_PURGE_B]))->toBe(2)
        ->and(mapping($this->ch, CH::PERSON_PURGE_B))->toBe([['p1', pid(40), 0], ['p2', pid(40), 0]]);

    // A second pass over nothing succeeds (idempotent); a late row is removed by it.
    (new PurgeProjectPersonDistinctIds(CH::PERSON_PURGE_A, 2))->withFakeQueueInteractions()->handle($this->ch->admin);
    $this->ch->insertPersonRows([[CH::PERSON_PURGE_A, 'late', pid(32), 0, 0, $t]]);
    $runs = 0;
    do {
        (new PurgeProjectPersonDistinctIds(CH::PERSON_PURGE_A, 2))->withFakeQueueInteractions()->handle($this->ch->admin);
        $runs++;
    } while ($this->ch->personRowCount([CH::PERSON_PURGE_A]) > 0 && $runs < 5);

    expect($this->ch->personRowCount([CH::PERSON_PURGE_A]))->toBe(0)->and($this->ch->personRowCount([CH::PERSON_PURGE_B]))->toBe(2);
});
