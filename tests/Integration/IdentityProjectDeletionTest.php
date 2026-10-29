<?php

use App\Actions\Projects\DeleteProject;
use App\Domain\Identity\IdentityEvent;
use App\Domain\Identity\IdentityKind;
use App\Domain\Identity\IdentityResolver;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('deletes the persons and distinct ids of a project with the project, on real Postgres, and leaves other projects alone', function () {
    Queue::fake();
    $gone = Project::factory()->create();
    $kept = Project::factory()->create();
    $resolver = app(IdentityResolver::class);

    foreach ([$gone, $kept] as $project) {
        $resolver->apply(new IdentityEvent($project->id, IdentityKind::Identify, 'alice', 'anon-1'));
        $resolver->apply(new IdentityEvent($project->id, IdentityKind::Identify, 'bob', 'anon-2'));
        $resolver->apply(new IdentityEvent($project->id, IdentityKind::MergeDangerously, 'alice', 'bob'));
    }

    expect(DB::table('persons')->count())->toBe(2)->and(DB::table('person_distinct_ids')->count())->toBe(8);

    app(DeleteProject::class)->handle($gone);

    expect(DB::table('persons')->pluck('project_id')->all())->toBe([$kept->id])
        ->and(DB::table('person_distinct_ids')->pluck('project_id')->unique()->values()->all())->toBe([$kept->id])
        ->and(DB::table('person_distinct_ids')->where('project_id', $kept->id)->count())->toBe(4);
});
