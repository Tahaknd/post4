<?php

use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Support\Facades\Gate;

it('is discovered for the Project model', function () {
    expect(Gate::getPolicyFor(Project::class))->toBeInstanceOf(ProjectPolicy::class);
});

it('decides every ability for a member, a non-member and an administrator', function (string $ability, bool $member, bool $nonMember, bool $admin) {
    $project = Project::factory()->create();
    $memberUser = User::factory()->create();
    $project->users()->attach($memberUser);
    $outsider = User::factory()->create();
    $administrator = User::factory()->admin()->create();

    expect(Gate::forUser($memberUser)->allows($ability, $project))->toBe($member, 'member')
        ->and(Gate::forUser($outsider)->allows($ability, $project))->toBe($nonMember, 'non-member')
        ->and(Gate::forUser($administrator)->allows($ability, $project))->toBe($admin, 'admin');
})->with([
    'view' => ['view', true, false, true],
    'update' => ['update', true, false, true],
    'rotateKey' => ['rotateKey', true, false, true],
    'delete' => ['delete', false, false, true],
    'manageMembers' => ['manageMembers', false, false, true],
]);

it('lets any signed-in user list and create projects', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewAny', Project::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('create', Project::class))->toBeTrue();
});

it('never lets a guest do anything', function (string $ability) {
    $project = Project::factory()->create();

    expect(Gate::forUser(null)->allows($ability, [$project]))->toBeFalse();
})->with(['view', 'update', 'rotateKey', 'delete', 'manageMembers']);

it('treats is_admin strictly', function () {
    $project = Project::factory()->create();
    $user = new User;
    $user->setRawAttributes(['id' => 1, 'is_admin' => 1]); // cast to true; a truthy-but-not-true value must not count
    $user->mergeCasts(['is_admin' => 'integer']);

    expect((new ProjectPolicy)->delete($user, $project))->toBeFalse();
});
