<?php

use App\Domain\Identity\IdentityKind;
use App\Domain\Identity\IdentityLockKey;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IdentityRace;

/*
 * Several resolvers at once, each a real child process with its own application and its own Postgres session
 * (tests/Support/identity_race_worker.php), released together from a barrier file. The tests would pass on a
 * resolver without locks only by luck, so the first two prove the locks are what is being waited on, and the races
 * repeat for several rounds.
 *
 * A deadlock would show as a worker error with SQLSTATE 40P01 after Postgres' deadlock_timeout (1 s), a unique
 * violation (23505) as the symptom of two creations racing, and a foreign key or "stale" failure as a lost
 * merge: every race asserts that NO worker reported an error at all.
 */

afterEach(fn () => IdentityRace::stop());

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->other = Project::factory()->create();
});

/** Wipes persons between the rounds of one test (projects stay). */
function resetPersons(): void
{
    DB::statement('truncate persons, person_distinct_ids restart identity cascade');
}

function seedSingles(Project $project, array $ids): void
{
    foreach ($ids as $id) {
        $personId = DB::table('persons')->insertGetId(['uuid' => (string) Str::uuid7(), 'project_id' => $project->id]);
        DB::table('person_distinct_ids')->insert(['project_id' => $project->id, 'person_id' => $personId, 'distinct_id' => $id]);
    }
}

/** @return list<list<string>> the distinct ids of each person of the project, sorted */
function personGroups(Project $project): array
{
    $groups = [];
    foreach (DB::table('person_distinct_ids')->where('project_id', $project->id)->orderBy('distinct_id')->get() as $row) {
        $groups[$row->person_id][] = $row->distinct_id;
    }
    $groups = array_values($groups);
    usort($groups, fn ($a, $b) => strcmp($a[0], $b[0]));

    return $groups;
}

function assertNoWorkerFailed(array $results): void
{
    foreach ($results as $label => $result) {
        expect($result)->not->toHaveKey('error', "worker {$label}: ".json_encode($result));
    }
}

function outcomesOf(array $results): array
{
    $outcomes = [];
    foreach ($results as $result) {
        foreach ($result['results'] as $one) {
            $outcomes[] = $one['outcome'];
        }
    }

    $counts = array_count_values($outcomes);
    ksort($counts);

    return $counts;
}

function assertConsistent(): void
{
    // Every person has distinct ids, every distinct id a person of its own project (the composite key), no orphans.
    expect(DB::scalar('select count(*) from persons p where not exists (select 1 from person_distinct_ids d where d.person_id = p.id)'))->toBe(0)
        ->and(DB::scalar('select count(*) from person_distinct_ids d where not exists (select 1 from persons p where p.id = d.person_id and p.project_id = d.project_id)'))->toBe(0);
}

it('makes a resolver wait while another transaction holds the lock of one of its ids, then finish when it ends', function () {
    $holder = DB::connectUsing('identity-holder', (array) config('database.connections.pgsql'), true);
    $holder->beginTransaction();

    try {
        // This session plays a resolver that is in the middle of its transaction on (project, "alice").
        $holder->select('select pg_advisory_xact_lock(?, ?)', IdentityLockKey::for($this->project->id, 'alice'));

        $workers = IdentityRace::start(['late' => [[$this->project->id, IdentityKind::Identify, 'bob', 'alice']]]);
        IdentityRace::release();
        IdentityRace::awaitAdvisoryWaiters(1);

        expect(IdentityRace::isRunning($workers[0]))->toBeTrue('the worker finished although the lock was held')
            ->and(DB::table('persons')->count())->toBe(0);

        $holder->commit();

        $result = IdentityRace::result($workers[0]);

        expect($result)->not->toHaveKey('error')
            ->and($result['results'][0]['outcome'])->toBe('created');
    } finally {
        if ($holder->transactionLevel() > 0) {
            $holder->rollBack();
        }
        DB::purge('identity-holder');
    }
});

it('does not make a resolver wait for the lock of the same distinct id in another project', function () {
    $holder = DB::connectUsing('identity-holder', (array) config('database.connections.pgsql'), true);
    $holder->beginTransaction();

    try {
        $holder->select('select pg_advisory_xact_lock(?, ?)', IdentityLockKey::for($this->project->id, 'alice'));

        $workers = IdentityRace::start([
            'blocked' => [[$this->project->id, IdentityKind::Identify, 'alice', 'anon']],
            'free' => [[$this->other->id, IdentityKind::Identify, 'alice', 'anon']],
        ]);
        IdentityRace::release();

        // The other project's resolver finishes while the first is still blocked on the held lock.
        $free = IdentityRace::result($workers[1]);
        expect($free)->not->toHaveKey('error')->and($free['results'][0]['outcome'])->toBe('created')
            ->and(IdentityRace::isRunning($workers[0]))->toBeTrue();

        IdentityRace::awaitAdvisoryWaiters(1);
        $holder->commit();

        expect(IdentityRace::result($workers[0])['results'][0]['outcome'])->toBe('created')
            ->and(DB::table('persons')->count())->toBe(2);
    } finally {
        if ($holder->transactionLevel() > 0) {
            $holder->rollBack();
        }
        DB::purge('identity-holder');
    }
});

it('makes four simultaneous identical identifies produce exactly one person, round after round', function () {
    foreach (range(1, 5) as $round) {
        resetPersons();
        $event = [[$this->project->id, IdentityKind::Identify, 'alice', 'anon-1']];

        $results = IdentityRace::run(['w1' => $event, 'w2' => $event, 'w3' => $event, 'w4' => $event]);

        assertNoWorkerFailed($results);
        expect(outcomesOf($results))->toBe(['created' => 1, 'noop' => 3], "round {$round}: ".json_encode($results))
            ->and(personGroups($this->project))->toBe([['alice', 'anon-1']])
            ->and(DB::table('persons')->count())->toBe(1);
        assertConsistent();
    }
});

it('does not deadlock when the same pair arrives in both orders at once', function () {
    foreach (range(1, 6) as $round) {
        resetPersons();
        $ab = [[$this->project->id, IdentityKind::Identify, 'alice', 'bob']];
        $ba = [[$this->project->id, IdentityKind::Identify, 'bob', 'alice']];

        $results = IdentityRace::run(['ab1' => $ab, 'ba1' => $ba, 'ab2' => $ab, 'ba2' => $ba]);

        assertNoWorkerFailed($results);
        expect(outcomesOf($results))->toBe(['created' => 1, 'noop' => 3], "round {$round}: ".json_encode($results))
            ->and(personGroups($this->project))->toBe([['alice', 'bob']]);
        assertConsistent();
    }
});

it('settles three overlapping identifies (A,B) (B,C) (C,A) into one person', function () {
    foreach (range(1, 5) as $round) {
        resetPersons();

        $results = IdentityRace::run([
            'ab' => [[$this->project->id, IdentityKind::Identify, 'a', 'b']],
            'bc' => [[$this->project->id, IdentityKind::Identify, 'b', 'c']],
            'ca' => [[$this->project->id, IdentityKind::Identify, 'c', 'a']],
        ]);

        assertNoWorkerFailed($results);
        expect(personGroups($this->project))->toBe([['a', 'b', 'c']], "round {$round}: ".json_encode($results))
            ->and(DB::table('persons')->count())->toBe(1);
        assertConsistent();
    }
});

it('merges three persons raced from every side into one, without an error or a lost id (the row lock and retry path)', function () {
    foreach (range(1, 6) as $round) {
        resetPersons();
        seedSingles($this->project, ['a', 'b', 'c']);

        $results = IdentityRace::run([
            'ab' => [[$this->project->id, IdentityKind::MergeDangerously, 'a', 'b']],
            'bc' => [[$this->project->id, IdentityKind::MergeDangerously, 'b', 'c']],
            'ca' => [[$this->project->id, IdentityKind::MergeDangerously, 'c', 'a']],
            'ac' => [[$this->project->id, IdentityKind::MergeDangerously, 'a', 'c']],
        ]);

        assertNoWorkerFailed($results);
        expect(personGroups($this->project))->toBe([['a', 'b', 'c']], "round {$round}: ".json_encode($results))
            ->and(DB::table('persons')->count())->toBe(1)
            // Two merges are needed to join three persons, and each id moves at most twice.
            ->and(outcomesOf($results)['merged'] ?? 0)->toBe(2)
            ->and((int) DB::table('person_distinct_ids')->max('version'))->toBeLessThanOrEqual(2);
        assertConsistent();
    }
});

it('collapses a ring of eight persons merged by eight simultaneous resolvers', function () {
    $ids = ['p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7', 'p8'];

    foreach (range(1, 3) as $round) {
        resetPersons();
        seedSingles($this->project, $ids);

        $workers = [];
        foreach ($ids as $index => $id) {
            $next = $ids[($index + 1) % 8];
            $workers["w{$index}"] = [[$this->project->id, IdentityKind::MergeDangerously, $id, $next]];
        }

        $results = IdentityRace::run($workers);

        assertNoWorkerFailed($results);
        expect(personGroups($this->project))->toBe([$ids], "round {$round}: ".json_encode($results))
            ->and(DB::table('persons')->count())->toBe(1)
            ->and(outcomesOf($results)['merged'] ?? 0)->toBe(7);
        assertConsistent();
    }
});

it('lets a worker apply a sequence of events while others race on the same ids', function () {
    resetPersons();
    seedSingles($this->project, ['a', 'b', 'c', 'd']);

    $results = IdentityRace::run([
        'one' => [
            [$this->project->id, IdentityKind::MergeDangerously, 'a', 'b'],
            [$this->project->id, IdentityKind::MergeDangerously, 'a', 'c'],
        ],
        'two' => [
            [$this->project->id, IdentityKind::MergeDangerously, 'd', 'c'],
            [$this->project->id, IdentityKind::MergeDangerously, 'd', 'a'],
        ],
    ]);

    assertNoWorkerFailed($results);
    expect(personGroups($this->project))->toBe([['a', 'b', 'c', 'd']])->and(DB::table('persons')->count())->toBe(1);
    assertConsistent();
});

it('keeps two projects racing on the same distinct ids apart', function () {
    foreach (range(1, 4) as $round) {
        resetPersons();
        $first = [[$this->project->id, IdentityKind::Identify, 'alice', 'anon-1']];
        $second = [[$this->other->id, IdentityKind::Identify, 'alice', 'anon-1']];

        $results = IdentityRace::run(['p1a' => $first, 'p2a' => $second, 'p1b' => $first, 'p2b' => $second]);

        assertNoWorkerFailed($results);
        expect(personGroups($this->project))->toBe([['alice', 'anon-1']])
            ->and(personGroups($this->other))->toBe([['alice', 'anon-1']])
            ->and(DB::table('persons')->count())->toBe(2)
            ->and(outcomesOf($results))->toBe(['created' => 2, 'noop' => 2], "round {$round}");
        assertConsistent();
    }
});

it('has a backstop behind the locks: the unique index refuses a second person for an id that two sessions both found free', function () {
    // The interleaving a resolver WITHOUT advisory locks would allow: both sessions saw no person for "alice", the first
    // writes and commits, the second writes next. The index turns that into an error instead of two persons; the locks
    // above turn it into a wait followed by a no-op. If this ever stops failing, the races above prove less than they say.
    $second = DB::connectUsing('identity-second', (array) config('database.connections.pgsql'), true);
    $project = $this->project->id;

    $second->beginTransaction();

    try {
        $first = DB::table('persons')->insertGetId(['uuid' => (string) Str::uuid7(), 'project_id' => $project]);
        DB::table('person_distinct_ids')->insert(['project_id' => $project, 'person_id' => $first, 'distinct_id' => 'alice']);

        $mine = $second->table('persons')->insertGetId(['uuid' => (string) Str::uuid7(), 'project_id' => $project]);

        expect(fn () => $second->table('person_distinct_ids')->insert(['project_id' => $project, 'person_id' => $mine, 'distinct_id' => 'alice']))
            ->toThrow(fn (QueryException $e) => expect($e->errorInfo[0])->toBe('23505'));
    } finally {
        $second->rollBack();
        DB::purge('identity-second');
    }
});
