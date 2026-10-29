<?php

use App\Domain\Capture\IngestHealth;

/** A snapshot of a perfectly healthy, idle pipeline; tests override single keys. */
function healthySnapshot(array $override = []): array
{
    return array_replace([
        'consumers' => 1,
        'exception_message' => null,
        'exception_age' => null,
        'commit_age' => 500,
        'poll_age' => 2,
        'lag' => 0,
        'offset_sum' => 1000,
        'previous_offset_sum' => null,
        'previous_age' => null,
        'previous_lag' => null,
        'errors_last_hour' => 0,
        'disk_free_bytes' => 50_000_000_000,
        'disk_total_bytes' => 100_000_000_000,
        'parts_active' => 100,
        'parts_max' => 100_000,
        'pending_mutations' => 0,
        'dropped_by_project' => [],
    ], $override);
}

/** The signature of the Kafka consumer wedge described in 10-ingest-settings.xml. */
function wedgedSnapshot(array $override = []): array
{
    return healthySnapshot(array_replace([
        'exception_message' => 'Too many partitions for single INSERT block (more than 1000)',
        'exception_age' => 5,
        'commit_age' => 400,
        'poll_age' => 3,
        // A real wedge always has a backlog: the block that cannot be inserted stays unread-by-commit.
        'lag' => 150,
    ], $override));
}

it('classifies a healthy idle pipeline as OK with no reasons', function () {
    expect(IngestHealth::classify(healthySnapshot()))->toBe(['status' => 'OK', 'reasons' => []]);
});

it('treats a snapshot with only the fields it knows as OK (missing data is not a problem)', function () {
    expect(IngestHealth::classify([])['status'])->toBe('OK');
});

describe('stall signature: exception + stale commit + fresh poll', function () {
    it('is STALLED and names the message and the XML pointer', function () {
        $result = IngestHealth::classify(wedgedSnapshot());

        expect($result['status'])->toBe('STALLED')
            ->and($result['reasons'][0])->toContain('Too many partitions for single INSERT block')
            ->toContain('see the header of docker/clickhouse/users.d/10-ingest-settings.xml');
    });

    it('is STALLED when the consumer has never committed (null commit age)', function () {
        expect(IngestHealth::classify(wedgedSnapshot(['commit_age' => null]))['status'])->toBe('STALLED');
    });

    it('counts an exception exactly 120 s old as recent, 121 s as old', function () {
        expect(IngestHealth::classify(wedgedSnapshot(['exception_age' => 120]))['status'])->toBe('STALLED')
            ->and(IngestHealth::classify(wedgedSnapshot(['exception_age' => 121]))['status'])->not->toBe('STALLED');
    });

    it('needs a commit strictly older than 120 s', function () {
        expect(IngestHealth::classify(wedgedSnapshot(['commit_age' => 121]))['status'])->toBe('STALLED')
            ->and(IngestHealth::classify(wedgedSnapshot(['commit_age' => 120]))['status'])->not->toBe('STALLED')
            ->and(IngestHealth::classify(wedgedSnapshot(['commit_age' => 5]))['status'])->not->toBe('STALLED');
    });

    it('needs a poll fresher than 60 s (a dead poll loop is another problem)', function () {
        expect(IngestHealth::classify(wedgedSnapshot(['poll_age' => 59]))['status'])->toBe('STALLED')
            ->and(IngestHealth::classify(wedgedSnapshot(['poll_age' => 60]))['status'])->not->toBe('STALLED')
            ->and(IngestHealth::classify(wedgedSnapshot(['poll_age' => null]))['status'])->not->toBe('STALLED');
    });

    it('is NOT stalled at lag 0: a quiet topic has nothing to commit, one exception (Kafka restart, cold start) must not read STALLED', function () {
        $result = IngestHealth::classify(wedgedSnapshot(['lag' => 0]));

        expect($result['status'])->toBe('OK')->and($result['reasons'])->toBe([]);
    });

    it('keeps the fallback when the lag is unknown: still STALLED, because the consumer may be wedged', function () {
        expect(IngestHealth::classify(wedgedSnapshot(['lag' => null]))['status'])->toBe('STALLED');
    });

    it('is not stalled without a recent exception', function () {
        expect(IngestHealth::classify(wedgedSnapshot(['exception_message' => null, 'exception_age' => null]))['status'])->toBe('OK');
    });
});

describe('stall signature: lag with frozen offsets', function () {
    it('is STALLED when lag > 0 and the offsets did not move for 60 s or more', function () {
        $result = IngestHealth::classify(healthySnapshot([
            'lag' => 25, 'offset_sum' => 1000,
            'previous_offset_sum' => 1000, 'previous_age' => 60, 'previous_lag' => 25,
        ]));

        expect($result['status'])->toBe('STALLED')
            ->and($result['reasons'][0])->toContain('25')->toContain('see the header of docker/clickhouse/users.d/10-ingest-settings.xml');
    });

    it('is not stalled when the previous snapshot is younger than 60 s', function () {
        expect(IngestHealth::classify(healthySnapshot([
            'lag' => 25, 'previous_offset_sum' => 1000, 'previous_age' => 59, 'previous_lag' => 25,
        ]))['status'])->toBe('OK');
    });

    it('is not stalled when the offsets advanced', function () {
        expect(IngestHealth::classify(healthySnapshot([
            'lag' => 25, 'offset_sum' => 1010, 'previous_offset_sum' => 1000, 'previous_age' => 300, 'previous_lag' => 25,
        ]))['status'])->toBe('OK');
    });

    it('is not stalled at lag 0 or unknown lag, whatever the offsets did', function () {
        $frozen = ['previous_offset_sum' => 1000, 'previous_age' => 300, 'previous_lag' => 0];

        expect(IngestHealth::classify(healthySnapshot(['lag' => 0] + $frozen))['status'])->toBe('OK')
            ->and(IngestHealth::classify(healthySnapshot(['lag' => null] + $frozen))['status'])->toBe('OK');
    });

    it('is not stalled when the previous lag is unknown (null): a baseline without a known backlog proves nothing', function () {
        expect(IngestHealth::classify(healthySnapshot([
            'lag' => 25, 'previous_offset_sum' => 1000, 'previous_age' => 300, 'previous_lag' => null,
        ]))['status'])->toBe('OK');
    });

    it('is not stalled when lag only just appeared (the previous run saw none)', function () {
        expect(IngestHealth::classify(healthySnapshot([
            'lag' => 3, 'previous_offset_sum' => 1000, 'previous_age' => 300, 'previous_lag' => 0,
        ]))['status'])->toBe('OK');
    });

    it('is not stalled without a previous snapshot', function () {
        expect(IngestHealth::classify(healthySnapshot(['lag' => 25]))['status'])->toBe('OK');
    });
});

describe('WARN', function () {
    it('warns about events_errors in the last hour', function () {
        $result = IngestHealth::classify(healthySnapshot(['errors_last_hour' => 1]));

        expect($result['status'])->toBe('WARN')->and($result['reasons'][0])->toContain('events_errors')->toContain('1');
    });

    it('warns below 10 percent free disk and not at exactly 10', function () {
        expect(IngestHealth::classify(healthySnapshot(['disk_free_bytes' => 9_999_000_000]))['status'])->toBe('WARN')
            ->and(IngestHealth::classify(healthySnapshot(['disk_free_bytes' => 10_000_000_000]))['status'])->toBe('OK');
    });

    it('warns below 2 GiB free even when that is a large share of a small disk', function () {
        $gib = 1024 ** 3;

        expect(IngestHealth::classify(healthySnapshot(['disk_free_bytes' => 2 * $gib - 1, 'disk_total_bytes' => 5 * $gib]))['status'])->toBe('WARN')
            ->and(IngestHealth::classify(healthySnapshot(['disk_free_bytes' => 2 * $gib, 'disk_total_bytes' => 5 * $gib]))['status'])->toBe('OK');
    });

    it('warns above 80 percent of max_parts_in_total and not at exactly 80', function () {
        expect(IngestHealth::classify(healthySnapshot(['parts_active' => 80_001]))['status'])->toBe('WARN')
            ->and(IngestHealth::classify(healthySnapshot(['parts_active' => 80_000]))['status'])->toBe('OK');
    });

    it('warns about pending mutations', function () {
        expect(IngestHealth::classify(healthySnapshot(['pending_mutations' => 2]))['status'])->toBe('WARN');
    });

    it('warns when lag is unknown while the consumer raised an exception in the last hour', function () {
        $stale = ['lag' => null, 'exception_message' => 'boom', 'commit_age' => 5];

        expect(IngestHealth::classify(healthySnapshot($stale + ['exception_age' => 3600]))['status'])->toBe('WARN')
            ->and(IngestHealth::classify(healthySnapshot($stale + ['exception_age' => 3601]))['status'])->toBe('OK')
            ->and(IngestHealth::classify(healthySnapshot(['lag' => null]))['status'])->toBe('OK');
    });

    it('warns when no consumer is registered at all', function () {
        expect(IngestHealth::classify(healthySnapshot(['consumers' => 0]))['status'])->toBe('WARN');
    });

    it('collects every reason', function () {
        $result = IngestHealth::classify(healthySnapshot(['errors_last_hour' => 4, 'pending_mutations' => 1]));

        expect($result['reasons'])->toHaveCount(2);
    });
});

it('lets STALLED win over WARN and keeps the warnings as extra reasons', function () {
    $result = IngestHealth::classify(wedgedSnapshot(['errors_last_hour' => 9]));

    expect($result['status'])->toBe('STALLED')
        ->and($result['reasons'])->toHaveCount(2)
        ->and($result['reasons'][0])->toContain('stalled');
});

it('accepts thresholds overrides', function () {
    $snapshot = healthySnapshot(['disk_free_bytes' => 4_000_000_000, 'disk_total_bytes' => 100_000_000_000]);

    expect(IngestHealth::classify($snapshot)['status'])->toBe('WARN')
        ->and(IngestHealth::classify($snapshot, ['min_free_percent' => 1, 'min_free_bytes' => 1_000_000])['status'])->toBe('OK');
});

describe('dropped events', function () {
    it('is informational: drops alone, up to 1000 per project, keep the status OK', function () {
        expect(IngestHealth::classify(healthySnapshot(['dropped_by_project' => [1 => 1000, 2 => 3]])))->toBe(['status' => 'OK', 'reasons' => []]);
    });

    it('warns for a project with more than 1000 drops in the hour and names the project', function () {
        $result = IngestHealth::classify(healthySnapshot(['dropped_by_project' => [1 => 5, 7 => 1001]]));

        expect($result['status'])->toBe('WARN')
            ->and($result['reasons'])->toHaveCount(1)
            ->and($result['reasons'][0])->toContain('project #7')->toContain('1001');
    });

    it('does not add the per-project drops up', function () {
        expect(IngestHealth::classify(healthySnapshot(['dropped_by_project' => [1 => 600, 2 => 600]]))['status'])->toBe('OK');
    });
});

describe('the identity consumer', function () {
    function identityStalled(array $override = []): array
    {
        return healthySnapshot(array_replace([
            'identity_lag' => 30,
            'identity_offset_sum' => 500,
            'identity_previous_offset_sum' => 500,
            'identity_previous_age' => 90,
            'identity_previous_lag' => 12,
        ], $override));
    }

    it('is STALLED when its lag is above 0 and its offsets did not move for a minute', function () {
        $result = IngestHealth::classify(identityStalled());

        expect($result['status'])->toBe('STALLED')
            ->and($result['reasons'][0])->toContain('identity consumer is stalled')->toContain('lag is 30')->toContain('90 s');
    });

    it('is never STALLED at lag 0, however long its offsets stayed put', function () {
        expect(IngestHealth::classify(identityStalled(['identity_lag' => 0, 'identity_previous_age' => 86_000, 'identity_previous_lag' => 0])))->toBe(['status' => 'OK', 'reasons' => []]);
    });

    it('needs a known lag, a baseline that is old enough, with a lag of its own, and offsets that really are the same', function (array $override) {
        expect(IngestHealth::classify(identityStalled($override))['status'])->toBe('OK');
    })->with([
        'unknown lag' => [['identity_lag' => null]],
        'baseline too young' => [['identity_previous_age' => 59]],
        'no baseline' => [['identity_previous_age' => null, 'identity_previous_offset_sum' => null, 'identity_previous_lag' => null]],
        'the baseline was caught up' => [['identity_previous_lag' => 0]],
        'the baseline lag was unknown' => [['identity_previous_lag' => null]],
        'the offsets moved' => [['identity_offset_sum' => 501]],
    ]);

    it('warns when the dead letter topic grew since the previous run, and only then', function (?int $now, ?int $before, bool $warns) {
        $result = IngestHealth::classify(healthySnapshot(['dlq_depth' => $now, 'dlq_previous_depth' => $before]));

        expect($result['status'])->toBe($warns ? 'WARN' : 'OK');
    })->with([
        'grew' => [5, 2, true],
        'same' => [5, 5, false],
        'shrank' => [2, 5, false],
        'first run' => [5, null, false],
        'unknown now' => [null, 5, false],
    ]);
});

describe('the person_distinct_ids consumer of ClickHouse', function () {
    /** The same wedge signature as wedgedSnapshot(), on the person_ keys. */
    function personWedged(array $override = []): array
    {
        return healthySnapshot(array_replace([
            'person_consumers' => 1,
            'person_exception_message' => 'Too many partitions for single INSERT block (more than 1000)',
            'person_exception_age' => 5,
            'person_commit_age' => 400,
            'person_poll_age' => 3,
            'person_lag' => 150,
            'person_offset_sum' => 77,
        ], $override));
    }

    function personFrozen(array $override = []): array
    {
        return healthySnapshot(array_replace([
            'person_consumers' => 1,
            'person_lag' => 30,
            'person_offset_sum' => 500,
            'person_previous_offset_sum' => 500,
            'person_previous_age' => 90,
            'person_previous_lag' => 12,
        ], $override));
    }

    it('is STALLED by the exception signature, with its own subject and the XML pointer', function () {
        $result = IngestHealth::classify(personWedged());

        expect($result['status'])->toBe('STALLED')
            ->and($result['reasons'])->toHaveCount(1)
            ->and($result['reasons'][0])->toStartWith('the person_distinct_ids consumer is stalled: it raised "Too many partitions')
            ->toContain('see the header of docker/clickhouse/users.d/10-ingest-settings.xml');
    });

    it('is STALLED by the frozen offsets signature with a lag above 0', function () {
        $result = IngestHealth::classify(personFrozen());

        expect($result['status'])->toBe('STALLED')
            ->and($result['reasons'][0])->toStartWith('the person_distinct_ids consumer is stalled: lag is 30')->toContain('90 s')->toContain('offset sum 500');
    });

    it('never raises a stall at lag 0: not by a fresh exception, not by offsets that stayed put for a day', function () {
        expect(IngestHealth::classify(personWedged(['person_lag' => 0]))['status'])->toBe('OK')
            ->and(IngestHealth::classify(personFrozen(['person_lag' => 0, 'person_previous_age' => 86_000, 'person_previous_lag' => 0]))['status'])->toBe('OK');
    });

    it('keeps the exception signature armed when the lag is unknown, exactly like the events consumer', function () {
        expect(IngestHealth::classify(personWedged(['person_lag' => null]))['status'])->toBe('STALLED')
            ->and(IngestHealth::classify(wedgedSnapshot(['lag' => null]))['status'])->toBe('STALLED');
    });

    it('applies the same conditions as the events consumer to each signature', function (array $override) {
        expect(IngestHealth::classify(personWedged($override))['status'])->toBe('OK');
    })->with([
        'old exception' => [['person_exception_age' => 121]],
        'committed recently' => [['person_commit_age' => 120]],
        'not polling' => [['person_poll_age' => 60]],
        'no exception' => [['person_exception_age' => null]],
    ]);

    it('needs a known lag, a baseline that is old enough with a lag of its own, and offsets that really are the same', function (array $override) {
        expect(IngestHealth::classify(personFrozen($override))['status'])->toBe('OK');
    })->with([
        'unknown lag' => [['person_lag' => null]],
        'baseline too young' => [['person_previous_age' => 59]],
        'no baseline' => [['person_previous_age' => null, 'person_previous_offset_sum' => null, 'person_previous_lag' => null]],
        'the baseline was caught up' => [['person_previous_lag' => 0]],
        'the offsets moved' => [['person_offset_sum' => 501]],
    ]);

    it('is independent of the events consumer: a healthy events side does not hide a stalled person side and vice versa', function () {
        expect(IngestHealth::classify(personFrozen(['lag' => 0, 'offset_sum' => 5]))['status'])->toBe('STALLED')
            ->and(IngestHealth::classify(wedgedSnapshot(['person_lag' => 0, 'person_consumers' => 1]))['reasons'][0])->toStartWith('the Kafka consumer is stalled');
    });

    it('leaves the events consumer wording byte for byte as it was', function () {
        $frozen = healthySnapshot(['lag' => 30, 'offset_sum' => 500, 'previous_offset_sum' => 500, 'previous_age' => 90, 'previous_lag' => 12]);

        expect(IngestHealth::classify(wedgedSnapshot())['reasons'][0])
            ->toBe('the Kafka consumer is stalled: it raised "Too many partitions for single INSERT block (more than 1000)" 5 s ago, last committed 400 s ago but polled 3 s ago, so it keeps retrying a block that never succeeds; see the header of docker/clickhouse/users.d/10-ingest-settings.xml')
            ->and(IngestHealth::classify($frozen)['reasons'][0])
            ->toBe('Kafka is stalled: lag is 30 and the consumer offsets have not moved for 90 s (offset sum 500); see the header of docker/clickhouse/users.d/10-ingest-settings.xml');
    });

    it('warns when no consumer is registered for its table, and only when the count is really 0', function () {
        $none = IngestHealth::classify(healthySnapshot(['person_consumers' => 0]));

        expect($none['status'])->toBe('WARN')->and($none['reasons'][0])->toContain('posthog.kafka_person_distinct_ids')
            ->and(IngestHealth::classify(healthySnapshot(['person_consumers' => 1]))['status'])->toBe('OK');
    });

    it('warns about an unknown lag after an exception that is too old for a stall but within the hour', function () {
        $result = IngestHealth::classify(healthySnapshot(['person_lag' => null, 'person_exception_age' => 300, 'person_exception_message' => 'Local: Unknown topic', 'person_poll_age' => 2, 'person_commit_age' => 9000]));

        expect($result['status'])->toBe('WARN')->and($result['reasons'][0])->toContain('the person_distinct_ids consumer raised "Local: Unknown topic" 300 s ago');
    });
});
