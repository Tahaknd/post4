<?php

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

describe('project:create', function () {
    it('stores the timezone option', function () {
        expect(Artisan::call('project:create', ['name' => 'Shop', '--timezone' => 'Europe/Istanbul', '--key-only' => true]))->toBe(0);

        expect(Project::firstOrFail()->timezone)->toBe('Europe/Istanbul');
    });

    it('rejects a bad name or timezone with exit code 2 on stderr and creates nothing', function (array $args) {
        expect(Artisan::call('project:create', $args))->toBe(2)
            ->and(Project::count())->toBe(0);
    })->with([
        'unknown timezone' => [['name' => 'Shop', '--timezone' => 'Nowhere/Land']],
        'too long' => [['name' => str_repeat('x', 101)]],
        'blank' => [['name' => '  ']],
    ]);

    it('writes an audit line attributed to the cli', function () {
        Log::spy();

        Artisan::call('project:create', ['name' => 'Shop', '--key-only' => true]);

        $id = Project::firstOrFail()->id;
        Log::shouldHaveReceived('info')->once()->with('project.create', ['actor' => 'cli', 'project_id' => $id, 'action' => 'create']);
    });
});

describe('project:list', function () {
    it('prints a table without keys by default', function () {
        $project = Project::factory()->create(['name' => 'Shop', 'timezone' => 'Europe/Istanbul']);
        $project->users()->attach(User::factory()->count(2)->create());

        expect(Artisan::call('project:list'))->toBe(0);

        $output = Artisan::output();
        expect($output)->toContain('Shop')->toContain('Europe/Istanbul')->toContain('members')
            ->and($output)->not->toContain('phc_')->not->toContain($project->api_key);
    });

    it('prints one json line with the member count and no key', function () {
        $a = Project::factory()->create(['name' => 'A']);
        Project::factory()->create(['name' => 'B']);
        $a->users()->attach(User::factory()->create());

        expect(Artisan::call('project:list', ['--json' => true]))->toBe(0);

        $output = Artisan::output();
        $rows = json_decode($output, true);
        expect(substr_count($output, "\n"))->toBe(1)
            ->and(array_column($rows, 'name'))->toBe(['A', 'B'])
            ->and(array_column($rows, 'members'))->toBe([1, 0])
            ->and(array_keys($rows[0]))->toBe(['id', 'name', 'timezone', 'created_at', 'members'])
            ->and($output)->not->toContain('api_key')->not->toContain('phc_');
    });

    it('includes the keys only with --show-keys', function () {
        $project = Project::factory()->create();

        Artisan::call('project:list', ['--json' => true, '--show-keys' => true]);

        expect(json_decode(Artisan::output(), true)[0]['api_key'])->toBe($project->api_key);
    });

    it('says so when there are no projects, and prints an empty array as json', function () {
        Artisan::call('project:list');
        expect(Artisan::output())->toContain('No projects.');

        Artisan::call('project:list', ['--json' => true]);
        expect(Artisan::output())->toBe("[]\n");
    });
});

describe('project:add-user and project:remove-user', function () {
    it('adds a member by email (case-insensitive) and is idempotent', function () {
        $project = Project::factory()->create();
        $user = User::factory()->create(['email' => 'ada@example.test']);

        expect(Artisan::call('project:add-user', ['project' => $project->id, 'email' => ' ADA@example.test ']))->toBe(0);
        expect(Artisan::output())->toContain("Added ada@example.test to project #{$project->id} as member.");
        expect(Artisan::call('project:add-user', ['project' => $project->id, 'email' => 'ada@example.test']))->toBe(0);
        expect(Artisan::output())->toContain('already a member');

        expect(DB::table('project_user')->where(['project_id' => $project->id, 'user_id' => $user->id, 'role' => ProjectRole::Member->value])->count())->toBe(1);
    });

    it('fails clearly for a bad project id, an unknown project, an unknown user and an unknown role', function () {
        $project = Project::factory()->create();
        User::factory()->create(['email' => 'ada@example.test']);

        expect(Artisan::call('project:add-user', ['project' => 'abc', 'email' => 'ada@example.test']))->toBe(2)
            ->and(Artisan::call('project:add-user', ['project' => 9999, 'email' => 'ada@example.test']))->toBe(3)
            ->and(Artisan::call('project:add-user', ['project' => $project->id, 'email' => 'nobody@example.test']))->toBe(1)
            ->and(Artisan::call('project:add-user', ['project' => $project->id, 'email' => 'ada@example.test', '--role' => 'owner']))->toBe(2)
            ->and(DB::table('project_user')->count())->toBe(0);
    });

    it('removes a member and reports when there was none', function () {
        $project = Project::factory()->create();
        $user = User::factory()->create(['email' => 'ada@example.test']);
        $project->users()->attach($user);

        expect(Artisan::call('project:remove-user', ['project' => $project->id, 'email' => 'ada@example.test']))->toBe(0);
        expect(Artisan::output())->toContain('Removed ada@example.test');
        expect(Artisan::call('project:remove-user', ['project' => $project->id, 'email' => 'ada@example.test']))->toBe(0);
        expect(Artisan::output())->toContain('not a member')
            ->and($user->canAccessProject($project))->toBeFalse();
    });

    it('rejects a malformed project id on remove-user', function () {
        expect(Artisan::call('project:remove-user', ['project' => '1.5', 'email' => 'a@example.test']))->toBe(2)
            ->and(Artisan::call('project:remove-user', ['project' => '999', 'email' => 'a@example.test']))->toBe(3);
    });
});
