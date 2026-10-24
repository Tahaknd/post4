<?php

use App\Infrastructure\ClickHouse\RoSettings;
use Tests\Support\ClickHouseIntegration as CH;

/*
 * Two-tenant isolation against a real ClickHouse (25.8) with the row policy of 20-readonly-user.xml and the
 * SQL_ custom settings prefix of config.d/10-custom-settings.xml. Mocks cannot try either. Opt-in: the whole
 * file is in the group "clickhouse", which phpunit.xml excludes from the default run. Run with
 *
 *   docker compose exec -T -e CLICKHOUSE_INTEGRATION_URL=http://clickhouse:8123 \
 *     -e CLICKHOUSE_INTEGRATION_ADMIN_PASSWORD=posthog -e CLICKHOUSE_INTEGRATION_RO_PASSWORD=posthog_ro \
 *     app php artisan test --group=clickhouse
 *
 * (a command line --group replaces the excluded group from phpunit.xml). Without the variables the tests fail
 * with a message instead of skipping. Rows of projects 990101 / 990102 are seeded before every test and
 * deleted after it (also when the test fails), see Tests\Support\ClickHouseIntegration.
 */

pest()->group('clickhouse');

/**
 * 64 bit integers (count()) come back as numbers or as quoted strings depending on
 * output_format_json_quote_64bit_integers (pinned only in Milestone 3.2): compare as ints.
 *
 * @param  array<int, array<string, mixed>>  $rows
 * @return array<int, array<string, mixed>>
 */
function ints(array $rows): array
{
    return array_map(fn (array $row) => array_map(fn ($v) => is_string($v) && ctype_digit($v) ? (int) $v : $v, $row), $rows);
}

beforeEach(function () {
    $this->ch = CH::connect();
    $this->ch->seed();
});

afterEach(function () {
    if (isset($this->ch)) {
        $this->ch->cleanup();
    }
});

it('shows each project only its own rows through the scoped reader', function () {
    $a = $this->ch->reader->select(CH::PROJECT_A, 'SELECT count() AS n, uniqExact(distinct_id) AS d, groupUniqArray(project_id) AS ids FROM project_events');
    $b = $this->ch->reader->select(CH::PROJECT_B, 'SELECT count() AS n, uniqExact(distinct_id) AS d, groupUniqArray(project_id) AS ids FROM project_events');

    expect((int) $a[0]['n'])->toBe(CH::ROWS_A)
        ->and((int) $a[0]['d'])->toBe(3)
        ->and($a[0]['ids'])->toBe([CH::PROJECT_A])
        ->and((int) $b[0]['n'])->toBe(CH::ROWS_B)
        ->and((int) $b[0]['d'])->toBe(5)
        ->and($b[0]['ids'])->toBe([CH::PROJECT_B]);

    $idsA = array_column($this->ch->reader->select(CH::PROJECT_A, 'SELECT DISTINCT distinct_id FROM project_events ORDER BY distinct_id'), 'distinct_id');
    $idsB = array_column($this->ch->reader->select(CH::PROJECT_B, 'SELECT DISTINCT distinct_id FROM project_events ORDER BY distinct_id'), 'distinct_id');

    expect($idsA)->toBe(['a1', 'a2', 'a3'])
        ->and($idsB)->toBe(['b1', 'b2', 'b3', 'b4', 'b5']);
});

it('shows a project without rows nothing', function () {
    $rows = $this->ch->reader->select(CH::PROJECT_C, 'SELECT count() AS n FROM project_events');

    expect((int) $rows[0]['n'])->toBe(0);
});

it('keeps GROUP BY aggregates inside the project', function () {
    $a = $this->ch->reader->select(CH::PROJECT_A, 'SELECT event, count() AS n FROM project_events GROUP BY event ORDER BY event');
    $b = $this->ch->reader->select(CH::PROJECT_B, 'SELECT event, count() AS n FROM project_events GROUP BY event ORDER BY event');

    expect(ints($a))->toBe([['event' => 'it_click', 'n' => 10], ['event' => 'it_view', 'n' => 20]])
        ->and(ints($b))->toBe([['event' => 'it_buy', 'n' => 50]]);
});

it('keeps UNION, subquery and self join shapes inside the project', function () {
    $union = $this->ch->reader->select(
        CH::PROJECT_A,
        'SELECT project_id, count() AS n FROM (SELECT project_id FROM project_events UNION ALL SELECT project_id FROM project_events) GROUP BY project_id',
    );
    $subquery = $this->ch->reader->select(
        CH::PROJECT_B,
        'SELECT uniqExact(project_id) AS p, count() AS n FROM project_events WHERE distinct_id IN (SELECT distinct_id FROM project_events)',
    );
    $join = $this->ch->reader->select(
        CH::PROJECT_A,
        'SELECT a.project_id AS project_id, count() AS n FROM project_events AS a INNER JOIN project_events AS b ON a.uuid = b.uuid GROUP BY a.project_id',
    );

    expect(ints($union))->toBe([['project_id' => CH::PROJECT_A, 'n' => 2 * CH::ROWS_A]])
        ->and((int) $subquery[0]['p'])->toBe(1)
        ->and((int) $subquery[0]['n'])->toBe(CH::ROWS_B)
        ->and(ints($join))->toBe([['project_id' => CH::PROJECT_A, 'n' => CH::ROWS_A]]);
});

it('passes typed params through the CTE with the right project', function () {
    $rows = $this->ch->reader->select(
        CH::PROJECT_A,
        'SELECT count() AS n FROM project_events WHERE event = {event:String} AND timestamp > now() - INTERVAL {days:UInt8} DAY',
        ['event' => 'it_view', 'days' => 3],
    );

    expect((int) $rows[0]['n'])->toBe(20);
});

it('rejects the bypass attempts in the lint and never reaches ClickHouse', function (string $sql) {
    expect(fn () => $this->ch->reader->select(CH::PROJECT_A, $sql))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'qualified table' => 'SELECT count() FROM posthog.events',
    'bare events table' => 'SELECT count() FROM events',
    'comment' => 'SELECT count() FROM project_events -- tail',
    'block comment' => 'SELECT count() /* x */ FROM project_events',
    'settings override' => 'SELECT count() FROM project_events SETTINGS SQL_project_id = 990102',
    'semicolon' => 'SELECT count() FROM project_events; SELECT 1',
    'placeholder' => 'SELECT count() FROM project_events WHERE project_id = {project_id:UInt32}',
]);

it('refuses a caller SQL_project_id setting on the scoped reader', function () {
    expect(fn () => $this->ch->reader->select(CH::PROJECT_A, 'SELECT count() FROM project_events', [], [RoSettings::SQL_PROJECT_ID => CH::PROJECT_B]))
        ->toThrow(InvalidArgumentException::class);
});

it('fails closed: a direct read-only query WITHOUT SQL_project_id is rejected by the server', function () {
    expect(fn () => $this->ch->ro->select('SELECT count() AS n FROM posthog.events'))
        ->toThrow(RuntimeException::class, 'UNKNOWN_SETTING');
    expect(fn () => $this->ch->ro->select('SELECT project_id, count() AS n FROM posthog.events GROUP BY project_id'))
        ->toThrow(RuntimeException::class, 'SQL_project_id');
});

it('returns only the requested project to a direct read-only query WITH SQL_project_id', function () {
    $rows = $this->ch->ro->select(
        'SELECT project_id, count() AS n FROM posthog.events GROUP BY project_id',
        settings: [RoSettings::SQL_PROJECT_ID => CH::PROJECT_A],
    );

    expect(ints($rows))->toBe([['project_id' => CH::PROJECT_A, 'n' => CH::ROWS_A]]);
});

it('folds the policy into a primary key condition so the index can prune', function () {
    $plan = $this->ch->ro->select(
        'EXPLAIN indexes = 1 SELECT event, count() FROM posthog.events GROUP BY event',
        settings: [RoSettings::SQL_PROJECT_ID => CH::PROJECT_A],
    );
    $text = implode("\n", array_column($plan, 'explain'));

    expect($text)->toContain('project_id in ['.CH::PROJECT_A.', '.CH::PROJECT_A.']');
});

it('KNOWN LIMITATION: a query level SETTINGS SQL_project_id overrides the row policy (documented, not a guard against SQL injection)', function () {
    // The URL argument says project A, but the SQL itself sets B: the query-level setting wins. This is why
    // ProjectScopedReader lints SETTINGS out of builder SQL and refuses caller SQL_* settings; the row policy
    // only protects against builder bugs. See the header of docker/clickhouse/users.d/20-readonly-user.xml.
    $rows = $this->ch->ro->select(
        'SELECT project_id, count() AS n FROM posthog.events GROUP BY project_id SETTINGS SQL_project_id = '.CH::PROJECT_B,
        settings: [RoSettings::SQL_PROJECT_ID => CH::PROJECT_A],
    );

    expect(ints($rows))->toBe([['project_id' => CH::PROJECT_B, 'n' => CH::ROWS_B]]);
});

it('does not apply the row policy to the admin user', function () {
    $rows = $this->ch->admin->select(
        'SELECT project_id, count() AS n FROM posthog.events WHERE project_id IN ({a:UInt32}, {b:UInt32}) GROUP BY project_id ORDER BY project_id',
        ['a' => CH::PROJECT_A, 'b' => CH::PROJECT_B],
    );

    expect(ints($rows))->toBe([
        ['project_id' => CH::PROJECT_A, 'n' => CH::ROWS_A],
        ['project_id' => CH::PROJECT_B, 'n' => CH::ROWS_B],
    ]);
});
