<?php

use App\Infrastructure\ClickHouse\ClickHouseMigrator;
use App\Infrastructure\ClickHouse\ProjectScopedReader;

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

it('grants posthog_ro exactly the scoped view and nothing on posthog.events', function () {
    $tables = grantedTables(readOnlyUserXml());

    expect($tables)->toBe([ProjectScopedReader::VIEW])
        ->and($tables)->not->toContain('events');
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

it('defines the scoped view in migration 002 as a DEFINER view over events with the SQL_project_id filter', function () {
    $sql = (string) file_get_contents(base_path('database/clickhouse/migrations/002_scoped_events_view.sql'));
    // comments dropped the way the migrator does
    [$statement] = ClickHouseMigrator::statements($sql);
    $flat = preg_replace('/\s+/', ' ', $statement);

    expect(ClickHouseMigrator::statements($sql))->toHaveCount(1)
        ->and($flat)->toContain('CREATE VIEW IF NOT EXISTS '.ProjectScopedReader::VIEW)
        ->and($flat)->toContain('DEFINER = CURRENT_USER SQL SECURITY DEFINER')
        ->and($flat)->toContain("WHERE project_id = toUInt32(getSetting('SQL_project_id'))")
        ->and($flat)->toContain('FROM events');

    // the view lists exactly ProjectScopedReader::COLUMNS (no SELECT *: it would skip MATERIALIZED columns)
    preg_match('/AS SELECT (.+?) FROM events/', $flat, $m);
    $columns = array_map('trim', explode(',', $m[1] ?? ''));
    expect($columns)->toBe(ProjectScopedReader::COLUMNS);
});

it('keeps ProjectScopedReader::COLUMNS equal to the column names of CREATE TABLE events in migration 001', function () {
    $sql = (string) file_get_contents(base_path('database/clickhouse/migrations/001_events.sql'));
    preg_match('/CREATE TABLE IF NOT EXISTS events \((.*?)\) ENGINE = ReplacingMergeTree/s', $sql, $m);
    preg_match_all('/^\s{4}(\w+) /m', $m[1] ?? '', $names);

    expect($names[1])->toEqualCanonicalizing(ProjectScopedReader::COLUMNS);
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
