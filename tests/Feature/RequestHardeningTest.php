<?php

use App\Http\Requests\Concerns\ResolvesVisibleProject;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;

describe('malformed UTF-8', function () {
    it('answers 400 and keeps the session when a project name is not valid UTF-8', function () {
        $user = User::factory()->create();

        // The bytes `curl --data-binary 'name=rv-%FF%FE'` sends, as PHP parses them into the request.
        $this->actingAs($user)->post('/projects', ['name' => "rv-\xFF\xFE", 'timezone' => 'UTC'])->assertStatus(400);

        expect(Project::count())->toBe(0);
        $this->assertAuthenticatedAs($user);
        $this->actingAs($user)->get('/projects')->assertOk();
    });

    it('rejects it in the query string, in nested input and in a JSON body', function (string $method, string $uri, array $parameters, ?string $content) {
        $this->actingAs(User::factory()->create())
            ->call($method, $uri, $parameters, [], [], $content === null ? [] : ['CONTENT_TYPE' => 'application/json'], $content)
            ->assertStatus(400);
    })->with([
        'query' => ['GET', '/projects?q=%FF', [], null],
        'nested' => ['POST', '/projects', ['a' => ['b' => "x\xFF"]], null],
        'array key' => ['POST', '/projects', ["k\xFF" => 'v'], null],
        'json' => ['POST', '/projects', [], "{\"name\":\"x\xFF\"}"],
    ]);

    it('also protects the login and register forms, and a guest never gets a session for it', function (string $uri) {
        $response = $this->post($uri, ['email' => "a\xFF@example.test", 'password' => 'whatever-long-1']);

        $response->assertStatus(400);
        expect($response->headers->getCookies())->toBe([]);
    })->with(['/login', '/register']);

    it('does not touch capture routes, which have their own payload handling', function () {
        $response = $this->post('/e/', ['a' => "\xFF"]);

        // The capture decoder answers (invalid payload or unauthenticated JSON), never the web middleware's 400 text.
        expect($response->headers->get('Content-Type'))->toContain('application/json')
            ->and($response->getContent())->not->toContain('not valid UTF-8');
    });

    it('accepts valid UTF-8 of every kind', function () {
        $this->actingAs(User::factory()->create())->post('/projects', ['name' => 'Mağaza 商店 🛒', 'timezone' => 'UTC'])->assertStatus(303);
    });

    it('does not flash the project name or the delete confirmation into the session', function () {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->create(['name' => 'Shop']);

        $this->actingAs($admin)->from('/projects/create')->post('/projects', ['name' => str_repeat('a', 101), 'timezone' => 'UTC'])
            ->assertSessionHasErrors('name')->assertSessionMissing('_old_input.name');

        $this->actingAs($admin)->from("/projects/{$project->id}")->delete("/projects/{$project->id}", ['confirm_name' => 'wrong'])
            ->assertSessionHasErrors('confirm_name')->assertSessionMissing('_old_input.confirm_name');
    });
});

describe('route binding', function () {
    it('applies the digits-only pattern to every route that has a {project}', function () {
        Route::middleware('web')->get('/t-project/{project}', fn (Project $project) => 'ok');

        $this->actingAs(User::factory()->admin()->create())->get('/t-project/abc')->assertNotFound();
        $this->actingAs(User::factory()->admin()->create())->get('/t-project/99999999999999999999')->assertNotFound();
    });

    it('answers 404 when there is no user at all, instead of resolving anything', function () {
        $project = Project::factory()->create();
        // A route outside `auth`: the binder itself must not hand a project to a guest.
        Route::middleware('web')->get('/t-open/{project}', fn (Project $project) => 'leaked '.$project->name);

        $this->get("/t-open/{$project->id}")->assertNotFound();
    });

    it('resolves for the user of the default guard, whichever way the guard was set', function () {
        $member = User::factory()->create();
        $project = Project::factory()->create();
        $project->users()->attach($member);
        Route::middleware('web')->get('/t-open/{project}', fn (Project $project) => 'ok '.$project->id);

        $this->actingAs($member, 'web')->get("/t-open/{$project->id}")->assertOk()->assertSee("ok {$project->id}");
        $this->actingAs(User::factory()->create(), 'web')->get("/t-open/{$project->id}")->assertNotFound();
    });
});

/** A form request that takes the project id from the body, the way 3.6 endpoints will. */
class ProbeProjectRequest extends FormRequest
{
    use ResolvesVisibleProject;

    public function authorize(): bool
    {
        $this->visibleProject($this->input('project_id'));

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}

describe('ResolvesVisibleProject', function () {
    beforeEach(function () {
        Route::middleware(['web', 'auth'])->post('/t-body', fn (ProbeProjectRequest $request) => response()->json(['project' => $request->input('project_id')]));
    });

    it('lets a member and an administrator use a project id from the body', function () {
        $project = Project::factory()->create();
        $member = User::factory()->create();
        $project->users()->attach($member);

        $this->actingAs($member)->postJson('/t-body', ['project_id' => $project->id])->assertOk();
        $this->actingAs(User::factory()->admin()->create())->postJson('/t-body', ['project_id' => (string) $project->id])->assertOk();
    });

    it('answers 404, never 403 or a validation message, for a project the user cannot open, a missing one and malformed ids', function (mixed $id) {
        $project = Project::factory()->create();
        $outsider = User::factory()->create();
        $payload = $id === 'REAL' ? $project->id : $id;

        $response = $this->actingAs($outsider)->postJson('/t-body', ['project_id' => $payload]);

        $response->assertNotFound();
        expect($response->getContent())->not->toContain('project_id');
    })->with(['REAL', 999999, 'abc', '1.5', '-1', '', null, [[]], '99999999999999999999']);

    it('answers 404 without a project_id at all', function () {
        $this->actingAs(User::factory()->create())->postJson('/t-body', [])->assertNotFound();
    });
});
