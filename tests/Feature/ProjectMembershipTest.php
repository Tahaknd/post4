<?php

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('links users and projects through the project_user pivot with a default member role', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $user->projects()->attach($project);

    expect($user->projects)->toHaveCount(1)
        ->and($user->projects->first()->pivot->role)->toBe(ProjectRole::Member)
        ->and($project->users->pluck('id')->all())->toBe([$user->id])
        ->and($project->users->first()->pivot->created_at)->not->toBeNull();
});

it('refuses a second membership row for the same user and project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $user->projects()->attach($project);

    expect(fn () => $user->projects()->attach($project))->toThrow(UniqueConstraintViolationException::class);
});

it('removes memberships when the user or the project is deleted', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $project = Project::factory()->create();
    $project->users()->attach([$user->id, $other->id]);

    $user->delete();
    expect(DB::table('project_user')->count())->toBe(1);

    $project->delete();
    expect(DB::table('project_user')->count())->toBe(0);
});

it('lets a member access only the projects they belong to', function () {
    $member = User::factory()->create();
    $mine = Project::factory()->create();
    $theirs = Project::factory()->create();
    $mine->users()->attach($member);

    expect($member->canAccessProject($mine))->toBeTrue()
        ->and($member->canAccessProject($theirs))->toBeFalse()
        ->and(Project::visibleTo($member)->pluck('id')->all())->toBe([$mine->id]);
});

it('lets an admin access every project without a membership row', function () {
    $admin = User::factory()->admin()->create();
    $projects = Project::factory()->count(2)->create();

    expect($projects->every(fn (Project $project) => $admin->canAccessProject($project)))->toBeTrue()
        ->and(Project::visibleTo($admin)->count())->toBe(2)
        ->and($admin->projects)->toBeEmpty();
});

it('gives a user with no membership no project at all', function () {
    Project::factory()->create();

    expect(Project::visibleTo(User::factory()->create())->count())->toBe(0);
});

it('does not let mass assignment promote a user', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@example.test', 'password' => 'secret-secret-1', 'is_admin' => true]);

    expect($user->fresh()->is_admin)->toBeFalse();
});

it('defaults is_admin to false and has an admin factory state', function () {
    expect(User::factory()->create()->is_admin)->toBeFalse()
        ->and(User::factory()->admin()->create()->is_admin)->toBeTrue();
});

it('stores emails trimmed and lowercased', function () {
    $user = User::factory()->create(['email' => '  Owner@Example.TEST ']);

    expect($user->fresh()->email)->toBe('owner@example.test');
});
