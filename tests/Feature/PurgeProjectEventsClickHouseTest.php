<?php

use App\Jobs\PurgeProjectEvents;
use Tests\Support\ClickHouseIntegration as CH;

/*
 * The purge job against a real ClickHouse 25.8: the asynchronous lightweight DELETE, its mutation and the isolation
 * between two projects cannot be tried with a fake. Opt-in (group "clickhouse", see ClickHouseTenantIsolationTest
 * for how to run it). Uses its own fixed project ids and checks only the mutations that name them, never the
 * global mutation state of the server.
 */

pest()->group('clickhouse');

function seedPurgeRows(CH $ch, int $project, int $rows): void
{
    $ch->admin->execute(
        'INSERT INTO posthog.events (uuid, project_id, event, distinct_id, properties, timestamp) '
        ."SELECT generateUUIDv4(), {$project}, 'purge_it', concat('d', toString(number % 4)), '{}', now64(3) - toIntervalMinute(number) FROM numbers({$rows})",
    );
}

function purgeCount(CH $ch, int $project): int
{
    return (int) $ch->admin->select("SELECT count() AS n FROM posthog.events WHERE project_id = {$project}")[0]['n'];
}

/** Unfinished mutations of events that name the project id (the same match the job uses). */
function pendingMutationsOf(CH $ch, int $project): int
{
    return (int) $ch->admin->select(
        "SELECT count() AS n FROM system.mutations WHERE database = 'posthog' AND table = 'events' AND is_done = 0 AND match(command, {pattern:String})",
        ['pattern' => '(^|[^0-9])'.$project.'([^0-9]|$)'],
    )[0]['n'];
}

/** Runs the job like the worker does: a run may release itself, so run again until it is done (bounded). */
function runPurge(CH $ch, int $project, int $pass = 1): int
{
    $runs = 0;
    do {
        $job = (new PurgeProjectEvents($project, $pass))->withFakeQueueInteractions();
        $job->handle($ch->admin);
        $runs++;
    } while (purgeCount($ch, $project) > 0 && $runs < 5);

    return $runs;
}

beforeEach(function () {
    $this->ch = CH::connect();
    $this->ch->deleteRowsOf([CH::PURGE_A, CH::PURGE_B]);
});

afterEach(function () {
    $this->ch->deleteRowsOf([CH::PURGE_A, CH::PURGE_B]);
});

it('removes every row of the purged project, leaves the other project alone and is repeatable', function () {
    seedPurgeRows($this->ch, CH::PURGE_A, 40);
    seedPurgeRows($this->ch, CH::PURGE_A, 15); // a second part
    seedPurgeRows($this->ch, CH::PURGE_B, 25);
    expect(purgeCount($this->ch, CH::PURGE_A))->toBe(55)
        ->and(purgeCount($this->ch, CH::PURGE_B))->toBe(25);

    runPurge($this->ch, CH::PURGE_A);

    expect(purgeCount($this->ch, CH::PURGE_A))->toBe(0)
        ->and(purgeCount($this->ch, CH::PURGE_B))->toBe(25);

    // The mutation this purge created has finished by now; the other project never had one.
    $deadline = microtime(true) + 30;
    while (pendingMutationsOf($this->ch, CH::PURGE_A) > 0 && microtime(true) < $deadline) {
        usleep(200_000);
    }
    expect(pendingMutationsOf($this->ch, CH::PURGE_A))->toBe(0)
        ->and(pendingMutationsOf($this->ch, CH::PURGE_B))->toBe(0);

    // Idempotent: a second pass over nothing succeeds, sends no DELETE and still spares the other project.
    (new PurgeProjectEvents(CH::PURGE_A, pass: 2))->withFakeQueueInteractions()->handle($this->ch->admin);
    expect(purgeCount($this->ch, CH::PURGE_B))->toBe(25);
});

/** Ids of every mutation of events that names the project id, finished or not (ClickHouse prunes old finished ones, so ids are compared, never counts). */
function mutationIdsOf(CH $ch, int $project): array
{
    return array_column($ch->admin->select(
        "SELECT mutation_id FROM system.mutations WHERE database = 'posthog' AND table = 'events' AND match(command, {pattern:String})",
        ['pattern' => '(^|[^0-9])'.$project.'([^0-9]|$)'],
    ), 'mutation_id');
}

it('does not stack a second mutation while the first is pending', function () {
    seedPurgeRows($this->ch, CH::PURGE_A, 30);
    $before = mutationIdsOf($this->ch, CH::PURGE_A);

    runPurge($this->ch, CH::PURGE_A);

    $created = array_diff(mutationIdsOf($this->ch, CH::PURGE_A), $before);
    expect(purgeCount($this->ch, CH::PURGE_A))->toBe(0)
        ->and($created)->toHaveCount(1);
});

it('removes events that land after the first pass when the second pass runs', function () {
    seedPurgeRows($this->ch, CH::PURGE_A, 10);
    runPurge($this->ch, CH::PURGE_A);

    seedPurgeRows($this->ch, CH::PURGE_A, 5); // an in-flight Kafka message arriving late
    expect(purgeCount($this->ch, CH::PURGE_A))->toBe(5);

    runPurge($this->ch, CH::PURGE_A, pass: 2);

    expect(purgeCount($this->ch, CH::PURGE_A))->toBe(0);
});
