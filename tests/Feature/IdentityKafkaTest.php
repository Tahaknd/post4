<?php

use App\Domain\Identity\IdentityKind;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\KafkaEndToEnd;

/*
 * The identity consumer end to end against the REAL compose stack: events go through the real /batch/ endpoint (Octane
 * in this container) into Kafka, `identity:consume` runs as a child process against the real Kafka and Postgres, and
 * the persons, the person_distinct_ids rows and the dead letters are read back from where they really are.
 *
 * Opt-in: the group "kafka-identity" is excluded from the default run. Run it with scripts/test-identity.sh, which
 * stops the live `identity` service for the duration (so this test's consumer is the only one that acts), creates
 * the scratch project this test uses, and deletes it, its groups and the service state afterwards:
 *
 *   scripts/test-identity.sh
 *
 * Everything is scoped to the scratch project: its events, its persons, its rows. Several consumer groups are used,
 * all named identity-e2e-*; none of them is the live group `identity`.
 *
 * The scenario runs once (scenario()); each test asserts one aspect of it.
 *
 * Ordering: Kafka orders messages only inside a partition, and the partition comes from `<project>:<event distinct id>`.
 * Events whose order matters therefore share the event's distinct id; the two phases (one consumer run each) order
 * the merge after the two persons it merges.
 */

pest()->group('kafka-identity');

beforeEach(fn () => Http::allowStrayRequests());

/** The scenario, run once per process. */
function scenario(): array
{
    static $result = null;

    if ($result !== null) {
        return $result;
    }

    $projectId = (int) KafkaEndToEnd::require('IDENTITY_E2E_PROJECT_ID');
    $apiKey = KafkaEndToEnd::require('IDENTITY_E2E_API_KEY');
    $host = rtrim((string) (getenv('IDENTITY_E2E_HOST') ?: 'http://localhost:8000'), '/');
    $run = bin2hex(random_bytes(4));
    $id = fn (string $name) => "e2e-{$run}-{$name}";
    $db = KafkaEndToEnd::liveConnection();
    $group = "identity-e2e-{$run}";

    // Where every topic is now, so that only this run's messages are looked at, and the groups start there.
    $eventsFrom = KafkaEndToEnd::highWatermarks('events');
    $rowsFrom = KafkaEndToEnd::highWatermarks('person_distinct_ids');
    $dlqFrom = KafkaEndToEnd::highWatermarks('events_dlq');

    $send = function (array $events) use ($host, $apiKey): void {
        $batch = array_map(fn (array $e) => $e + ['timestamp' => gmdate('Y-m-d\TH:i:s\Z')], $events);
        $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'])
            ->asJson()->post("{$host}/batch/", ['api_key' => $apiKey, 'batch' => $batch]);
        expect($response->status())->toBe(200, 'the capture endpoint refused the events: '.$response->body());
    };
    $identity = fn (IdentityKind $kind, string $distinctId, ?string $other) => [
        'event' => $kind->value,
        'distinct_id' => $distinctId,
        'properties' => $other === null ? ['$set' => ['plan' => 'pro']] : [$kind->counterpartProperty() => $other],
    ];

    // Phase 1: independent events, no order between them except what shares a distinct id.
    $send([
        $identity(IdentityKind::Identify, $id('alice'), $id('anon-1')),
        ['event' => '$pageview', 'distinct_id' => $id('anon-2'), 'properties' => ['$current_url' => 'http://example.test/']],
        $identity(IdentityKind::Identify, $id('bob'), $id('anon-2')),
        $identity(IdentityKind::Identify, $id('alice'), $id('anon-1')),
        $identity(IdentityKind::CreateAlias, $id('carol'), $id('dave')),
        $identity(IdentityKind::Identify, $id('erin'), null),
        $identity(IdentityKind::Identify, $id('alice'), 'undefined'),
    ]);

    // A poison message: valid JSON that ClickHouse also accepts, but no string distinct id for the identity consumer.
    $poison = KafkaEndToEnd::produce('events', json_encode([
        'uuid' => (string) Str::uuid7(), 'project_id' => $projectId, 'event' => '$identify', 'distinct_id' => 5,
        'properties' => json_encode(['$anon_distinct_id' => $id('never')]), 'timestamp' => gmdate('Y-m-d H:i:s').'.000',
    ]), "{$projectId}:poison-{$run}");

    KafkaEndToEnd::commitOffsets($group, 'events', $eventsFrom);
    $first = KafkaEndToEnd::runConsumer($group);

    $afterFirst = [
        'persons' => $db->table('persons')->where('project_id', $projectId)->orderBy('id')->get(['uuid', 'is_identified'])->map(fn ($p) => (array) $p)->all(),
        'ids' => $db->table('person_distinct_ids')->where('project_id', $projectId)->orderBy('distinct_id')->get(['distinct_id', 'version'])->map(fn ($r) => (array) $r)->all(),
    ];

    // Phase 2: the merge, after both persons exist: alice absorbs bob's person (bob and anon-2 move, version 1).
    $send([$identity(IdentityKind::MergeDangerously, $id('alice'), $id('bob'))]);
    $second = KafkaEndToEnd::runConsumer($group);

    $state = function () use ($db, $projectId): array {
        return [
            'persons' => $db->table('persons')->where('project_id', $projectId)->orderBy('uuid')->get(['uuid', 'is_identified', 'properties', 'created_at'])->map(fn ($p) => (array) $p)->all(),
            'ids' => $db->table('person_distinct_ids as d')->join('persons as p', 'p.id', '=', 'd.person_id')->where('d.project_id', $projectId)
                ->orderBy('d.distinct_id')->get(['d.distinct_id', 'd.version', 'p.uuid as person_uuid', 'd.updated_at'])->map(fn ($r) => (array) $r)->all(),
        ];
    };
    $final = $state();
    $rowsAfterRun = KafkaEndToEnd::readJson('person_distinct_ids', $rowsFrom);
    $dlqAfterRun = KafkaEndToEnd::readJson('events_dlq', $dlqFrom);

    // Replay: a NEW group at the same starting offsets reads every event again (including the merge, now a repeat).
    $replayGroup = "identity-e2e-{$run}-replay";
    KafkaEndToEnd::commitOffsets($replayGroup, 'events', $eventsFrom);
    $replay = KafkaEndToEnd::runConsumer($replayGroup);
    $afterReplay = $state();
    $rowsAfterReplay = KafkaEndToEnd::readJson('person_distinct_ids', $rowsFrom);
    $dlqAfterReplay = KafkaEndToEnd::readJson('events_dlq', $dlqFrom);

    $own = fn (array $rows) => array_values(array_filter($rows, fn ($r) => ($r['project_id'] ?? null) === $projectId));
    $ownDlq = fn (array $rows) => array_values(array_filter($rows, fn ($r) => ($r['source_key'] ?? null) === "{$projectId}:poison-{$run}"));

    return $result = compact('projectId', 'run', 'id', 'poison', 'first', 'second', 'replay', 'afterFirst', 'final', 'afterReplay')
        + [
            'rows' => $own($rowsAfterRun),
            'rowsAfterReplay' => $own($rowsAfterReplay),
            'dlq' => $ownDlq($dlqAfterRun),
            'dlqAfterReplay' => $ownDlq($dlqAfterReplay),
        ];
}

it('runs the consumer to a clean exit and counts what it did', function () {
    $s = scenario();

    expect($s['first']['exit'])->toBe(0, $s['first']['stderr'])
        ->and($s['second']['exit'])->toBe(0, $s['second']['stderr'])
        ->and($s['first']['counts'])->toMatchArray(['created' => 3, 'noop' => 1, 'rejected' => 1, 'no_counterpart' => 1, 'poison' => 1])
        ->and($s['first']['counts']['filtered'])->toBeGreaterThanOrEqual(1)
        ->and($s['second']['counts'])->toMatchArray(['merged' => 1])
        ->and($s['first']['counts']['commits'])->toBeGreaterThan(0);
});

it('creates the persons: identified by $identify, not by $create_alias, and merged by $merge_dangerously with the versions bumped', function () {
    $s = scenario();
    $id = $s['id'];
    $versions = array_column($s['final']['ids'], 'version', 'distinct_id');
    $persons = array_column($s['final']['ids'], 'person_uuid', 'distinct_id');

    // After phase 1: alice+anon-1, bob+anon-2 (both identified), carol+dave (an alias: not identified); nothing for erin.
    expect($s['afterFirst']['persons'])->toHaveCount(3)
        ->and(array_column($s['afterFirst']['ids'], 'version', 'distinct_id'))->toBe([
            $id('alice') => 0, $id('anon-1') => 0, $id('anon-2') => 0, $id('bob') => 0, $id('carol') => 0, $id('dave') => 0,
        ]);

    // After the merge: two persons. One holds alice, anon-1, bob, anon-2 (bob's two ids moved: version 1).
    expect($s['final']['persons'])->toHaveCount(2)
        ->and($versions)->toBe([$id('alice') => 0, $id('anon-1') => 0, $id('anon-2') => 1, $id('bob') => 1, $id('carol') => 0, $id('dave') => 0])
        ->and(array_unique([$persons[$id('alice')], $persons[$id('anon-1')], $persons[$id('bob')], $persons[$id('anon-2')]]))->toHaveCount(1)
        ->and($persons[$id('carol')])->toBe($persons[$id('dave')])->not->toBe($persons[$id('alice')]);

    $flags = array_column($s['final']['persons'], 'is_identified', 'uuid');
    expect((bool) $flags[$persons[$id('alice')]])->toBeTrue()
        ->and((bool) $flags[$persons[$id('carol')]])->toBeFalse();
});

it('writes one row per changed distinct id to person_distinct_ids, and the latest version of every id names the person Postgres has', function () {
    $s = scenario();
    $id = $s['id'];
    $personOf = array_column($s['final']['ids'], 'person_uuid', 'distinct_id');

    // 6 rows from phase 1 (a created person has two) and 2 for the ids the merge moved; nothing for the repeat, the
    // refused id, the event without a second id and the pageview.
    expect($s['rows'])->toHaveCount(8);

    foreach ($s['rows'] as $row) {
        expect(array_keys($row))->toBe(['project_id', 'distinct_id', 'person_id', 'version', 'is_deleted', 'created_at'])
            ->and($row['is_deleted'])->toBe(0)
            ->and($row['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/');
    }

    $latest = [];
    foreach ($s['rows'] as $row) {
        if (! isset($latest[$row['distinct_id']]) || $row['version'] > $latest[$row['distinct_id']]['version']) {
            $latest[$row['distinct_id']] = $row;
        }
    }
    ksort($latest);

    // What a ReplacingMergeTree(version) would keep is exactly the state of Postgres.
    $versionOf = array_column($s['final']['ids'], 'version', 'distinct_id');

    expect(array_map(fn ($r) => [$r['version'], $r['person_id']], $latest))
        ->toBe(array_combine(array_keys($latest), array_map(fn ($d) => [(int) $versionOf[$d], $personOf[$d]], array_keys($latest))))
        ->and(array_keys($latest))->toBe([$id('alice'), $id('anon-1'), $id('anon-2'), $id('bob'), $id('carol'), $id('dave')]);
});

it('dead-letters the poison message with its origin and the original payload, and the partition is not blocked', function () {
    $s = scenario();

    expect($s['dlq'])->toHaveCount(1)
        ->and($s['dlq'][0])->toMatchArray([
            'consumer' => 'identity', 'error_class' => 'InvalidEvent', 'source_topic' => 'events',
            'source_partition' => $s['poison']['partition'], 'source_offset' => $s['poison']['offset'],
            'payload_encoding' => 'utf8', 'payload_truncated' => false,
        ])
        ->and(json_decode($s['dlq'][0]['payload'], true)['distinct_id'])->toBe(5)
        // Events behind it in the same partition were still handled: the committed offset passed it.
        ->and($s['first']['counts']['created'])->toBe(3);
});

it('is idempotent: a new group replaying every event changes nothing in Postgres', function () {
    $s = scenario();

    expect($s['replay']['exit'])->toBe(0, $s['replay']['stderr'])
        ->and($s['replay']['counts'])->not->toHaveKey('created')
        ->and($s['replay']['counts'])->not->toHaveKey('merged')
        ->and($s['replay']['counts'])->not->toHaveKey('attached')
        ->and($s['afterReplay'])->toBe($s['final']);
});

it('only re-sends rows that are already true after a replay, and dead-letters the poison message again with the same origin', function () {
    $s = scenario();
    $current = [];
    foreach ($s['final']['ids'] as $row) {
        $current[$row['distinct_id']] = [$row['version'], $row['person_uuid']];
    }

    // The topic is read partition by partition, so "the rows the replay added" are the multiset difference.
    $key = fn (array $r) => json_encode([$r['distinct_id'], $r['version'], $r['person_id']]);
    $before = array_count_values(array_map($key, $s['rows']));
    $extra = [];
    foreach ($s['rowsAfterReplay'] as $row) {
        if (($before[$key($row)] ?? 0) > 0) {
            $before[$key($row)]--;
        } else {
            $extra[] = $row;
        }
    }

    // The first event of a partition after an assignment is treated as a possible crash replay: the current rows of
    // its person are sent again. Each of them is a row Postgres still agrees with.
    expect($extra)->not->toBe([])
        ->and($s['replay']['counts'])->toHaveKey('reconciled');

    foreach ($extra as $row) {
        expect([$row['version'], $row['person_id']])->toBe($current[$row['distinct_id']]);
    }

    expect($s['dlqAfterReplay'])->toHaveCount(2)
        ->and($s['dlqAfterReplay'][1]['source_offset'])->toBe($s['poison']['offset'])
        ->and($s['dlqAfterReplay'][1]['source_partition'])->toBe($s['poison']['partition']);
});
