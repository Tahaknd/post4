<?php

use App\Actions\Projects\AddProjectMember;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\QueueEventsPurge;
use App\Actions\Projects\RemoveProjectMember;
use App\Actions\Projects\RotateProjectKey;
use App\Domain\Capture\ProjectResolver;
use App\Jobs\PurgeProjectEvents;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProjectCache;

/** Collects the structured log lines of project writes. */
function captureAuditLog(): ArrayObject
{
    $lines = new ArrayObject;
    Log::shouldReceive('info')->andReturnUsing(function (string $message, array $context = []) use ($lines): void {
        $lines[] = [$message, $context];
    });

    return $lines;
}

describe('CreateProject', function () {
    it('creates a project with a fresh key, a trimmed name and the UTC default', function () {
        $project = app(CreateProject::class)->handle('  Demo  ');

        expect($project->name)->toBe('Demo')
            ->and($project->timezone)->toBe('UTC')
            ->and($project->api_key)->toMatch(Project::API_KEY_PATTERN)
            ->and($project->users()->count())->toBe(0);
    });

    it('stores a valid timezone', function () {
        expect(app(CreateProject::class)->handle('Demo', 'Europe/Istanbul')->fresh()->timezone)->toBe('Europe/Istanbul');
    });

    it('attaches a non-admin creator as a member and gives an admin no membership row', function () {
        $member = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $mine = app(CreateProject::class)->handle('Mine', actor: $member);
        $adminsOwn = app(CreateProject::class)->handle('Admins', actor: $admin);

        expect($mine->users->pluck('id')->all())->toBe([$member->id])
            ->and($mine->users->first()->pivot->role)->toBe(ProjectRole::Member)
            ->and($member->canAccessProject($mine))->toBeTrue()
            ->and($adminsOwn->users()->count())->toBe(0)
            ->and($admin->canAccessProject($adminsOwn))->toBeTrue();
    });

    it('rejects an invalid name or timezone and stores nothing', function (string $name, ?string $timezone) {
        expect(fn () => app(CreateProject::class)->handle($name, $timezone))->toThrow(ValidationException::class)
            ->and(Project::count())->toBe(0);
    })->with([
        'empty' => ['', null],
        'blank' => ['   ', null],
        'too long' => [str_repeat('a', 101), null],
        'control character' => ["bad\nname", null],
        'unknown timezone' => ['Demo', 'Mars/Olympus'],
        'wrong case' => ['Demo', 'europe/istanbul'],
        'empty timezone' => ['Demo', ''],
    ]);

    it('accepts 100 characters and counts characters, not bytes', function () {
        expect(app(CreateProject::class)->handle(str_repeat('ü', 100))->name)->toBe(str_repeat('ü', 100));
    });

    it('rolls the project back when a later step of the creation fails', function () {
        Project::created(fn () => throw new RuntimeException('after the insert'));

        expect(fn () => app(CreateProject::class)->handle('Demo'))->toThrow(RuntimeException::class)
            ->and(Project::count())->toBe(0);
    });

    it('makes the new key resolvable even after the key was probed before it existed', function () {
        $resolver = app(ProjectResolver::class);
        $project = app(CreateProject::class)->handle('Demo');

        expect($resolver->resolve($project->api_key))->toBe($project->id);
    });
});

describe('RotateProjectKey', function () {
    it('replaces the key, and the old key stops resolving even when cached', function () {
        $project = Project::factory()->create();
        $old = $project->api_key;
        $resolver = app(ProjectResolver::class);
        expect($resolver->resolve($old))->toBe($project->id);

        $rotated = app(RotateProjectKey::class)->handle($project);

        expect($rotated->api_key)->not->toBe($old)->toMatch(Project::API_KEY_PATTERN)
            ->and($project->fresh()->api_key)->toBe($rotated->api_key)
            ->and(ProjectCache::hasPositive($old))->toBeFalse()
            ->and($resolver->resolve($old))->toBeNull()
            ->and($resolver->resolve($rotated->api_key))->toBe($project->id);
    });

    it('forgets the old key only after the outermost commit', function () {
        $project = Project::factory()->create();
        $old = $project->api_key;
        app(ProjectResolver::class)->resolve($old);

        DB::transaction(function () use ($project, $old) {
            app(RotateProjectKey::class)->handle($project);

            expect(ProjectCache::hasPositive($old))->toBeTrue();
        });

        expect(ProjectCache::hasPositive($old))->toBeFalse();
    });

    it('keeps the old key and the cache when the surrounding transaction rolls back', function () {
        $project = Project::factory()->create();
        $old = $project->api_key;
        app(ProjectResolver::class)->resolve($old);

        try {
            DB::transaction(function () use ($project) {
                app(RotateProjectKey::class)->handle($project);

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        expect($project->fresh()->api_key)->toBe($old)
            ->and(ProjectCache::hasPositive($old))->toBeTrue();
    });

    it('rotates from the stored key even when the given instance is stale', function () {
        $project = Project::factory()->create();
        $stale = Project::find($project->id);
        $first = app(RotateProjectKey::class)->handle($project);

        $second = app(RotateProjectKey::class)->handle($stale);

        expect($second->api_key)->not->toBe($first->api_key)
            ->and(Project::find($project->id)->api_key)->toBe($second->api_key);
    });

    it('fails with ModelNotFound for a project that no longer exists', function () {
        $project = Project::factory()->create();
        $project->delete();

        expect(fn () => app(RotateProjectKey::class)->handle($project))->toThrow(ModelNotFoundException::class);
    });
});

describe('DeleteProject', function () {
    it('deletes the project and its memberships, forgets the key and queues two purge passes', function () {
        Queue::fake();
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $project->users()->attach(User::factory()->create());
        app(ProjectResolver::class)->resolve($project->api_key);

        $result = app(DeleteProject::class)->handle($project);

        expect($result->deleted)->toBeTrue()->and($result->purgeRequested)->toBeTrue()->and($result->purgeQueued)->toBeTrue()
            ->and($result->purgeFailedToQueue())->toBeFalse()
            ->and(Project::whereKey($project->id)->exists())->toBeFalse()
            ->and(Project::whereKey($other->id)->exists())->toBeTrue()
            ->and(DB::table('project_user')->count())->toBe(0)
            ->and(ProjectCache::hasPositive($project->api_key))->toBeFalse();

        Queue::assertPushed(PurgeProjectEvents::class, 2);
        Queue::assertPushed(PurgeProjectEvents::class, fn (PurgeProjectEvents $job) => $job->projectId === $project->id && $job->pass === 1 && $job->delay === null);
        Queue::assertPushed(PurgeProjectEvents::class, fn (PurgeProjectEvents $job) => $job->projectId === $project->id && $job->pass === 2 && $job->delay === QueueEventsPurge::SECOND_PASS_DELAY_SECONDS);
    });

    it('queues nothing when the events are to be kept', function () {
        Queue::fake();
        $project = Project::factory()->create();

        $result = app(DeleteProject::class)->handle($project, purgeEvents: false);

        Queue::assertNothingPushed();
        expect(Project::count())->toBe(0)
            ->and($result->deleted)->toBeTrue()->and($result->purgeRequested)->toBeFalse()->and($result->purgeFailedToQueue())->toBeFalse();
    });

    it('queues the purge only after the outermost commit and never on a rollback', function () {
        Queue::fake();
        $kept = Project::factory()->create();
        $gone = Project::factory()->create();

        try {
            DB::transaction(function () use ($kept) {
                app(DeleteProject::class)->handle($kept);

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }
        Queue::assertNothingPushed();
        expect(Project::whereKey($kept->id)->exists())->toBeTrue();

        DB::transaction(function () use ($gone) {
            app(DeleteProject::class)->handle($gone);

            Queue::assertNothingPushed();
        });
        Queue::assertPushed(PurgeProjectEvents::class, 2);
    });

    it('is a no-op for a project that is already gone', function () {
        Queue::fake();
        $project = Project::factory()->create();
        $project->delete();

        $result = app(DeleteProject::class)->handle($project);

        Queue::assertNothingPushed();
        expect($result->deleted)->toBeFalse()->and($result->purgeFailedToQueue())->toBeFalse();
    });

    it('still deletes the project and reports that the purge was not queued when the queue is unreachable, logging once', function () {
        config(['queue.default' => 'broken', 'queue.connections.broken' => ['driver' => 'redis', 'connection' => 'unreachable']]);
        config(['database.redis.unreachable' => ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2]]);
        app()->forgetInstance('queue');
        Queue::swap(app('queue'));
        $project = Project::factory()->create();
        Exceptions::fake();
        Log::spy();

        $result = app(DeleteProject::class)->handle($project);

        expect(Project::whereKey($project->id)->exists())->toBeFalse()
            ->and($result->deleted)->toBeTrue()->and($result->purgeQueued)->toBeFalse()->and($result->purgeFailedToQueue())->toBeTrue();
        // One log line (no report() on top of it), carrying the id and the exception class only.
        Exceptions::assertNothingReported();
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => array_keys($context) === ['project_id', 'exception'] && $context['project_id'] === $project->id);
    });
});

describe('membership actions', function () {
    it('adds a member once and removes them again', function () {
        $project = Project::factory()->create();
        $user = User::factory()->create();

        expect(app(AddProjectMember::class)->handle($project, $user))->toBeTrue()
            ->and(app(AddProjectMember::class)->handle($project, $user))->toBeFalse()
            ->and($project->users()->count())->toBe(1)
            ->and($user->canAccessProject($project))->toBeTrue()
            ->and(app(RemoveProjectMember::class)->handle($project, $user))->toBeTrue()
            ->and(app(RemoveProjectMember::class)->handle($project, $user))->toBeFalse()
            ->and($user->canAccessProject($project))->toBeFalse();
    });
});

describe('audit log', function () {
    it('logs who did what to which project, and never the api key or the name', function () {
        $lines = captureAuditLog();
        $actor = User::factory()->create();

        $project = app(CreateProject::class)->handle('Secret Name', actor: $actor);
        app(RotateProjectKey::class)->handle($project, $actor);
        Queue::fake();
        app(DeleteProject::class)->handle($project, null);

        $actions = array_map(fn ($line) => [$line[0], $line[1]['actor'], $line[1]['project_id'], $line[1]['action']], $lines->getArrayCopy());

        expect($actions)->toBe([
            ['project.create', $actor->id, $project->id, 'create'],
            ['project.rotate_key', $actor->id, $project->id, 'rotate_key'],
            ['project.delete', 'cli', $project->id, 'delete'],
        ]);

        $logged = json_encode($lines->getArrayCopy());
        expect($logged)->not->toContain('phc_')->not->toContain('Secret Name')->not->toContain($project->api_key);
    });

    it('writes no line for a write that rolled back', function () {
        $lines = captureAuditLog();

        try {
            DB::transaction(function () {
                app(CreateProject::class)->handle('Demo');

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        expect($lines->count())->toBe(0);
    });
});

describe('audit channel', function () {
    it('writes the audit lines to the configured channel and not to the default one', function () {
        $audit = sys_get_temp_dir().'/audit-'.bin2hex(random_bytes(4)).'.log';
        $default = sys_get_temp_dir().'/default-'.bin2hex(random_bytes(4)).'.log';
        config([
            'logging.channels.audit_test' => ['driver' => 'single', 'path' => $audit, 'level' => 'info'],
            'logging.channels.default_test' => ['driver' => 'single', 'path' => $default, 'level' => 'info'],
            'logging.default' => 'default_test',
            'logging.audit_channel' => 'audit_test',
        ]);

        $project = app(CreateProject::class)->handle('Demo');

        $auditText = (string) @file_get_contents($audit);
        $defaultText = (string) @file_get_contents($default);
        @unlink($audit);
        @unlink($default);
        expect($auditText)->toContain('project.create')->toContain('"project_id":'.$project->id)->not->toContain('phc_')
            ->and($defaultText)->toBe('');
    });

    it('falls back to the default channel when none is configured', function () {
        config(['logging.audit_channel' => null]);
        $lines = captureAuditLog();

        app(CreateProject::class)->handle('Demo');

        expect($lines)->toHaveCount(1);
    });

    it('defines an audit channel to point LOG_AUDIT_CHANNEL at', function () {
        expect(config('logging.channels.audit.driver'))->toBe('daily')
            ->and(config('logging.channels.audit.path'))->toEndWith('storage/logs/audit.log');
    });
});
