<?php

use App\Domain\Identity\DistinctIdProblem;
use App\Domain\Identity\DistinctIdRules;
use App\Domain\Identity\IdentityEvent;
use App\Domain\Identity\IdentityKind;
use App\Domain\Identity\IdentityLockKey;
use App\Domain\Identity\IdentityNoopReason;
use App\Domain\Identity\IdentityOutcome;
use App\Domain\Identity\IdentityResolver;
use App\Domain\Identity\IdentityResult;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The identity rules against a real Postgres (phpunit.pgsql.xml), one resolver in one process. The concurrency of
 * several resolvers is IdentityResolverRaceTest. Every test starts from empty tables (DatabaseTruncation).
 */

function resolveIdentity(Project $project, IdentityKind $kind, string $distinctId, string $other): IdentityResult
{
    return app(IdentityResolver::class)->apply(new IdentityEvent($project->id, $kind, $distinctId, $other));
}

function identifyAs(Project $project, string $distinctId, string $anon): IdentityResult
{
    return resolveIdentity($project, IdentityKind::Identify, $distinctId, $anon);
}

function aliasAs(Project $project, string $distinctId, string $alias): IdentityResult
{
    return resolveIdentity($project, IdentityKind::CreateAlias, $distinctId, $alias);
}

function mergeAs(Project $project, string $distinctId, string $other): IdentityResult
{
    return resolveIdentity($project, IdentityKind::MergeDangerously, $distinctId, $other);
}

/** A person with the given distinct ids, written directly (an unidentified one models an anonymous visitor's person). */
function seedPerson(Project $project, array $distinctIds, bool $identified = false, array $properties = [], ?string $createdAt = null): int
{
    $personId = DB::table('persons')->insertGetId([
        'uuid' => (string) Str::uuid7(),
        'project_id' => $project->id,
        'properties' => json_encode((object) $properties),
        'is_identified' => $identified,
        'created_at' => $createdAt ?? now(),
        'updated_at' => now(),
    ]);

    foreach ($distinctIds as $distinctId) {
        DB::table('person_distinct_ids')->insert([
            'project_id' => $project->id, 'person_id' => $personId, 'distinct_id' => $distinctId, 'version' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $personId;
}

/** @return array<int, list<string>> person id => its distinct ids, sorted, for one project */
function personsOfProject(Project $project): array
{
    $groups = [];

    foreach (DB::table('person_distinct_ids')->where('project_id', $project->id)->orderBy('distinct_id')->get() as $row) {
        $groups[$row->person_id][] = $row->distinct_id;
    }

    ksort($groups);

    return $groups;
}

/** The whole identity state, to prove that an event changed nothing. */
function identitySnapshot(): array
{
    return [
        DB::table('persons')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        DB::table('person_distinct_ids')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
}

function versionOf(Project $project, string $distinctId): int
{
    return (int) DB::table('person_distinct_ids')->where('project_id', $project->id)->where('distinct_id', $distinctId)->value('version');
}

beforeEach(fn () => $this->project = Project::factory()->create());

describe('creating and attaching', function () {
    it('creates one identified person with both ids when neither is known ($identify A, anon B)', function () {
        $result = identifyAs($this->project, 'alice', 'anon-1');

        expect($result->outcome)->toBe(IdentityOutcome::Created)
            ->and($result->changedAnything())->toBeTrue()
            ->and($result->projectId)->toBe($this->project->id)
            ->and(Str::isUuid($result->personUuid))->toBeTrue()
            ->and($result->deletedPersonId)->toBeNull()
            ->and(array_map(fn ($c) => [$c->distinctId, $c->personId, $c->personUuid, $c->version], $result->changes))
            ->toBe([['alice', $result->personId, $result->personUuid, 0], ['anon-1', $result->personId, $result->personUuid, 0]])
            ->and(personsOfProject($this->project))->toBe([$result->personId => ['alice', 'anon-1']]);

        $person = DB::table('persons')->find($result->personId);

        expect((bool) $person->is_identified)->toBeTrue()
            ->and($person->uuid)->toBe($result->personUuid)
            ->and(json_decode($person->properties, true))->toBe([]);
    });

    it('attaches the other id to the person of the event\'s own id, and identifies it', function () {
        $personId = seedPerson($this->project, ['alice']);

        $result = identifyAs($this->project, 'alice', 'anon-1');

        expect($result->outcome)->toBe(IdentityOutcome::Attached)
            ->and($result->personId)->toBe($personId)
            ->and(array_map(fn ($c) => [$c->distinctId, $c->version], $result->changes))->toBe([['anon-1', 0]])
            ->and(personsOfProject($this->project))->toBe([$personId => ['alice', 'anon-1']])
            ->and((bool) DB::table('persons')->find($personId)->is_identified)->toBeTrue();
    });

    it('attaches the event\'s own id to the person of the other id', function () {
        $personId = seedPerson($this->project, ['anon-1']);

        $result = identifyAs($this->project, 'alice', 'anon-1');

        expect($result->outcome)->toBe(IdentityOutcome::Attached)
            ->and($result->personId)->toBe($personId)
            ->and(array_map(fn ($c) => $c->distinctId, $result->changes))->toBe(['alice'])
            ->and(personsOfProject($this->project))->toBe([$personId => ['alice', 'anon-1']])
            ->and((bool) DB::table('persons')->find($personId)->is_identified)->toBeTrue();
    });

    it('attaches to an already identified person without touching its other fields', function () {
        $personId = seedPerson($this->project, ['alice'], identified: true, properties: ['plan' => 'pro']);
        $before = DB::table('persons')->find($personId);

        identifyAs($this->project, 'alice', 'anon-1');

        $after = DB::table('persons')->find($personId);
        expect($after->properties)->toBe($before->properties)->and($after->uuid)->toBe($before->uuid);
    });

    it('creates the person of an alias and of a dangerous merge the same way, but NOT identified (only $identify identifies)', function (IdentityKind $kind) {
        $result = resolveIdentity($this->project, $kind, 'alice', 'bob');

        expect($result->outcome)->toBe(IdentityOutcome::Created)
            ->and(personsOfProject($this->project))->toBe([$result->personId => ['alice', 'bob']])
            ->and((bool) DB::table('persons')->find($result->personId)->is_identified)->toBeFalse();
    })->with([IdentityKind::CreateAlias, IdentityKind::MergeDangerously]);

    it('attaching through an alias or a dangerous merge keeps the flag the person had', function (IdentityKind $kind, bool $identified) {
        $personId = seedPerson($this->project, ['alice'], identified: $identified);

        $result = resolveIdentity($this->project, $kind, 'alice', 'bob');

        expect($result->outcome)->toBe(IdentityOutcome::Attached)
            ->and((bool) DB::table('persons')->find($personId)->is_identified)->toBe($identified);
    })->with([IdentityKind::CreateAlias, IdentityKind::MergeDangerously])->with([true, false]);
});

describe('re-emitting the current rows (currentRows)', function () {
    function rowsOf(array $changes): array
    {
        return array_map(fn ($c) => [$c->distinctId, $c->personId, $c->personUuid, $c->version], $changes);
    }

    it('returns the same rows the first run reported, for a created person', function () {
        $first = identifyAs($this->project, 'alice', 'anon-1');

        $again = app(IdentityResolver::class)->currentRows(new IdentityEvent($this->project->id, IdentityKind::Identify, 'alice', 'anon-1'));

        expect(rowsOf($again))->toBe(rowsOf($first->changes));
    });

    it('returns EVERY id of a merged person with its current version, also the ones the event does not name', function () {
        seedPerson($this->project, ['alice']);
        seedPerson($this->project, ['anon-1', 'anon-2', 'anon-3']);
        $merged = identifyAs($this->project, 'alice', 'anon-1');

        $rows = app(IdentityResolver::class)->currentRows(new IdentityEvent($this->project->id, IdentityKind::Identify, 'alice', 'anon-1'));

        expect(rowsOf($rows))->toBe([
            ['alice', $merged->personId, $merged->personUuid, 0],
            ['anon-1', $merged->personId, $merged->personUuid, 1],
            ['anon-2', $merged->personId, $merged->personUuid, 1],
            ['anon-3', $merged->personId, $merged->personUuid, 1],
        ]);
    });

    it('returns the rows of both persons when the ids are still apart, nothing for unknown ids, and stays inside the project', function () {
        $other = Project::factory()->create();
        seedPerson($this->project, ['alice']);
        seedPerson($this->project, ['bob']);
        seedPerson($other, ['alice', 'bob']);
        $resolver = app(IdentityResolver::class);

        $apart = $resolver->currentRows(new IdentityEvent($this->project->id, IdentityKind::Identify, 'alice', 'bob'));
        $unknown = $resolver->currentRows(new IdentityEvent($this->project->id, IdentityKind::Identify, 'nobody', 'no-one'));

        expect(array_column(rowsOf($apart), 0))->toBe(['alice', 'bob'])
            ->and(array_unique(array_map(fn ($c) => $c->projectId, $apart)))->toBe([$this->project->id])
            ->and($unknown)->toBe([]);
    });

    it('honours the cap and writes nothing', function () {
        seedPerson($this->project, ['a', 'b', 'c', 'd']);
        $snapshot = identitySnapshot();

        $rows = app(IdentityResolver::class)->currentRows(new IdentityEvent($this->project->id, IdentityKind::Identify, 'a', 'b'), limit: 3);

        expect($rows)->toHaveCount(3)->and(identitySnapshot())->toBe($snapshot);
    });
});

describe('being idempotent', function () {
    it('does nothing the second time, and nothing for the reverse event (A->B then B->A)', function () {
        $first = identifyAs($this->project, 'alice', 'anon-1');
        $snapshot = identitySnapshot();

        $again = identifyAs($this->project, 'alice', 'anon-1');
        $reverse = identifyAs($this->project, 'anon-1', 'alice');

        expect($first->outcome)->toBe(IdentityOutcome::Created)
            ->and($again->outcome)->toBe(IdentityOutcome::Noop)
            ->and($again->reason)->toBe(IdentityNoopReason::SamePerson)
            ->and($again->changedAnything())->toBeFalse()
            ->and($again->changes)->toBe([])
            ->and($again->personId)->toBe($first->personId)
            ->and($reverse->outcome)->toBe(IdentityOutcome::Noop)
            ->and($reverse->reason)->toBe(IdentityNoopReason::SamePerson)
            ->and(identitySnapshot())->toBe($snapshot);
    });

    it('repeats a merge without a second merge: the version of a moved id goes up once', function () {
        seedPerson($this->project, ['alice']);
        seedPerson($this->project, ['anon-1']);

        $merged = identifyAs($this->project, 'alice', 'anon-1');
        $snapshot = identitySnapshot();
        $again = identifyAs($this->project, 'alice', 'anon-1');

        expect($merged->outcome)->toBe(IdentityOutcome::Merged)
            ->and($again->outcome)->toBe(IdentityOutcome::Noop)
            ->and(versionOf($this->project, 'anon-1'))->toBe(1)
            ->and(identitySnapshot())->toBe($snapshot);
    });

    it('does nothing for an event whose two ids are the same id, and creates no person', function (IdentityKind $kind) {
        $result = resolveIdentity($this->project, $kind, 'alice', 'alice');

        expect($result->outcome)->toBe(IdentityOutcome::Noop)->and($result->reason)->toBe(IdentityNoopReason::SameDistinctId)
            ->and(DB::table('persons')->count())->toBe(0);
    })->with(IdentityKind::cases());
});

describe('merging', function () {
    it('merges the anonymous person into the identified one: ids move with a new version, properties merge, the source is deleted', function () {
        $target = seedPerson($this->project, ['alice', 'alice-2'], identified: true, properties: ['plan' => 'pro', 'nested' => ['x' => 1]], createdAt: '2026-02-01 00:00:00');
        $source = seedPerson($this->project, ['anon-1', 'anon-2'], identified: false, properties: ['plan' => 'free', 'city' => 'Ankara', 'nested' => ['y' => 2]], createdAt: '2026-01-01 00:00:00');
        $sourceUuid = DB::table('persons')->where('id', $source)->value('uuid');
        $targetUuid = DB::table('persons')->where('id', $target)->value('uuid');

        $result = identifyAs($this->project, 'alice', 'anon-1');

        expect($result->outcome)->toBe(IdentityOutcome::Merged)
            ->and($result->personId)->toBe($target)
            ->and($result->personUuid)->toBe($targetUuid)
            ->and($result->deletedPersonId)->toBe($source)
            ->and($result->deletedPersonUuid)->toBe($sourceUuid)
            // Only the moved rows are reported (the target's own ids did not change), by distinct id.
            ->and(array_map(fn ($c) => [$c->distinctId, $c->personId, $c->personUuid, $c->version], $result->changes))
            ->toBe([['anon-1', $target, $targetUuid, 1], ['anon-2', $target, $targetUuid, 1]])
            ->and(personsOfProject($this->project))->toBe([$target => ['alice', 'alice-2', 'anon-1', 'anon-2']])
            ->and(DB::table('persons')->pluck('id')->all())->toBe([$target])
            ->and(versionOf($this->project, 'alice'))->toBe(0);

        $person = DB::table('persons')->find($target);

        // The target wins a key both have; the merge is shallow (jsonb ||), so "nested" is the target's object.
        expect(json_decode($person->properties, true))->toEqual(['plan' => 'pro', 'nested' => ['x' => 1], 'city' => 'Ankara'])
            ->and((bool) $person->is_identified)->toBeTrue()
            ->and($person->created_at)->toStartWith('2026-01-01');
    });

    it('marks the surviving person identified for $identify and leaves its flag alone for an alias or a dangerous merge', function (IdentityKind $kind, bool $targetIdentified, bool $sourceIdentified, bool $expected) {
        seedPerson($this->project, ['alice'], identified: $targetIdentified);
        seedPerson($this->project, ['bob'], identified: $sourceIdentified);

        $result = resolveIdentity($this->project, $kind, 'alice', 'bob');

        expect($result->outcome)->toBe(IdentityOutcome::Merged)
            ->and((bool) DB::table('persons')->find($result->personId)->is_identified)->toBe($expected);
    })->with([
        '$identify, target not identified' => [IdentityKind::Identify, false, false, true],
        '$create_alias, neither identified' => [IdentityKind::CreateAlias, false, false, false],
        '$create_alias, target identified' => [IdentityKind::CreateAlias, true, false, true],
        '$create_alias, only the source identified: its flag is not carried over' => [IdentityKind::CreateAlias, false, true, false],
        '$merge_dangerously, neither identified' => [IdentityKind::MergeDangerously, false, false, false],
        '$merge_dangerously, target identified' => [IdentityKind::MergeDangerously, true, true, true],
        '$merge_dangerously, only the source identified' => [IdentityKind::MergeDangerously, false, true, false],
    ]);

    it('lets the target\'s properties win in both directions of the same pair', function () {
        seedPerson($this->project, ['a'], properties: ['k' => 'from-a']);
        seedPerson($this->project, ['b'], properties: ['k' => 'from-b']);

        mergeAs($this->project, 'b', 'a');

        expect(json_decode(DB::table('persons')->value('properties'), true))->toBe(['k' => 'from-b']);
    });

    it('keeps the target (the event\'s own id) as the survivor, whichever person is older', function () {
        $older = seedPerson($this->project, ['older'], createdAt: '2020-01-01 00:00:00');
        $newer = seedPerson($this->project, ['newer'], createdAt: '2026-01-01 00:00:00');

        $result = mergeAs($this->project, 'newer', 'older');

        expect($result->personId)->toBe($newer)->and($result->deletedPersonId)->toBe($older)
            ->and(DB::table('persons')->value('created_at'))->toStartWith('2020-01-01');
    });

    it('raises the version again when an id is moved a second time', function () {
        seedPerson($this->project, ['a']);
        seedPerson($this->project, ['b']);
        seedPerson($this->project, ['c']);

        mergeAs($this->project, 'a', 'b');
        mergeAs($this->project, 'c', 'a');

        expect(versionOf($this->project, 'b'))->toBe(2)
            ->and(versionOf($this->project, 'a'))->toBe(1)
            ->and(versionOf($this->project, 'c'))->toBe(0)
            ->and(count(personsOfProject($this->project)))->toBe(1);
    });
});

describe('the identified rules', function () {
    it('$identify merges when the anonymous (source) person is not identified, whatever the target is', function (bool $targetIdentified) {
        seedPerson($this->project, ['alice'], identified: $targetIdentified);
        seedPerson($this->project, ['anon-1'], identified: false);

        expect(identifyAs($this->project, 'alice', 'anon-1')->outcome)->toBe(IdentityOutcome::Merged);
    })->with([true, false]);

    it('$identify refuses to merge a source person that is already identified, and changes nothing', function (bool $targetIdentified) {
        seedPerson($this->project, ['alice'], identified: $targetIdentified);
        seedPerson($this->project, ['bob'], identified: true);
        $snapshot = identitySnapshot();

        $result = identifyAs($this->project, 'alice', 'bob');

        expect($result->outcome)->toBe(IdentityOutcome::Noop)
            ->and($result->reason)->toBe(IdentityNoopReason::SourceAlreadyIdentified)
            ->and($result->changes)->toBe([])
            ->and(identitySnapshot())->toBe($snapshot);
    })->with([true, false]);

    it('$create_alias merges unless BOTH persons are identified', function (bool $targetIdentified, bool $sourceIdentified, bool $merges) {
        seedPerson($this->project, ['alice'], identified: $targetIdentified);
        seedPerson($this->project, ['bob'], identified: $sourceIdentified);

        $result = aliasAs($this->project, 'alice', 'bob');

        expect($result->outcome)->toBe($merges ? IdentityOutcome::Merged : IdentityOutcome::Noop)
            ->and($result->reason)->toBe($merges ? null : IdentityNoopReason::BothAlreadyIdentified)
            ->and(count(personsOfProject($this->project)))->toBe($merges ? 1 : 2);
    })->with([
        'neither identified' => [false, false, true],
        'only the target identified' => [true, false, true],
        'only the source identified' => [false, true, true],
        'both identified' => [true, true, false],
    ]);

    it('$merge_dangerously merges persons that are both identified', function () {
        seedPerson($this->project, ['alice'], identified: true);
        seedPerson($this->project, ['bob'], identified: true);

        $result = mergeAs($this->project, 'alice', 'bob');

        expect($result->outcome)->toBe(IdentityOutcome::Merged)->and(count(personsOfProject($this->project)))->toBe(1);
    });

    it('lets an identified person be merged by $merge_dangerously after $identify refused it', function () {
        seedPerson($this->project, ['alice'], identified: true);
        seedPerson($this->project, ['bob'], identified: true);

        expect(identifyAs($this->project, 'alice', 'bob')->outcome)->toBe(IdentityOutcome::Noop)
            ->and(count(personsOfProject($this->project)))->toBe(2)
            ->and(mergeAs($this->project, 'alice', 'bob')->outcome)->toBe(IdentityOutcome::Merged)
            ->and(count(personsOfProject($this->project)))->toBe(1);
    });
});

describe('chains', function () {
    it('grows one person along a chain of identifies and ends the same however the chain is walked back', function () {
        identifyAs($this->project, 'a', 'b');
        identifyAs($this->project, 'b', 'c');
        identifyAs($this->project, 'c', 'd');
        $reverse = [identifyAs($this->project, 'd', 'a'), identifyAs($this->project, 'c', 'a'), identifyAs($this->project, 'b', 'd')];

        expect(array_values(personsOfProject($this->project)))->toBe([['a', 'b', 'c', 'd']])
            ->and(array_map(fn ($r) => $r->outcome, $reverse))->toBe([IdentityOutcome::Noop, IdentityOutcome::Noop, IdentityOutcome::Noop])
            ->and(DB::table('persons')->count())->toBe(1);
    });

    it('collapses a chain of merges into one person with every id', function () {
        foreach (['a', 'b', 'c', 'd', 'e'] as $id) {
            seedPerson($this->project, [$id]);
        }

        // Each event merges the next person into the one that holds the previous id.
        foreach ([['a', 'b'], ['b', 'c'], ['c', 'd'], ['d', 'e']] as [$x, $y]) {
            expect(mergeAs($this->project, $x, $y)->outcome)->toBe(IdentityOutcome::Merged);
        }

        expect(array_values(personsOfProject($this->project)))->toBe([['a', 'b', 'c', 'd', 'e']])
            ->and(DB::table('persons')->count())->toBe(1);
    });

    it('converges to the same grouping whatever the order of the merge events', function () {
        $pairs = [['a', 'b'], ['b', 'c'], ['d', 'e'], ['c', 'e'], ['f', 'a']];
        $groupings = [];

        foreach ([0, 1, 2, 3] as $permutation) {
            DB::table('person_distinct_ids')->delete();
            DB::table('persons')->delete();

            foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g'] as $id) {
                seedPerson($this->project, [$id]);
            }

            $ordered = $pairs;
            mt_srand(7 + $permutation);
            shuffle($ordered);

            foreach ($ordered as [$x, $y]) {
                mergeAs($this->project, $x, $y);
            }

            $groupings[] = array_values(personsOfProject($this->project));
        }

        // The survivors differ with the order, the grouping of ids does not: {a,b,c,d,e,f} and {g}.
        expect(array_unique(array_map('json_encode', $groupings)))->toHaveCount(1)
            ->and($groupings[0])->toBe([['a', 'b', 'c', 'd', 'e', 'f'], ['g']]);
    });
});

describe('a reference model', function () {
    /**
     * Plays a random sequence of events against an in-memory model of the rules (sets of ids and an identified flag
     * per set) and against the real resolver; the groups of ids, their identified flags and the outcome of every event
     * must agree. The model is written from the rules in the class comment, not from the SQL.
     */
    it('agrees with the resolver on 300 random events over six ids', function (int $seed) {
        mt_srand($seed);
        $ids = ['a', 'b', 'c', 'd', 'e', 'f'];
        $kinds = IdentityKind::cases();
        $groups = []; // list of ['ids' => [...], 'identified' => bool]

        $find = function (string $id) use (&$groups) {
            foreach ($groups as $index => $group) {
                if (in_array($id, $group['ids'], true)) {
                    return $index;
                }
            }

            return null;
        };

        foreach (range(1, 300) as $step) {
            $kind = $kinds[mt_rand(0, count($kinds) - 1)];
            $x = $ids[mt_rand(0, 5)];
            $y = $ids[mt_rand(0, 5)];

            $expected = null;
            if ($x === $y) {
                $expected = IdentityOutcome::Noop;
            } else {
                $tx = $find($x);
                $ty = $find($y);

                if ($tx === null && $ty === null) {
                    $groups[] = ['ids' => [$x, $y], 'identified' => $kind === IdentityKind::Identify];
                    $expected = IdentityOutcome::Created;
                } elseif ($tx === null || $ty === null) {
                    $index = $tx ?? $ty;
                    $groups[$index]['ids'][] = $tx === null ? $x : $y;
                    $groups[$index]['identified'] = $groups[$index]['identified'] || $kind === IdentityKind::Identify;
                    $expected = IdentityOutcome::Attached;
                } elseif ($tx === $ty) {
                    $expected = IdentityOutcome::Noop;
                } else {
                    $refused = ($kind === IdentityKind::Identify && $groups[$ty]['identified'])
                        || ($kind === IdentityKind::CreateAlias && $groups[$ty]['identified'] && $groups[$tx]['identified']);

                    if ($refused) {
                        $expected = IdentityOutcome::Noop;
                    } else {
                        $groups[$tx]['ids'] = array_merge($groups[$tx]['ids'], $groups[$ty]['ids']);
                        $groups[$tx]['identified'] = $groups[$tx]['identified'] || $kind === IdentityKind::Identify;
                        unset($groups[$ty]);
                        $expected = IdentityOutcome::Merged;
                    }
                }
            }

            $actual = resolveIdentity($this->project, $kind, $x, $y)->outcome;

            expect($actual)->toBe($expected, "step {$step}: {$kind->value}({$x}, {$y})");
        }

        $modelGroups = array_map(function ($group) {
            sort($group['ids']);

            return [$group['ids'], $group['identified']];
        }, array_values($groups));
        usort($modelGroups, fn ($p, $q) => strcmp($p[0][0], $q[0][0]));

        $actualGroups = [];
        foreach (personsOfProject($this->project) as $personId => $distinct) {
            $actualGroups[] = [$distinct, (bool) DB::table('persons')->find($personId)->is_identified];
        }
        usort($actualGroups, fn ($p, $q) => strcmp($p[0][0], $q[0][0]));

        expect($actualGroups)->toBe($modelGroups)
            ->and(DB::table('persons')->count())->toBe(count($modelGroups))
            // No person is left without a distinct id, and every distinct id belongs to exactly one person.
            ->and(DB::scalar('select count(*) from persons p where not exists (select 1 from person_distinct_ids d where d.person_id = p.id)'))->toBe(0);
    })->with([11, 12, 13, 14, 15, 16]);
});

describe('refusing ids', function () {
    it('rejects every reserved id on either side, writes nothing, and names the id and the problem', function (string $reserved) {
        foreach ([[$reserved, 'alice'], ['alice', $reserved]] as [$x, $y]) {
            $result = identifyAs($this->project, $x, $y);

            expect($result->outcome)->toBe(IdentityOutcome::Rejected)
                ->and($result->changedAnything())->toBeFalse()
                ->and($result->problem)->toBe(DistinctIdProblem::Reserved)
                ->and($result->detail)->toBe($reserved)
                ->and($result->personId)->toBeNull();
        }

        expect(DB::table('persons')->count())->toBe(0)->and(DB::table('person_distinct_ids')->count())->toBe(0);
    })->with(DistinctIdRules::RESERVED);

    it('rejects blank, over-long and control-character ids for every kind, before anything is read or locked', function (string $id, DistinctIdProblem $problem) {
        foreach (IdentityKind::cases() as $kind) {
            $result = resolveIdentity($this->project, $kind, 'alice', $id);

            expect($result->outcome)->toBe(IdentityOutcome::Rejected)->and($result->problem)->toBe($problem);
        }

        expect(DB::table('persons')->count())->toBe(0);
    })->with([
        'empty' => ['', DistinctIdProblem::Blank],
        'spaces' => ['   ', DistinctIdProblem::Blank],
        'too long' => [str_repeat('x', 201), DistinctIdProblem::TooLong],
        'NUL, which postgres text cannot store' => ["a\0b", DistinctIdProblem::ControlCharacters],
        'newline' => ["a\nb", DistinctIdProblem::ControlCharacters],
    ]);

    it('accepts an id of exactly 200 characters and stores it whole', function () {
        $id = str_repeat('é', 200);

        expect(identifyAs($this->project, 'alice', $id)->outcome)->toBe(IdentityOutcome::Created)
            ->and(DB::table('person_distinct_ids')->where('distinct_id', $id)->count())->toBe(1);
    });

    it('keeps a reserved word that is only part of an id usable', function () {
        expect(identifyAs($this->project, 'null-user', 'guest_17')->outcome)->toBe(IdentityOutcome::Created);
    });

    it('does not let a rejected event disturb a person that exists', function () {
        $personId = seedPerson($this->project, ['alice'], identified: true);
        $snapshot = identitySnapshot();

        mergeAs($this->project, 'alice', 'undefined');
        aliasAs($this->project, 'NaN', 'alice');

        expect(identitySnapshot())->toBe($snapshot)->and(personsOfProject($this->project))->toBe([$personId => ['alice']]);
    });

    it('works end to end from a normalized event, including an SDK that sent no user id', function () {
        $normalize = fn (array $properties, string $distinctId = 'alice') => IdentityEvent::fromNormalized([
            'event' => '$identify', 'distinct_id' => $distinctId, 'project_id' => $this->project->id,
            'properties' => json_encode((object) $properties), 'uuid' => (string) Str::uuid(),
        ]);

        expect(app(IdentityResolver::class)->apply($normalize(['$anon_distinct_id' => 'anon-1']))->outcome)->toBe(IdentityOutcome::Created)
            // posthog-js with an undefined anon id sends null; JavaScript would call it "null".
            ->and(app(IdentityResolver::class)->apply($normalize(['$anon_distinct_id' => null], 'bob'))->outcome)->toBe(IdentityOutcome::Rejected)
            ->and(app(IdentityResolver::class)->apply($normalize(['$anon_distinct_id' => 0], 'bob'))->outcome)->toBe(IdentityOutcome::Rejected)
            ->and(app(IdentityResolver::class)->apply($normalize(['$anon_distinct_id' => 12345], 'bob'))->outcome)->toBe(IdentityOutcome::Created)
            ->and(DB::table('person_distinct_ids')->where('distinct_id', '12345')->count())->toBe(1);
    });
});

describe('projects', function () {
    it('keeps the same distinct ids of two projects on separate persons', function () {
        $other = Project::factory()->create();

        $first = identifyAs($this->project, 'alice', 'anon-1');
        $second = identifyAs($other, 'alice', 'anon-1');

        expect($first->personId)->not->toBe($second->personId)
            ->and($second->outcome)->toBe(IdentityOutcome::Created)
            ->and(personsOfProject($this->project))->toBe([$first->personId => ['alice', 'anon-1']])
            ->and(personsOfProject($other))->toBe([$second->personId => ['alice', 'anon-1']]);
    });

    it('merges inside one project without touching the same ids in another', function () {
        $other = Project::factory()->create();
        seedPerson($this->project, ['alice']);
        seedPerson($this->project, ['bob']);
        $otherAlice = seedPerson($other, ['alice']);
        $otherBob = seedPerson($other, ['bob']);

        mergeAs($this->project, 'alice', 'bob');

        expect(count(personsOfProject($this->project)))->toBe(1)
            ->and(personsOfProject($other))->toBe([$otherAlice => ['alice'], $otherBob => ['bob']])
            ->and(versionOf($other, 'bob'))->toBe(0)
            ->and(versionOf($this->project, 'bob'))->toBe(1);
    });

    it('fails on a project that does not exist, rolling everything back', function () {
        $missing = $this->project->id + 1000;

        expect(fn () => app(IdentityResolver::class)->apply(new IdentityEvent($missing, IdentityKind::Identify, 'alice', 'anon')))
            ->toThrow(QueryException::class)
            ->and(DB::table('persons')->count())->toBe(0);
    });
});

describe('the transaction', function () {
    it('refuses to run inside a transaction, which would stop it from retrying by rolling back', function () {
        DB::transaction(fn () => expect(fn () => identifyAs($this->project, 'alice', 'anon-1'))->toThrow(LogicException::class));

        expect(DB::table('persons')->count())->toBe(0);
    });

    it('rolls the whole event back when something fails half way', function () {
        $personId = seedPerson($this->project, ['alice']);
        seedPerson($this->project, ['bob']);
        // Make the delete of the source person fail at the very end of a merge.
        DB::unprepared("create function fail_person_delete() returns trigger language plpgsql as \$\$ begin raise exception 'boom'; end \$\$;
            create trigger fail_person_delete before delete on persons for each row execute function fail_person_delete();");

        try {
            expect(fn () => mergeAs($this->project, 'alice', 'bob'))->toThrow(QueryException::class);
        } finally {
            DB::unprepared('drop trigger fail_person_delete on persons; drop function fail_person_delete();');
        }

        // The moved id went back with the rollback: bob still belongs to bob's person, at version 0.
        expect(count(personsOfProject($this->project)))->toBe(2)
            ->and(versionOf($this->project, 'bob'))->toBe(0)
            ->and(DB::table('persons')->where('id', $personId)->exists())->toBeTrue();
    });

    it('gives up waiting for a lock after its lock timeout instead of blocking for ever', function () {
        $holder = DB::connectUsing('lock-holder', (array) config('database.connections.pgsql'), true);
        $keys = IdentityLockKey::for($this->project->id, 'alice');

        $holder->beginTransaction();

        try {
            $holder->select('select pg_advisory_xact_lock(?, ?)', $keys);

            $started = microtime(true);
            $resolver = new IdentityResolver(lockTimeoutMilliseconds: 300);

            expect(fn () => $resolver->apply(new IdentityEvent($this->project->id, IdentityKind::Identify, 'alice', 'anon-1')))
                ->toThrow(fn (QueryException $e) => expect($e->errorInfo[0])->toBe('55P03'));

            expect(microtime(true) - $started)->toBeLessThan(5.0);
        } finally {
            $holder->rollBack();
            DB::purge('lock-holder');
        }

        expect(DB::table('persons')->count())->toBe(0);
    });
});
