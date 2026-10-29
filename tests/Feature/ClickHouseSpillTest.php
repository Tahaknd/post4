<?php

use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Tests\Support\ClickHouseIntegration as CH;

/*
 * The spill settings of the read-only profile (docker/clickhouse/users.d/20-readonly-user.xml) against a real ClickHouse 25.8,
 * through the real read-only user: a GROUP BY whose state is far above the 400 MB per query cap finishes (it is written to
 * the temp directory past 150 MB) with the exact answer, the pathological case still fails cleanly as a memory limit, and
 * nothing is left behind on disk. Opt-in (group "clickhouse"). 3 million rows of the tenant SPILL_A, in a partition of its own.
 */

pest()->group('clickhouse');

const SPILL_ROWS = 3_000_000;

/** `SELECT count() ... GROUP BY uuid`: every row is its own group, each with a one-element array: well above the cap without spilling. */
const SPILL_SQL = 'SELECT count() AS grp, sum(length(ids)) AS total FROM (SELECT uuid, groupArray(distinct_id) AS ids FROM project_events GROUP BY uuid)';

function spillSeed(CH $ch): void
{
    static $seeded = false;
    if ($seeded) {
        return;
    }
    $seeded = true;
    $ch->cleanupSpill();
    $ch->admin->execute(
        'INSERT INTO posthog.events (uuid, project_id, event, distinct_id, properties, timestamp) '
        .'SELECT generateUUIDv4(), {p:UInt32}, \'spill\', concat(\'distinct-id-\', toString(number)), \'{}\', '
        ."toDateTime64('2001-01-15 12:00:00', 3, 'UTC') + number / 1000 FROM numbers({n:UInt32}) SETTINGS max_insert_threads = 2",
        ['p' => CH::SPILL_A, 'n' => SPILL_ROWS],
    );
}

/** @return array<string, mixed> the finished SELECT of the query log for this log comment (admin view) */
function spillLog(CH $ch, string $comment): array
{
    // The log is written in the background: flush and look again for a few seconds.
    $deadline = microtime(true) + 10;
    do {
        $ch->admin->execute('SYSTEM FLUSH LOGS');
        $rows = $ch->admin->select(
            'SELECT exception_code, memory_usage, ProfileEvents[\'ExternalProcessingFilesTotal\'] AS files FROM system.query_log '
            .'WHERE log_comment = {c:String} AND type != \'QueryStart\' ORDER BY event_time_microseconds DESC LIMIT 1',
            ['c' => $comment],
        );
    } while ($rows === [] && microtime(true) < $deadline && usleep(250_000) === null);
    expect($rows)->toHaveCount(1);

    return $rows[0];
}

afterAll(function () {
    Http::swap(new Factory);
    try {
        CH::connect()->cleanupSpill();
    } finally {
        Facade::clearResolvedInstances();
    }
});

it('finishes a GROUP BY far above the memory cap by spilling it to disk, with the exact numbers', function () {
    $ch = CH::connect();
    spillSeed($ch);

    $rows = $ch->reader->select(CH::SPILL_A, SPILL_SQL, settings: ['log_comment' => 'spill-test:ok']);

    expect($rows)->toBe([['grp' => SPILL_ROWS, 'total' => SPILL_ROWS]]);
    $log = spillLog($ch, 'spill-test:ok');
    expect((int) $log['exception_code'])->toBe(0)
        ->and((int) $log['files'])->toBeGreaterThan(0)                // it really spilled
        ->and((int) $log['memory_usage'])->toBeLessThan(400_000_000); // and stayed under the cap
});

it('still fails cleanly as a memory limit when even the spill cannot save the query', function () {
    $ch = CH::connect();
    spillSeed($ch);

    try {
        $ch->reader->select(CH::SPILL_A, SPILL_SQL, settings: ['max_memory_usage' => 40_000_000, 'log_comment' => 'spill-test:small']);
        $this->fail('a 40 MB limit must stop this query');
    } catch (ClickHouseException $e) {
        expect($e->kind)->toBe(ClickHouseErrorKind::MemoryLimit);
    }
    expect((int) spillLog($ch, 'spill-test:small')['exception_code'])->toBe(241);
});

it('leaves no temporary file behind after a spilling query, finished or failed', function () {
    $ch = CH::connect();
    spillSeed($ch);

    $ch->reader->select(CH::SPILL_A, SPILL_SQL, settings: ['log_comment' => 'spill-test:clean']);
    try {
        $ch->reader->select(CH::SPILL_A, SPILL_SQL, settings: ['max_memory_usage' => 40_000_000]);
    } catch (ClickHouseException) {
    }

    $bytes = (int) $ch->admin->select("SELECT sum(value) AS b FROM system.metrics WHERE metric LIKE 'TemporaryFiles%'")[0]['b'];
    expect($bytes)->toBe(0);
});

it('lets a caller neither switch the spill off nor raise the disk limits', function (string $setting, string $value) {
    CH::connect();   // allows the integration URL for stray requests
    $response = Http::withHeaders(['X-ClickHouse-User' => 'posthog_ro', 'X-ClickHouse-Key' => (string) getenv('CLICKHOUSE_INTEGRATION_RO_PASSWORD')])
        ->withBody('SELECT 1', 'text/plain')
        ->post(rtrim((string) getenv('CLICKHOUSE_INTEGRATION_URL'), '/').'/?SQL_project_id=1&'.$setting.'='.$value);

    expect($response->header('X-ClickHouse-Exception-Code'))->toBe('452');
})->with([
    ['max_bytes_before_external_group_by', '0'],
    ['max_bytes_before_external_group_by', '1000000000'],
    ['max_bytes_before_external_sort', '1000'],
    ['max_temporary_data_on_disk_size_for_query', '0'],
    ['max_temporary_data_on_disk_size_for_user', '0'],
    ['min_free_disk_space_for_temporary_data', '0'],
]);
