<?php

use App\Jobs\PurgeProjectEvents;
use Tests\Support\ClickHouseIntegration as CH;

/*
 * The purge job against a real ClickHouse 25.8: the lightweight DELETE, its mutation and the isolation between
 * two projects cannot be tried with a fake. Opt-in (group "clickhouse", see ClickHouseTenantIsolationTest for how
 * to run it). Uses its own fixed project ids and removes whatever it left behind.
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

beforeEach(function () {
    $this->ch = CH::connect();
    $this->cleanup = function () {
        $this->ch->admin->execute('DELETE FROM posthog.events WHERE project_id IN ('.CH::PURGE_A.', '.CH::PURGE_B.')', settings: ['lightweight_deletes_sync' => 2]);
    };
    ($this->cleanup)();
});

afterEach(function () {
    ($this->cleanup)();
});

it('removes every row of the purged project, leaves the other project alone and is repeatable', function () {
    seedPurgeRows($this->ch, CH::PURGE_A, 40);
    seedPurgeRows($this->ch, CH::PURGE_A, 15); // a second part
    seedPurgeRows($this->ch, CH::PURGE_B, 25);
    expect(purgeCount($this->ch, CH::PURGE_A))->toBe(55)
        ->and(purgeCount($this->ch, CH::PURGE_B))->toBe(25);

    (new PurgeProjectEvents(CH::PURGE_A))->handle($this->ch->admin);

    expect(purgeCount($this->ch, CH::PURGE_A))->toBe(0)
        ->and(purgeCount($this->ch, CH::PURGE_B))->toBe(25);

    // Every mutation the purge created has finished (it waited for them), and none failed.
    $mutations = $this->ch->admin->select("SELECT count() AS pending FROM system.mutations WHERE database = 'posthog' AND table = 'events' AND is_done = 0");
    expect((int) $mutations[0]['pending'])->toBe(0);

    // Idempotent: a second pass over nothing succeeds and still spares the other project.
    (new PurgeProjectEvents(CH::PURGE_A, pass: 2))->handle($this->ch->admin);
    expect(purgeCount($this->ch, CH::PURGE_B))->toBe(25);
});

it('removes events that land after the first pass when the second pass runs', function () {
    seedPurgeRows($this->ch, CH::PURGE_A, 10);
    (new PurgeProjectEvents(CH::PURGE_A))->handle($this->ch->admin);

    seedPurgeRows($this->ch, CH::PURGE_A, 5); // an in-flight Kafka message arriving late
    expect(purgeCount($this->ch, CH::PURGE_A))->toBe(5);

    (new PurgeProjectEvents(CH::PURGE_A, pass: 2))->handle($this->ch->admin);

    expect(purgeCount($this->ch, CH::PURGE_A))->toBe(0);
});
