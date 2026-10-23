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
