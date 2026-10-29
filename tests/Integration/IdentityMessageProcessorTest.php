<?php

use App\Domain\Identity\IdentityKind;
use App\Domain\Identity\IdentityLockKey;
use App\Domain\Identity\IdentityResolver;
use App\Infrastructure\Kafka\Identity\ConsumedMessage;
use App\Infrastructure\Kafka\Identity\Disposition;
use App\Infrastructure\Kafka\Identity\IdentityMessageProcessor;
use App\Infrastructure\Kafka\Identity\RetryableFailure;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * IdentityMessageProcessor with the real resolver on a real Postgres (phpunit.pgsql.xml): what an event of the events
 * topic turns into, including the failures. The loop around it is tests/Unit/IdentityConsumerTest.php.
 */

function identityMessage(Project $project, IdentityKind $kind, string $distinctId, ?string $other, string $timestamp = '2026-10-29 11:59:59.123'): ConsumedMessage
{
    $properties = $other === null ? [] : [$kind->counterpartProperty() => $other];

    return new ConsumedMessage('events', 0, 1, "{$project->id}:{$distinctId}", json_encode([
        'uuid' => (string) Str::uuid7(), 'project_id' => $project->id, 'event' => $kind->value, 'distinct_id' => $distinctId,
        'properties' => json_encode((object) $properties), 'timestamp' => $timestamp,
    ]));
}

function processorFor(int $lockTimeoutMs = 10_000, ?Closure $reset = null): IdentityMessageProcessor
{
    return new IdentityMessageProcessor(
        new IdentityResolver($lockTimeoutMs),
        $reset ?? fn () => null,
        fn () => CarbonImmutable::parse('2026-10-29 12:34:56.789', 'UTC'),
    );
}

beforeEach(fn () => $this->project = Project::factory()->create());

it('turns a created person into one row per id at version 0, stamped with the time of the event', function () {
    $result = processorFor()->process(identityMessage($this->project, IdentityKind::Identify, 'alice', 'anon-1'));
    $uuid = DB::table('persons')->value('uuid');

    expect($result->disposition)->toBe(Disposition::Created)
        ->and($result->rows)->toBe([
            ['project_id' => $this->project->id, 'distinct_id' => 'alice', 'person_id' => $uuid, 'version' => 0, 'is_deleted' => 0, 'created_at' => '2026-10-29 11:59:59.123'],
            ['project_id' => $this->project->id, 'distinct_id' => 'anon-1', 'person_id' => $uuid, 'version' => 0, 'is_deleted' => 0, 'created_at' => '2026-10-29 11:59:59.123'],
        ]);
});

it('falls back to the time of the consumer when the event carries no usable timestamp', function (string $timestamp) {
    $result = processorFor()->process(identityMessage($this->project, IdentityKind::Identify, 'alice', 'anon-1', $timestamp));

    expect($result->rows[0]['created_at'])->toBe('2026-10-29 12:34:56.789');
})->with(['', 'yesterday', '2026-10-29T11:59:59Z']);

it('reports an attach with only the new id, and a merge with every id that moved', function () {
    $processor = processorFor();
    $processor->process(identityMessage($this->project, IdentityKind::Identify, 'alice', 'anon-1'));
    $attached = $processor->process(identityMessage($this->project, IdentityKind::CreateAlias, 'alice', 'alias-1'));

    $processor->process(identityMessage($this->project, IdentityKind::MergeDangerously, 'bob', 'bob-2'));
    $merged = $processor->process(identityMessage($this->project, IdentityKind::MergeDangerously, 'alice', 'bob'));

    expect($attached->disposition)->toBe(Disposition::Attached)
        ->and(array_column($attached->rows, 'distinct_id'))->toBe(['alias-1'])
        ->and($merged->disposition)->toBe(Disposition::Merged)
        ->and(array_map(fn ($r) => [$r['distinct_id'], $r['version']], $merged->rows))->toBe([['bob', 1], ['bob-2', 1]]);
});

it('sends nothing for a repeated event, and for a refusal and a rejected id', function () {
    $processor = processorFor();
    $processor->process(identityMessage($this->project, IdentityKind::Identify, 'alice', 'anon-1'));

    $again = $processor->process(identityMessage($this->project, IdentityKind::Identify, 'alice', 'anon-1'));
    $rejected = $processor->process(identityMessage($this->project, IdentityKind::Identify, 'alice', 'undefined'));

    expect($again->disposition)->toBe(Disposition::Noop)->and($again->rows)->toBe([])
        ->and($rejected->disposition)->toBe(Disposition::Rejected)->and($rejected->rows)->toBe([]);
});

describe('a replay after a crash', function () {
    it('re-sends the CURRENT rows of the person, also the ids a merge moved that the event does not name, when asked to reconcile', function () {
        $processor = processorFor();
        $processor->process(identityMessage($this->project, IdentityKind::MergeDangerously, 'bob', 'bob-2'));
        $processor->process(identityMessage($this->project, IdentityKind::MergeDangerously, 'bob', 'bob-3'));
        $merge = identityMessage($this->project, IdentityKind::MergeDangerously, 'alice', 'bob');
        $processor->process(identityMessage($this->project, IdentityKind::MergeDangerously, 'alice', 'alice-2'));
        $first = $processor->process($merge);
        // The first run committed to Postgres and died before its rows reached Kafka: the replay finds a no-op.

        $plain = $processor->process($merge);
        $replay = $processor->process($merge, reconcile: true);

        expect($first->disposition)->toBe(Disposition::Merged)
            ->and($plain->disposition)->toBe(Disposition::Noop)->and($plain->rows)->toBe([])
            ->and($replay->disposition)->toBe(Disposition::Reconciled)
            ->and(array_map(fn ($r) => [$r['distinct_id'], $r['version']], $replay->rows))
            ->toBe([['alice', 0], ['alice-2', 0], ['bob', 1], ['bob-2', 1], ['bob-3', 1]])
            ->and(array_unique(array_column($replay->rows, 'person_id')))->toHaveCount(1)
            // The rows the first run sent are among them, identical.
            ->and(array_values(array_filter($replay->rows, fn ($r) => $r['distinct_id'] === 'bob')))->toBe(array_values(array_filter($first->rows, fn ($r) => $r['distinct_id'] === 'bob')));
    });

    it('does not reconcile what is not a same-person no-op: a refused merge and a rejected id stay silent', function () {
        $processor = processorFor();
        $processor->process(identityMessage($this->project, IdentityKind::Identify, 'alice', 'anon-1'));
        $processor->process(identityMessage($this->project, IdentityKind::Identify, 'bob', 'anon-2'));

        $refused = $processor->process(identityMessage($this->project, IdentityKind::Identify, 'alice', 'bob'), reconcile: true);
        $rejected = $processor->process(identityMessage($this->project, IdentityKind::Identify, 'alice', 'null'), reconcile: true);

        expect($refused->disposition)->toBe(Disposition::Noop)->and($refused->rows)->toBe([])
            ->and($rejected->disposition)->toBe(Disposition::Rejected);
    });
});

describe('failures', function () {
    it('skips an event of a project that was deleted meanwhile, and writes nothing', function () {
        $message = identityMessage($this->project, IdentityKind::Identify, 'alice', 'anon-1');
        $this->project->delete();

        $result = processorFor()->process($message);

        expect($result->disposition)->toBe(Disposition::ProjectGone)
            ->and(DB::table('persons')->count())->toBe(0)
            ->and(DB::table('person_distinct_ids')->count())->toBe(0);
    });

    it('raises RetryableFailure on a lock timeout, drops the connection, and writes nothing', function () {
        $holder = DB::connectUsing('lock-holder', (array) config('database.connections.pgsql'), true);
        $holder->beginTransaction();
        $resets = 0;
        $processor = processorFor(200, function () use (&$resets) {
            $resets++;
        });

        try {
            $holder->select('select pg_advisory_xact_lock(?, ?)', IdentityLockKey::for($this->project->id, 'alice'));

            expect(fn () => $processor->process(identityMessage($this->project, IdentityKind::Identify, 'alice', 'anon-1')))
                ->toThrow(RetryableFailure::class, '55P03');
        } finally {
            $holder->rollBack();
            DB::purge('lock-holder');
        }

        expect($resets)->toBe(1)->and(DB::table('persons')->count())->toBe(0);
    });

    it('succeeds when the same event is tried again after the lock was released', function () {
        $processor = processorFor();

        expect($processor->process(identityMessage($this->project, IdentityKind::Identify, 'alice', 'anon-1'))->disposition)->toBe(Disposition::Created);
    });

    it('dead-letters an error no retry cures, with its class, instead of retrying it for ever', function () {
        DB::statement('alter table person_distinct_ids rename to person_distinct_ids_away');

        try {
            $result = processorFor()->process(identityMessage($this->project, IdentityKind::Identify, 'alice', 'anon-1'));
        } finally {
            DB::statement('alter table person_distinct_ids_away rename to person_distinct_ids');
        }

        expect($result->disposition)->toBe(Disposition::Poison)
            ->and($result->errorClass)->toBe(QueryException::class)
            ->and($result->errorMessage)->toContain('42P01');
    });
});
