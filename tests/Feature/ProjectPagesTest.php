<?php

use App\Actions\Projects\QueueEventsPurge;
use App\Domain\Capture\ProjectResolver;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\ReservedPaths;
use App\Jobs\PurgeProjectEvents;
use App\Models\Project;
use App\Models\User;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ProjectCache;

/** A project the member belongs to, and one they do not. */
function memberWithProjects(): array
{
    $member = User::factory()->create();
    $mine = Project::factory()->create(['name' => 'Mine', 'timezone' => 'Europe/Istanbul']);
    $theirs = Project::factory()->create(['name' => 'Theirs']);
    $mine->users()->attach($member);

    return [$member, $mine, $theirs];
}

describe('index', function () {
    it('redirects guests to the login page', function () {
        $this->get('/projects')->assertRedirect('/login?next=%2Fprojects');
        $this->get('/projects/create')->assertRedirect('/login?next=%2Fprojects%2Fcreate');
    });

    it('lists only the projects the user can open, with pinned props and no key', function () {
        [$member, $mine, $theirs] = memberWithProjects();

        $this->actingAs($member)->get('/projects')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Index')
                ->where('projects', [[
                    'id' => $mine->id,
                    'name' => 'Mine',
                    'timezone' => 'Europe/Istanbul',
                    'created_at' => $mine->created_at->toIso8601String(),
                    'url' => "/projects/{$mine->id}",
                ]])
                ->where('create_url', '/projects/create'));

        $response = $this->actingAs($member)->get('/projects');
        expect($response->getContent())->not->toContain('phc_')->not->toContain('Theirs');
    });

    it('lists every project for an administrator and shows the empty state data for nobody else', function () {
        Project::factory()->create(['name' => 'B']);
        Project::factory()->create(['name' => 'A']);

        $names = array_column($this->actingAs(User::factory()->admin()->create())->get('/projects')->viewData('page')['props']['projects'], 'name');
        expect($names)->toBe(['A', 'B']);

        $this->actingAs(User::factory()->create())->get('/projects')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('projects', []));
    });
});

describe('shared props', function () {
    it('shares the nav project list as id, name and server-built url, ordered, capped at 50, and never a key', function () {
        $user = User::factory()->admin()->create();
        foreach (range(1, 55) as $n) {
            Project::factory()->create(['name' => sprintf('P%02d', $n)]);
        }

        $props = $this->actingAs($user)->get('/')->viewData('page')['props'];
        $first = $props['projects'][0];

        expect($props['projects'])->toHaveCount(HandleInertiaRequests::PROJECT_LIMIT)
            ->and($props['projectsTruncated'])->toBeTrue()
            ->and(array_keys($first))->toBe(['id', 'name', 'url'])
            ->and($first['name'])->toBe('P01')
            ->and($first['url'])->toBe("/projects/{$first['id']}")
            ->and(json_encode($props))->not->toContain('phc_');
    });

    it('says nothing is truncated when every project fits', function () {
        Project::factory()->count(3)->create();

        $props = $this->actingAs(User::factory()->admin()->create())->get('/')->viewData('page')['props'];

        expect($props['projects'])->toHaveCount(3)->and($props['projectsTruncated'])->toBeFalse();
    });

    it('always lists the project on screen, even when it sorts past the cap', function () {
        foreach (range(1, 55) as $n) {
            Project::factory()->create(['name' => sprintf('P%02d', $n)]);
        }
        $last = Project::factory()->create(['name' => 'Zulu']);

        $props = $this->actingAs(User::factory()->admin()->create())->get("/projects/{$last->id}")->viewData('page')['props'];

        expect($props['projects'])->toHaveCount(HandleInertiaRequests::PROJECT_LIMIT)
            ->and($props['projectsTruncated'])->toBeTrue()
            ->and(array_column($props['projects'], 'id'))->toContain($last->id)
            ->and(array_column($props['projects'], 'name'))->toBe(array_values(array_unique(array_column($props['projects'], 'name'))));
    });

    it('builds the shared props lazily: nothing is read until a page renders', function () {
        Project::factory()->create();
        $request = Request::create('/');
        $request->setUserResolver(fn () => User::factory()->admin()->create());
        DB::enableQueryLog();

        $shared = (new HandleInertiaRequests)->share($request);

        expect($shared['projects'])->toBeInstanceOf(Closure::class)
            ->and($shared['projectsTruncated'])->toBeInstanceOf(Closure::class)
            ->and($shared['auth'])->toBeInstanceOf(Closure::class)
            ->and(array_filter(DB::getQueryLog(), fn (array $q) => str_contains($q['query'], 'projects')))->toBe([]);

        // And once it is called, it reads the list once for both props.
        DB::flushQueryLog();
        $shared['projects']();
        $shared['projectsTruncated']();
        expect(array_filter(DB::getQueryLog(), fn (array $q) => str_contains($q['query'], 'from "projects"')))->toHaveCount(1);
    });

    it('shows a member only their projects in the nav list', function () {
        [$member, $mine] = memberWithProjects();

        $props = $this->actingAs($member)->get('/')->viewData('page')['props'];

        expect($props['projects'])->toBe([['id' => $mine->id, 'name' => 'Mine', 'url' => "/projects/{$mine->id}"]]);
    });
});

describe('create and store', function () {
    it('renders the form with the timezone list and the default', function () {
        $this->actingAs(User::factory()->create())->get('/projects/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Create')
                ->where('default_timezone', 'UTC')
                ->where('store_url', '/projects')
                ->where('index_url', '/projects')
                ->where('timezones', DateTimeZone::listIdentifiers()));
    });

    it('creates the project, makes a non-admin the member and redirects with 303 to its page', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/projects', ['name' => '  Shop ', 'timezone' => 'Europe/Istanbul']);

        $project = Project::firstOrFail();
        $response->assertStatus(303)->assertRedirect("/projects/{$project->id}")->assertSessionHas('status', 'Project created.');
        expect($project->name)->toBe('Shop')
            ->and($project->timezone)->toBe('Europe/Istanbul')
            ->and($user->canAccessProject($project))->toBeTrue()
            ->and($project->users()->count())->toBe(1);
    });

    it('lets an administrator create without a membership row', function () {
        $this->actingAs(User::factory()->admin()->create())->post('/projects', ['name' => 'Shop', 'timezone' => 'UTC'])->assertStatus(303);

        expect(Project::firstOrFail()->users()->count())->toBe(0);
    });

    it('answers an Inertia form post with 303 too', function () {
        $this->actingAs(User::factory()->create())
            ->post('/projects', ['name' => 'Shop', 'timezone' => 'UTC'], ['X-Inertia' => 'true', 'X-Inertia-Version' => hash_file('md5', $this->publicPath.'/build/manifest.json')])
            ->assertStatus(303);
    });

    it('rejects bad input with fixed messages and creates nothing', function (array $input, string $field, string $message) {
        $this->actingAs(User::factory()->create())->from('/projects/create')->post('/projects', $input)
            ->assertRedirect('/projects/create')
            ->assertSessionHasErrors([$field => $message]);

        expect(Project::count())->toBe(0);
    })->with([
        'no name' => [['name' => '', 'timezone' => 'UTC'], 'name', 'Enter a project name.'],
        'blank name' => [['name' => '   ', 'timezone' => 'UTC'], 'name', 'Enter a project name.'],
        'long name' => [['name' => str_repeat('a', 101), 'timezone' => 'UTC'], 'name', 'The name must be 100 characters or fewer.'],
        'control char' => [['name' => "a\x07b", 'timezone' => 'UTC'], 'name', 'The name contains characters that are not allowed.'],
        'no timezone' => [['name' => 'Shop'], 'timezone', 'Choose a timezone.'],
        'bad timezone' => [['name' => 'Shop', 'timezone' => 'Mars/Base'], 'timezone', 'Choose a timezone from the list.'],
        'array name' => [['name' => ['x'], 'timezone' => 'UTC'], 'name', 'Enter a project name.'],
    ]);

    it('does not echo what the user typed back in the error', function () {
        $this->actingAs(User::factory()->create())->from('/projects/create')
            ->post('/projects', ['name' => 'ok', 'timezone' => '<script>alert(1)</script>'])
            ->assertSessionHasErrors(['timezone' => 'Choose a timezone from the list.']);
    });

    it('lets the write through when the limiter store is unreachable', function () {
        LimiterOutage::reset();
        $user = User::factory()->create();
        ProjectCache::breakStore();

        $this->actingAs($user)->post('/projects', ['name' => 'A', 'timezone' => 'UTC'])->assertStatus(303);

        expect(Project::count())->toBe(1);
    });
});

describe('show', function () {
    it('renders the project page for a member with the key as a page prop and pinned props', function () {
        [$member, $mine] = memberWithProjects();
        config(['app.url' => 'https://analytics.example/']);

        $this->actingAs($member)->get("/projects/{$mine->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Show')
                ->where('project', [
                    'id' => $mine->id,
                    'name' => 'Mine',
                    'timezone' => 'Europe/Istanbul',
                    'created_at' => $mine->created_at->toIso8601String(),
                ])
                ->where('api_key', $mine->api_key)
                ->where('api_host', 'https://analytics.example')
                ->where('can', ['rotate_key' => true, 'delete' => false])
                ->where('urls', [
                    'index' => '/projects',
                    'trends' => "/projects/{$mine->id}/insights/trends",
                    'funnel' => "/projects/{$mine->id}/insights/funnel",
                    'retention' => "/projects/{$mine->id}/insights/retention",
                    'demo' => "/projects/{$mine->id}/demo",
                    'rotate_key' => "/projects/{$mine->id}/api-key",
                    'destroy' => "/projects/{$mine->id}",
                ]));
    });

    it('offers deletion to an administrator', function () {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->admin()->create())->get("/projects/{$project->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.delete', true)->where('can.rotate_key', true));
    });

    it('carries the key in no shared prop of its own page', function () {
        [$member, $mine] = memberWithProjects();

        $props = $this->actingAs($member)->get("/projects/{$mine->id}")->viewData('page')['props'];

        $shared = array_intersect_key($props, array_flip(['app', 'auth', 'projects', 'flash', 'errors']));
        expect(json_encode($shared))->not->toContain($mine->api_key)->not->toContain('phc_')
            ->and(substr_count(json_encode($props), $mine->api_key))->toBe(1);
    });

    it('answers 404, not 403, to a non-member for an existing project and for a missing one alike', function () {
        [$member, , $theirs] = memberWithProjects();

        $existing = $this->actingAs($member)->get("/projects/{$theirs->id}");
        $missing = $this->actingAs($member)->get('/projects/999999');

        $existing->assertNotFound();
        $missing->assertNotFound();
        expect($existing->getContent())->not->toContain($theirs->api_key)->not->toContain('Theirs');
    });

    it('answers 404 to ids that are not plain numbers', function (string $id) {
        $this->actingAs(User::factory()->admin()->create())->get("/projects/{$id}")->assertNotFound();
    })->with(['letters' => 'abc', 'negative' => '-1', 'decimal' => '1.5', 'beyond bigint' => '99999999999999999999']);

    it('answers the Error page for a 404 inside the app layout', function () {
        $this->actingAs(User::factory()->create())->get('/projects/1')
            ->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 404));
    });
});

describe('demo', function () {
    it('renders the test bench for a member with the key as a page prop and pinned props', function () {
        [$member, $mine] = memberWithProjects();
        config(['app.url' => 'https://analytics.example/']);

        $this->actingAs($member)->get("/projects/{$mine->id}/demo")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Demo')
                ->where('project', ['id' => $mine->id, 'name' => 'Mine'])
                ->where('api_key', $mine->api_key)
                ->where('api_host', 'https://analytics.example')
                ->where('urls', [
                    'show' => "/projects/{$mine->id}",
                    'trends' => "/projects/{$mine->id}/insights/trends",
                ]));
    });

    it('is named projects.demo and lives under /projects/{project}/demo', function () {
        expect(route('projects.demo', 7, absolute: false))->toBe('/projects/7/demo');
    });

    it('carries the key once, in no shared prop', function () {
        [$member, $mine] = memberWithProjects();

        $props = $this->actingAs($member)->get("/projects/{$mine->id}/demo")->viewData('page')['props'];

        $shared = array_intersect_key($props, array_flip(['app', 'auth', 'projects', 'flash', 'errors']));
        expect(json_encode($shared))->not->toContain('phc_')
            ->and(substr_count(json_encode($props), $mine->api_key))->toBe(1);
    });

    it('is open to an administrator for any project and to a member of that project only', function () {
        [$member, $mine, $theirs] = memberWithProjects();

        $this->actingAs(User::factory()->admin()->create())->get("/projects/{$theirs->id}/demo")->assertOk();
        $this->actingAs($member)->get("/projects/{$mine->id}/demo")->assertOk();
    });

    it('answers 404 to a non-member for an existing project and a missing one alike, without the key', function () {
        [$member, , $theirs] = memberWithProjects();

        $existing = $this->actingAs($member)->get("/projects/{$theirs->id}/demo");
        $missing = $this->actingAs($member)->get('/projects/999999/demo');

        $existing->assertNotFound();
        $missing->assertNotFound();
        expect($existing->getContent())->not->toContain($theirs->api_key)->not->toContain('phc_');
    });

    it('redirects a guest to the login page and never starts a session for it', function () {
        [, $mine] = memberWithProjects();

        $this->get("/projects/{$mine->id}/demo")->assertRedirect("/login?next=%2Fprojects%2F{$mine->id}%2Fdemo");
    });

    it('answers 404 to ids that are not plain numbers', function (string $id) {
        $this->actingAs(User::factory()->admin()->create())->get("/projects/{$id}/demo")->assertNotFound();
    })->with(['letters' => 'abc', 'negative' => '-1', 'beyond bigint' => '99999999999999999999']);

    it('is no capture or SDK path', function () {
        expect(ReservedPaths::collides('/projects/1/demo'))->toBeFalse();
    });
});

describe('rotate key', function () {
    it('replaces the key, redirects 303 to the project page and flashes a sentence without the key', function () {
        [$member, $mine] = memberWithProjects();
        $old = $mine->api_key;

        $response = $this->actingAs($member)->put("/projects/{$mine->id}/api-key");

        $new = $mine->fresh()->api_key;
        $response->assertStatus(303)->assertRedirect("/projects/{$mine->id}")
            ->assertSessionHas('status', 'API key replaced. The old key no longer works.');
        expect($new)->not->toBe($old)
            ->and(json_encode(session()->all()))->not->toContain($old)->not->toContain($new);

        $page = $this->actingAs($member)->get("/projects/{$mine->id}")->viewData('page')['props'];
        expect($page['api_key'])->toBe($new);
    });

    it('stops the old key working at once, also when it was cached', function () {
        $project = Project::factory()->create();
        $old = $project->api_key;
        app(ProjectResolver::class)->resolve($old);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put("/projects/{$project->id}/api-key")->assertStatus(303);

        expect(ProjectCache::hasPositive($old))->toBeFalse()
            ->and(app(ProjectResolver::class)->resolve($old))->toBeNull();
    });

    it('is a 404 for a non-member and changes nothing', function () {
        [$member, , $theirs] = memberWithProjects();
        $key = $theirs->api_key;

        $this->actingAs($member)->put("/projects/{$theirs->id}/api-key")->assertNotFound();

        expect($theirs->fresh()->api_key)->toBe($key);
    });

    it('also answers an Inertia PUT with 303', function () {
        [$member, $mine] = memberWithProjects();

        $this->actingAs($member)
            ->put("/projects/{$mine->id}/api-key", [], ['X-Inertia' => 'true', 'X-Inertia-Version' => hash_file('md5', $this->publicPath.'/build/manifest.json')])
            ->assertStatus(303);
    });
});

describe('delete', function () {
    it('lets an administrator delete after typing the name, queues the purge and redirects 303', function () {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->create(['name' => 'Shop']);
        $other = Project::factory()->create();

        $this->actingAs($admin)->delete("/projects/{$project->id}", ['confirm_name' => 'Shop'])
            ->assertStatus(303)->assertRedirect('/projects')
            ->assertSessionHas('status', 'Project deleted. Its events are being removed in the background.');

        expect(Project::whereKey($project->id)->exists())->toBeFalse()
            ->and(Project::whereKey($other->id)->exists())->toBeTrue();
        Queue::assertPushed(PurgeProjectEvents::class, fn (PurgeProjectEvents $job) => $job->projectId === $project->id && $job->pass === 1);
        Queue::assertPushed(PurgeProjectEvents::class, fn (PurgeProjectEvents $job) => $job->projectId === $project->id && $job->pass === 2 && $job->delay === QueueEventsPurge::SECOND_PASS_DELAY_SECONDS);
    });

    it('refuses a missing or wrong confirmation on the server and deletes nothing', function (?string $typed) {
        Queue::fake();
        $project = Project::factory()->create(['name' => 'Shop']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->from("/projects/{$project->id}")->delete("/projects/{$project->id}", ['confirm_name' => $typed])
            ->assertRedirect("/projects/{$project->id}")
            ->assertSessionHasErrors(['confirm_name' => 'Type the project name exactly to confirm.']);

        expect(Project::whereKey($project->id)->exists())->toBeTrue();
        Queue::assertNothingPushed();
    })->with([null, '', 'shop', 'Shop 2', 'Sho']);

    it('answers an Inertia DELETE with 303', function () {
        Queue::fake();
        $project = Project::factory()->create(['name' => 'Shop']);

        $this->actingAs(User::factory()->admin()->create())
            ->delete("/projects/{$project->id}", ['confirm_name' => 'Shop'], ['X-Inertia' => 'true', 'X-Inertia-Version' => hash_file('md5', $this->publicPath.'/build/manifest.json')])
            ->assertStatus(303);
    });

    it('answers 403 to a member, who can see the project but may not delete it', function () {
        Queue::fake();
        [$member, $mine] = memberWithProjects();

        $this->actingAs($member)->delete("/projects/{$mine->id}", ['confirm_name' => 'Mine'])->assertForbidden();

        expect(Project::whereKey($mine->id)->exists())->toBeTrue();
        Queue::assertNothingPushed();
    });

    it('answers 404 to a non-member', function () {
        Queue::fake();
        [$member, , $theirs] = memberWithProjects();

        $this->actingAs($member)->delete("/projects/{$theirs->id}", ['confirm_name' => 'Theirs'])->assertNotFound();

        expect(Project::whereKey($theirs->id)->exists())->toBeTrue();
    });
});

describe('guests and CSRF', function () {
    it('answers a guest with 303 to the login page for every write', function (string $method, string $uri) {
        $project = Project::factory()->create();

        $this->call($method, str_replace('{id}', (string) $project->id, $uri))->assertStatus(303)->assertRedirect('/login');
        expect(Project::count())->toBe(1);
    })->with([['POST', '/projects'], ['PUT', '/projects/{id}/api-key'], ['DELETE', '/projects/{id}']]);

    it('refuses a write without a CSRF token with a 303 back and changes nothing', function (string $method, string $uri) {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->create(['name' => 'Shop']);
        $key = $project->api_key;
        // CSRF is skipped while the application runs as `testing`.
        app()->detectEnvironment(fn () => 'production');

        $this->actingAs($admin)->call($method, str_replace('{id}', (string) $project->id, $uri), ['name' => 'X', 'timezone' => 'UTC', 'confirm_name' => 'Shop'])
            ->assertStatus(303)
            ->assertSessionHas('status', 'The page expired. Please try again.');

        expect(Project::count())->toBe(1)->and($project->fresh()->api_key)->toBe($key);
    })->with([['POST', '/projects'], ['PUT', '/projects/{id}/api-key'], ['DELETE', '/projects/{id}']]);
});

describe('home', function () {
    it('links to project creation when there is no project and to the projects otherwise', function () {
        $this->actingAs(User::factory()->create())->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Home')->where('projects', []));

        [$member, $mine] = memberWithProjects();
        $this->actingAs($member)->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('projects', [['id' => $mine->id, 'name' => 'Mine', 'url' => "/projects/{$mine->id}"]]));
    });
});
