<?php

use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The constraints of the identity tables that the default (SQLite) suite can see. The jsonb operators, the GIN
 * index and the advisory locks are Postgres-only and covered by the Integration suite (phpunit.pgsql.xml).
 */

function insertPerson(Project $project, array $attributes = []): int
{
    return DB::table('persons')->insertGetId($attributes + ['uuid' => (string) Str::uuid7(), 'project_id' => $project->id]);
}

function insertDistinctId(Project $project, int $personId, string $distinctId): void
{
    DB::table('person_distinct_ids')->insert(['project_id' => $project->id, 'person_id' => $personId, 'distinct_id' => $distinctId]);
}

it('defaults a person to empty properties, not identified, and a distinct id to version 0', function () {
    $project = Project::factory()->create();
    $personId = insertPerson($project);
    insertDistinctId($project, $personId, 'alice');

    $person = DB::table('persons')->find($personId);

    expect($person->properties)->toBe('{}')
        ->and((bool) $person->is_identified)->toBeFalse()
        ->and(DB::table('person_distinct_ids')->value('version'))->toBe(0);
});

it('keeps one row per distinct id and project, and lets two projects use the same distinct id', function () {
    $a = Project::factory()->create();
    $b = Project::factory()->create();
    $personA = insertPerson($a);
    insertDistinctId($a, $personA, 'alice');
    insertDistinctId($b, insertPerson($b), 'alice');

    expect(DB::table('person_distinct_ids')->where('distinct_id', 'alice')->count())->toBe(2)
        ->and(fn () => insertDistinctId($a, insertPerson($a), 'alice'))->toThrow(QueryException::class);
});

it('rejects a person uuid that is already taken', function () {
    $project = Project::factory()->create();
    $uuid = (string) Str::uuid7();
    insertPerson($project, ['uuid' => $uuid]);

    expect(fn () => insertPerson($project, ['uuid' => $uuid]))->toThrow(QueryException::class);
});

it('refuses a distinct id that points at a person of another project', function () {
    $a = Project::factory()->create();
    $b = Project::factory()->create();
    $personOfB = insertPerson($b);

    expect(fn () => insertDistinctId($a, $personOfB, 'alice'))->toThrow(QueryException::class);
});

it('deletes the distinct ids of a person with the person, and the persons of a project with the project', function () {
    $project = Project::factory()->create();
    $kept = Project::factory()->create();
    $first = insertPerson($project);
    $second = insertPerson($project);
    insertDistinctId($project, $first, 'alice');
    insertDistinctId($project, $second, 'bob');
    insertDistinctId($kept, insertPerson($kept), 'alice');

    DB::table('persons')->where('id', $first)->delete();
    expect(DB::table('person_distinct_ids')->where('project_id', $project->id)->pluck('distinct_id')->all())->toBe(['bob']);

    $project->delete();
    expect(DB::table('persons')->count())->toBe(1)
        ->and(DB::table('person_distinct_ids')->count())->toBe(1);
});
