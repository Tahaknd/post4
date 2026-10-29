<?php

use App\Console\Commands\CaptureStatus;
use App\Domain\Capture\KafkaLagProbe;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\ClickHouseStatusFake;
use Tests\Support\FakeKafkaLagProbe;
use Tests\Support\ProjectCache;
use Tests\Support\SplitOutput;

const WEDGE_MESSAGE = 'Too many partitions for single INSERT block (more than 1000)';

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-23 12:00:00');
    config([
        'capture.status.min_free_percent' => 10,
        'capture.status.min_free_bytes' => 2 * 1024 ** 3,
        'capture.status.snapshot_ttl_seconds' => 86400,
    ]);
    ClickHouseStatusFake::install();
    $this->app->instance(KafkaLagProbe::class, new FakeKafkaLagProbe);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** Runs the command with separate stdout and stderr: [exit code, stdout, stderr]. */
function runStatus(array $options = []): array
{
    $out = new SplitOutput;
    $code = Artisan::call('capture:status', $options, $out);

    return [$code, $out->stdout(), $out->stderr()];
}

it('reports OK with exit code 0 for a healthy pipeline and queries every section', function () {
    [$code, $out, $err] = runStatus();

    expect($code)->toBe(0)
        ->and($out)->toContain('Ingest status: OK')
        ->toContain('total rows:          1,500')
        ->toContain('created last hour:   20')
        ->toContain('consumers:           1')
        ->toContain('total lag:           0')
        ->toContain('pending mutations:   0')
        ->and($err)->toBe('')
        ->and(array_unique(ClickHouseStatusFake::$seenTags))->toEqualCanonicalizing(
            ['events', 'errors_count', 'errors_top', 'consumers', 'too_many_parts', 'disks', 'parts', 'mutations'],
        );
});

it('shows the newest events_errors entries grouped by message and exits 1 (WARN)', function () {
    $now = CarbonImmutable::now('UTC')->getTimestamp();
    ClickHouseStatusFake::install([
        'errors_count' => [['c' => '7']],
        'errors_top' => [
            ['err' => 'Cannot parse input: expected \'{\' before: \'not json\'', 'c' => '5', 'newest_ts' => $now - 20],
            ['err' => 'rejected: invalid uuid/project_id/event/distinct_id/timestamp', 'c' => '2', 'newest_ts' => $now - 900],
        ],
    ]);

    [$code, $out, $err] = runStatus();

    expect($code)->toBe(1)
        ->and($out)->toContain('Ingest status: WARN')
        ->toContain('rows:                7')
        ->toContain('5 x  Cannot parse input')
        ->toContain('2 x  rejected: invalid uuid')
        ->toContain('2026-10-23T11:59:40Z')
        ->and($err)->toContain('WARN: 7 message(s) landed in posthog.events_errors')
        ->not->toContain('INGEST STALLED');
});

it('warns for a nearly full disk, many parts and pending mutations, and shows TOO_MANY_PARTS history', function () {
    $now = CarbonImmutable::now('UTC')->getTimestamp();
    ClickHouseStatusFake::install([
        'disks' => [
            ['name' => 'default', 'free_space' => '2731237376', 'total_space' => '62671097856'],
            ['name' => 'other', 'free_space' => '900000000000', 'total_space' => '1000000000000'],
        ],
        'parts' => [['active' => '90000', 'max_parts' => '100000']],
        'mutations' => [['c' => '2']],
        'too_many_parts' => [['value' => '3', 'last_error_ts' => $now - 4000, 'last_error_message' => "Too many partitions\nfor single INSERT block"]],
    ]);

    [$code, $out, $err] = runStatus();

    expect($code)->toBe(1)
        ->and($out)->toContain('2.5 GiB free of 58.4 GiB (4.4%)')
        ->toContain('active parts:        90000 (max_parts_in_total 100000)')
        ->toContain('pending mutations:   2')
        ->toContain('TOO_MANY_PARTS:      3 time(s), last 2026-10-23T10:53:20Z: Too many partitions for single INSERT block')
        ->and($err)->toContain('nearly full')->toContain('active parts')->toContain('2 ClickHouse mutation(s)');
});

it('uses the configurable disk thresholds', function () {
    ClickHouseStatusFake::install(['disks' => [['name' => 'default', 'free_space' => '2731237376', 'total_space' => '62671097856']]]);
    config(['capture.status.min_free_percent' => 1, 'capture.status.min_free_bytes' => 1_000_000_000]);

    expect(runStatus()[0])->toBe(0);
});

it('detects the consumer wedge: STALLED, exit 2, one prominent line with the message and the XML pointer', function () {
    ClickHouseStatusFake::install([
        'consumers' => [ClickHouseStatusFake::consumer(commitAgo: 400, pollAgo: 2, exceptions: [3 => WEDGE_MESSAGE, 9 => WEDGE_MESSAGE])],
    ]);
    $this->app->instance(KafkaLagProbe::class, new FakeKafkaLagProbe([0 => ['committed' => 10, 'high' => 160, 'lag' => 150]]));

    [$code, $out, $err] = runStatus();

    expect($code)->toBe(2)
        ->and($out)->toContain('Ingest status: STALLED')
        ->toContain('newest exception:    2026-10-23T11:59:57Z (3 s ago): '.WEDGE_MESSAGE)
        ->toContain('total lag:           150')
        ->and($err)->toContain('INGEST STALLED: ')
        ->toContain(WEDGE_MESSAGE)
        ->toContain('see the header of docker/clickhouse/users.d/10-ingest-settings.xml')
        ->and(substr_count($err, 'INGEST STALLED'))->toBe(1);
});

it('does not call one recent exception a stall on an idle topic (lag 0): Kafka restart or cold start', function () {
    ClickHouseStatusFake::install([
        'consumers' => [ClickHouseStatusFake::consumer(commitAgo: 4000, pollAgo: 2, exceptions: [10 => 'Local: Broker transport failure'])],
    ]);

    [$code, $out, $err] = runStatus();

    expect($code)->toBe(0)
        ->and($out)->toContain('Ingest status: OK')->toContain('total lag:           0')
        ->and($err)->toBe('');
});

it('does not call an old exception a stall, and a quiet idle consumer is not one either', function () {
    ClickHouseStatusFake::install([
        'consumers' => [ClickHouseStatusFake::consumer(commitAgo: 400, pollAgo: 2, exceptions: [500 => WEDGE_MESSAGE])],
    ]);

    expect(runStatus()[0])->toBe(0);
});

it('detects frozen offsets with lag across two runs 60 s apart', function () {
    $this->app->instance(KafkaLagProbe::class, new FakeKafkaLagProbe([0 => ['committed' => 10, 'high' => 40, 'lag' => 30]]));

    [$first, $firstOut] = runStatus();
    expect($first)->toBe(0)->and($firstOut)->toContain('no previous run recorded');

    CarbonImmutable::setTestNow('2026-10-23 12:01:05');
    ClickHouseStatusFake::install();
    [$second, $secondOut, $secondErr] = runStatus();

    expect($second)->toBe(2)
        ->and($secondOut)->toContain('unchanged for 65 s')
        ->and($secondErr)->toContain('INGEST STALLED: Kafka is stalled: lag is 30')->toContain('10-ingest-settings.xml');
});

it('does not flag frozen offsets with lag when the runs are less than 60 s apart', function () {
    $this->app->instance(KafkaLagProbe::class, new FakeKafkaLagProbe([0 => ['committed' => 10, 'high' => 40, 'lag' => 30]]));
    runStatus();

    CarbonImmutable::setTestNow('2026-10-23 12:00:30');
    ClickHouseStatusFake::install();

    expect(runStatus()[0])->toBe(0);
});

it('stores a snapshot under capture-status:last and reports whether offsets advanced', function () {
    runStatus();

    expect(Cache::get(CaptureStatus::SNAPSHOT_KEY))->toBe(['offset_sum' => 1447, 'at' => CarbonImmutable::now('UTC')->getTimestamp(), 'lag' => 0]);

    CarbonImmutable::setTestNow('2026-10-23 12:10:00');
    ClickHouseStatusFake::install(['consumers' => [ClickHouseStatusFake::consumer(offsets: [-1001, 1500, 5])]]);
    [$code, $out] = runStatus();

    expect($code)->toBe(0)
        ->and($out)->toContain('advanced by 58 since the previous run 600 s ago')
        ->and(Cache::get(CaptureStatus::SNAPSHOT_KEY)['offset_sum'])->toBe(1505);
});

it('keeps the frozen-offsets baseline instead of refreshing it, so its age keeps growing', function () {
    $this->app->instance(KafkaLagProbe::class, new FakeKafkaLagProbe([0 => ['committed' => 10, 'high' => 40, 'lag' => 30]]));
    runStatus();
    $baseline = Cache::get(CaptureStatus::SNAPSHOT_KEY);

    CarbonImmutable::setTestNow('2026-10-23 12:00:20');
    ClickHouseStatusFake::install();
    runStatus();
    expect(Cache::get(CaptureStatus::SNAPSHOT_KEY))->toBe($baseline);

    CarbonImmutable::setTestNow('2026-10-23 12:01:10');
    ClickHouseStatusFake::install();
    expect(runStatus()[0])->toBe(2);
});

it('reports a reset when the offsets go down', function () {
    runStatus();

    CarbonImmutable::setTestNow('2026-10-23 12:05:00');
    ClickHouseStatusFake::install(['consumers' => [ClickHouseStatusFake::consumer(offsets: [-1001, 100, -1001])]]);

    expect(runStatus()[1])->toContain('went DOWN by 1347');
});

it('works without a usable cache (fail-open)', function () {
    ProjectCache::breakStore();

    [$code, $out] = runStatus();

    expect($code)->toBe(0)->and($out)->toContain('no previous run recorded');
});

it('shows lag as unknown with a stderr note when the probe fails, and stays non-fatal', function () {
    $this->app->instance(KafkaLagProbe::class, new FakeKafkaLagProbe(failWith: new RuntimeException("Local: Broker transport failure\nafter 3000 ms")));

    [$code, $out, $err] = runStatus();

    expect($code)->toBe(0)
        ->and($out)->toContain('total lag:           unknown')
        ->and($err)->toContain('NOTE: Kafka lag is unknown: Local: Broker transport failure after 3000 ms');
});

it('warns when lag is unknown while the consumer has a recent exception', function () {
    ClickHouseStatusFake::install(['consumers' => [ClickHouseStatusFake::consumer(commitAgo: 5, exceptions: [30 => 'boom'])]]);
    $this->app->instance(KafkaLagProbe::class, new FakeKafkaLagProbe(failWith: new RuntimeException('timeout')));

    [$code, , $err] = runStatus();

    expect($code)->toBe(1)->and($err)->toContain('lag could not be computed');
});

it('treats a consumer that never committed as a stale commit', function () {
    ClickHouseStatusFake::install(['consumers' => [ClickHouseStatusFake::consumer(commitAgo: 0, exceptions: [4 => WEDGE_MESSAGE])]]);
    $this->app->instance(KafkaLagProbe::class, new FakeKafkaLagProbe([0 => ['committed' => null, 'high' => 40, 'lag' => 40]]));

    [$code, $out, $err] = runStatus();

    expect($code)->toBe(2)->and($out)->toContain('last commit:         never')->and($err)->toContain('last committed never');
});

describe('--json', function () {
    it('prints exactly one JSON object on stdout and nothing on stderr when healthy', function () {
        [$code, $out, $err] = runStatus(['--json' => true]);

        $json = json_decode($out, true, flags: JSON_THROW_ON_ERROR);

        expect($code)->toBe(0)
            ->and($err)->toBe('')
            ->and(substr_count(trim($out), "\n"))->toBe(0)
            ->and($json['status'])->toBe('OK')
            ->and($json['exit_code'])->toBe(0)
            ->and($json['reasons'])->toBe([])
            ->and($json['events'])->toMatchArray(['total' => 1500, 'last_hour' => 20, 'newest_created_at' => '2026-10-23T11:59:30Z'])
            ->and($json['events_errors'])->toBe(['last_hour' => 0, 'top' => []])
            ->and($json['kafka_consumer'])->toMatchArray(['consumers' => 1, 'newest_exception' => null, 'offset_sum' => 1447, 'last_poll_age_seconds' => 1])
            ->and($json['kafka_lag'])->toMatchArray(['known' => true, 'total' => 0])
            ->and($json['kafka_lag']['partitions'])->toBe(['0' => ['committed' => 10, 'high' => 10, 'lag' => 0]])
            ->and($json['clickhouse'])->toMatchArray(['active_parts' => 120, 'max_parts_in_total' => 100000, 'pending_mutations' => 0, 'too_many_parts' => null])
            ->and($json['clickhouse']['disk'])->toMatchArray(['free_bytes' => 50000000000, 'free_percent' => 50.0])
            ->and($json['previous_run'])->toMatchArray(['found' => false, 'offsets' => 'none']);
    });

    it('keeps stdout pure JSON when STALLED: the prominent line and notes go to stderr', function () {
        ClickHouseStatusFake::install([
            'consumers' => [ClickHouseStatusFake::consumer(commitAgo: 400, exceptions: [3 => WEDGE_MESSAGE])],
        ]);
        $this->app->instance(KafkaLagProbe::class, new FakeKafkaLagProbe(failWith: new RuntimeException('no brokers')));

        [$code, $out, $err] = runStatus(['--json' => true]);

        $json = json_decode($out, true, flags: JSON_THROW_ON_ERROR);

        expect($code)->toBe(2)
            ->and($json['status'])->toBe('STALLED')
            ->and($json['exit_code'])->toBe(2)
            ->and($json['reasons'][0])->toContain(WEDGE_MESSAGE)->toContain('10-ingest-settings.xml')
            ->and($json['kafka_consumer']['newest_exception']['message'])->toBe(WEDGE_MESSAGE)
            ->and($json['kafka_lag'])->toMatchArray(['known' => false, 'total' => null])
            ->and($out)->not->toContain('INGEST STALLED')
            ->and($err)->toContain('INGEST STALLED')->toContain('NOTE: Kafka lag is unknown');
    });

    it('lists the WARN reasons in the JSON', function () {
        ClickHouseStatusFake::install(['errors_count' => [['c' => '1']], 'errors_top' => [['err' => 'x', 'c' => '1', 'newest_ts' => 1790000000]]]);

        [$code, $out] = runStatus(['--json' => true]);
        $json = json_decode($out, true, flags: JSON_THROW_ON_ERROR);

        expect($code)->toBe(1)
            ->and($json['status'])->toBe('WARN')
            ->and($json['reasons'])->toHaveCount(1)
            ->and($json['events_errors']['top'][0])->toBe(['error' => 'x', 'count' => 1, 'newest_received_at' => '2026-09-21T14:13:20Z']);
    });
});

describe('ClickHouse unavailable', function () {
    it('exits 2 with one clear stderr message and no stack trace when it cannot connect', function () {
        ClickHouseStatusFake::down();

        [$code, $out, $err] = runStatus();

        expect($code)->toBe(2)
            ->and($out)->toBe('')
            ->and($err)->toContain('Cannot query ClickHouse (ConnectionException)')->toContain('Failed to connect')->toContain('ERROR: ')
            ->not->toContain('Stack trace')->not->toContain('#0 ');
    });

    it('exits 2 for a server error, and --json then prints a status ERROR object', function () {
        ClickHouseStatusFake::install(['events' => fn () => Http::response('Code: 516. DB::Exception: posthog: Authentication failed', 516)]);

        [$code, $out, $err] = runStatus(['--json' => true]);
        $json = json_decode($out, true, flags: JSON_THROW_ON_ERROR);

        expect($code)->toBe(2)
            ->and($json['status'])->toBe('ERROR')
            ->and($json['reasons'][0])->toContain('Authentication failed')
            ->and($err)->toContain('Authentication failed')->not->toContain('Stack trace');
    });

    it('also fails cleanly when only a later section fails', function () {
        ClickHouseStatusFake::install(['mutations' => fn () => Http::response('Code: 497. ACCESS_DENIED', 403)]);

        [$code, , $err] = runStatus();

        expect($code)->toBe(2)->and($err)->toContain('ACCESS_DENIED');
    });
});

it('runs through the artisan facade like a real invocation', function () {
    $this->artisan('capture:status')->assertExitCode(0);
});

it('registers the command with its exit-code help', function () {
    $this->artisan('capture:status --help')->expectsOutputToContain('INGEST STALLED')->assertExitCode(0);
});

describe('dropped events', function () {
    it('shows drops of the current and previous hour per project with a total, and stays OK', function () {
        $a = Project::factory()->create();
        $b = Project::factory()->create();
        $quiet = Project::factory()->create();
        Cache::put("capture-dropped:{$a->id}:2026102312", 4, 7200);
        Cache::put("capture-dropped:{$a->id}:2026102311", 3, 7200);
        Cache::put("capture-dropped:{$b->id}:2026102312", 10, 7200);
        Cache::put("capture-dropped:{$a->id}:2026102310", 999, 7200);

        [$code, $out, $err] = runStatus();

        expect($code)->toBe(0)
            ->and($out)->toContain('Dropped events')
            ->toContain('total:              17')
            ->toContain("project #{$a->id}:".str_repeat(' ', 1))
            ->and($out)->toMatch("/project #{$a->id}:\s+7/")
            ->and($out)->toMatch("/project #{$b->id}:\s+10/")
            ->and($out)->not->toContain("project #{$quiet->id}:")
            ->and($err)->toBe('');
    });

    it('has the drops in the JSON', function () {
        $a = Project::factory()->create();
        Cache::put("capture-dropped:{$a->id}:2026102312", 2, 7200);

        [, $out] = runStatus(['--json' => true]);
        $json = json_decode($out, true, flags: JSON_THROW_ON_ERROR);

        expect($json['dropped'])->toBe(['known' => true, 'last_hour_total' => 2, 'by_project' => [(string) $a->id => 2]]);
    });

    it('shows none when nothing was dropped', function () {
        Project::factory()->create();

        [$code, $out] = runStatus();
        $json = json_decode(runStatus(['--json' => true])[1], true, flags: JSON_THROW_ON_ERROR);

        expect($code)->toBe(0)->and($out)->toContain('total:              0')
            ->and($json['dropped'])->toBe(['known' => true, 'last_hour_total' => 0, 'by_project' => []]);
    });

    it('warns (exit 1) for a project with more than 1000 drops in the hour', function () {
        $a = Project::factory()->create();
        Cache::put("capture-dropped:{$a->id}:2026102312", 600, 7200);
        Cache::put("capture-dropped:{$a->id}:2026102311", 401, 7200);

        [$code, , $err] = runStatus();

        expect($code)->toBe(1)->and($err)->toContain("project #{$a->id}")->toContain('1001');
    });

    it('does not warn at exactly 1000', function () {
        $a = Project::factory()->create();
        Cache::put("capture-dropped:{$a->id}:2026102312", 1000, 7200);

        expect(runStatus()[0])->toBe(0);
    });

    it('is non-fatal when the cache is unavailable: drops are unknown with a stderr note', function () {
        Project::factory()->create();
        ProjectCache::breakStore();

        [$code, $out, $err] = runStatus();

        expect($code)->toBe(0)
            ->and($out)->toContain('total:              unknown')
            ->and($err)->toContain('NOTE: dropped-event counters are unknown');
    });
});
