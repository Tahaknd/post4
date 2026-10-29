<?php

use App\Infrastructure\ClickHouse\ClickHouseMigrator;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use App\Infrastructure\ClickHouse\ScopedRelations;
use Tests\Support\MigrationColumns;

/*
 * Offline guard for docker/clickhouse/users.d/20-readonly-user.xml: the read-only user may SELECT only from
 * the DEFINER view posthog.project_events_v and from NOTHING ELSE (above all not from posthog.events, whose
 * table functions such as mergeTreeIndex skip row policies), and every relation it may select from needs its
 * own row policy that filters on SQL_project_id. scripts/check-readonly-user.sh checks the same thing
 * against a running server; the opt-in clickhouse group checks the view against the real table.
 */

function readOnlyUserXml(): SimpleXMLElement
{
    $path = base_path('docker/clickhouse/users.d/20-readonly-user.xml');
    $xml = simplexml_load_file($path);
    expect($xml)->toBeInstanceOf(SimpleXMLElement::class, "cannot parse {$path}");

    return $xml;
}

/** @return list<string> relation names of every `GRANT SELECT ON posthog.<name>` of posthog_ro */
function grantedTables(SimpleXMLElement $xml): array
{
    $tables = [];
    foreach ($xml->users->posthog_ro->grants->query as $query) {
        $sql = trim((string) $query);
        if (preg_match('/^GRANT\s+SELECT\s+ON\s+posthog\.([A-Za-z0-9_]+)$/', $sql, $m) === 1) {
            $tables[] = $m[1];
        } else {
            // any other shape (posthog.*, column list, *.*, other privileges) needs a conscious test update
            test()->fail("unexpected grant for posthog_ro, extend this guard first: {$sql}");
        }
    }

    return $tables;
}

it('grants posthog_ro exactly the two scoped views and nothing on any base table', function () {
    $tables = grantedTables(readOnlyUserXml());

    expect($tables)->toBe([ProjectScopedReader::VIEW, ScopedRelations::PERSON_DISTINCT_IDS_VIEW])
        ->and($tables)->not->toContain('events')
        ->and($tables)->not->toContain('person_distinct_ids')
        ->and($tables)->not->toContain('kafka_person_distinct_ids');
});

it('grants exactly the view of every registered relation, never its base table', function () {
    $tables = grantedTables(readOnlyUserXml());

    foreach (ScopedRelations::all() as $relation) {
        expect($tables)->toContain($relation->view)->and($tables)->not->toContain($relation->table);
    }
    expect($tables)->toHaveCount(count(ScopedRelations::all()));
});

it('has a SQL_project_id row policy for every table posthog_ro may select from', function () {
    $xml = readOnlyUserXml();
    $tables = grantedTables($xml);

    expect($tables)->not->toBeEmpty();
    foreach ($tables as $table) {
        $filter = (string) ($xml->users->posthog_ro->databases->posthog->{$table}->filter ?? '');
        test()->assertStringContainsString('SQL_project_id', $filter, "posthog.{$table} is granted but has no row policy filter on SQL_project_id");
        test()->assertStringContainsString('project_id =', $filter);
    }
});

it('has no row policy for a table that is not granted', function () {
    $xml = readOnlyUserXml();
    $tables = grantedTables($xml);

    foreach ($xml->users->posthog_ro->databases->posthog->children() as $policy) {
        expect($tables)->toContain($policy->getName());
    }
});

it('keeps the custom settings prefix that the row policy needs', function () {
    $xml = simplexml_load_file(base_path('docker/clickhouse/config.d/10-custom-settings.xml'));

    expect((string) $xml->custom_settings_prefixes)->toBe('SQL_');
});

it('mounts the config.d file next to the users.d file in docker-compose.yml', function () {
    $compose = file_get_contents(base_path('docker-compose.yml'));

    expect($compose)->toContain('source: ./docker/clickhouse/config.d/10-custom-settings.xml')
        ->and($compose)->toContain('target: /etc/clickhouse-server/config.d/10-custom-settings.xml');
});

it('creates the scoped view in migration 002 as a DEFINER view over events with the SQL_project_id filter', function () {
    $sql = (string) file_get_contents(base_path('database/clickhouse/migrations/002_scoped_events_view.sql'));
    // comments dropped the way the migrator does
    [$statement] = ClickHouseMigrator::statements($sql);
    $flat = preg_replace('/\s+/', ' ', $statement);

    // 002 itself is checksummed and never edited: only its shape is pinned here. Which columns the view has NOW
    // is decided by the last migration that replaces it (next test).
    expect(ClickHouseMigrator::statements($sql))->toHaveCount(1)
        ->and($flat)->toContain('CREATE VIEW IF NOT EXISTS '.ProjectScopedReader::VIEW)
        ->and($flat)->toContain('DEFINER = CURRENT_USER SQL SECURITY DEFINER')
        ->and($flat)->toContain("WHERE project_id = toUInt32(getSetting('SQL_project_id'))")
        ->and($flat)->toContain('FROM events');
});

it('ends with a scoped view that lists exactly ProjectScopedReader::COLUMNS, in order (all migrations folded)', function () {
    // no SELECT *: it would skip MATERIALIZED columns. The order is the order of ProjectScopedReader::COLUMNS.
    expect(MigrationColumns::viewColumns())->toBe(ProjectScopedReader::COLUMNS);
});

it('keeps the final view definition a DEFINER view with the SQL_project_id filter, whichever migration wrote it', function () {
    $flat = MigrationColumns::viewStatement();

    expect($flat)->not->toBe('')
        ->and($flat)->toContain('DEFINER = CURRENT_USER SQL SECURITY DEFINER')
        ->and($flat)->toContain("WHERE project_id = toUInt32(getSetting('SQL_project_id'))")
        ->and($flat)->toContain('FROM events');
});

it('keeps ProjectScopedReader::COLUMNS equal to the columns events has after ALL migrations', function () {
    expect(MigrationColumns::tableColumns())->toEqualCanonicalizing(ProjectScopedReader::COLUMNS)
        ->and(MigrationColumns::tableColumns())->toBe(ProjectScopedReader::COLUMNS);
});

it('recreates the view in the migration that last changed the columns of events (or later)', function () {
    // ADD COLUMN leaves the view silently without the column: the migration that changes events must also
    // CREATE OR REPLACE the view (ScopedViewDriftCheck finds the mistake at deploy time, this finds it in CI).
    expect(MigrationColumns::lastViewMigration())->not->toBeNull()
        ->and(MigrationColumns::lastViewMigration() >= MigrationColumns::lastColumnMigration())->toBeTrue(
            'the last migration that changes events is '.MigrationColumns::lastColumnMigration().' but the view was last written by '.MigrationColumns::lastViewMigration(),
        );
});

it('numbers the migrations without gaps or duplicates and never edits applied ones by accident', function () {
    $numbers = array_map(fn (string $f) => (int) substr($f, 0, 3), MigrationColumns::files());

    expect($numbers)->toBe(range(1, count($numbers)));
});

it('adds the five enrichment columns in migration 003 and materializes each in its own statement', function () {
    $sql = (string) file_get_contents(base_path('database/clickhouse/migrations/003_enrichment_columns.sql'));
    $statements = ClickHouseMigrator::statements($sql);
    $added = array_values(array_filter(array_map(fn ($s) => preg_match('/ADD COLUMN IF NOT EXISTS (\w+) /', $s, $m) ? $m[1] : null, $statements)));
    $materialized = array_values(array_filter(array_map(fn ($s) => preg_match('/^ALTER TABLE events MATERIALIZE COLUMN (\w+)$/', $s, $m) ? $m[1] : null, $statements)));

    expect($added)->toBe(['os', 'device_type', 'utm_source', 'utm_medium', 'utm_campaign'])
        ->and($materialized)->toBe($added);
});

it('pins the result shape settings of the ro_insights profile and makes them constant', function (string $setting, string $value) {
    $profile = readOnlyUserXml()->profiles->ro_insights;

    expect((string) $profile->{$setting})->toBe($value)
        ->and(isset($profile->constraints->{$setting}->const))->toBeTrue("{$setting} must have a <const/> constraint");
})->with([
    'session_timezone' => ['session_timezone', 'UTC'],
    'json 64 bit integers are numbers' => ['output_format_json_quote_64bit_integers', '0'],
    'query plan is not serialized' => ['serialize_query_plan', '0'],
    'new analyzer' => ['enable_analyzer', '1'],
]);
