<?php

use App\Actions\Projects\DeleteProject;
use App\Http\ApiErrors;
use App\Models\Dashboard;
use App\Models\DashboardTile;
use App\Models\Project;
use App\Models\SavedInsight;
use App\Models\User;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

/*
 * The dashboard JSON API and its tiles: authorisation matrix, isolation between projects (a dashboard, an insight or a tile
 * of another project or dashboard is a 404 / the same 422), CRUD, idempotent add, the limits, position compaction, reorder
 * and the last_viewed_at throttle. Races on real Postgres are in tests/Integration.
 */

beforeEach(function () {
    config(['insights.cache_store' => 'array']);
    Cache::store('array')->flush();
    LimiterOutage::reset();
    Carbon::setTestNow('2026-03-15 10:00:00 UTC');
    $this->project = Project::factory()->create(['timezone' => 'UTC']);
    $this->member = User::factory()->create(['name' => 'Mia Member']);
    $this->project->users()->attach($this->member);
});

afterEach(fn () => Carbon::setTestNow());

function dbUrl(Project|int $project, string $id = '', string $suffix = ''): string
{
    return '/api/projects/'.($project instanceof Project ? $project->id : $project).'/dashboards'.($id === '' ? '' : "/{$id}").$suffix;
}

function dbCreate(User $user, Project|int $project, array $body): TestResponse
{
    return test()->actingAs($user)->postJson(dbUrl($project), $body);
}

/** @return array<string, mixed> the data of a dashboard made through the API */
function dbMake(User $user, Project $project, string $name = 'Growth', array $o = []): array
{
    return dbCreate($user, $project, ['name' => $name, ...$o])->assertCreated()->json('data');
}

/** A saved insight row (a fixture) of the project; returns its uuid. */
function dbInsight(Project $project, string $name = 'Signups', array $o = []): string
{
    return SavedInsight::factory()->create(['project_id' => $project->id, 'name' => $name, ...$o])->uuid;
}

function dbAddTile(User $user, Project $project, string $dashboard, string $insight): TestResponse
{
    return test()->actingAs($user)->postJson(dbUrl($project, $dashboard, '/tiles'), ['saved_insight_id' => $insight]);
}

/** @return list<string> the insight names of a dashboard, in the order the API shows them */
function dbNames(User $user, Project $project, string $dashboard): array
{
    return array_map(fn (array $tile) => $tile['saved_insight']['name'], test()->actingAs($user)->getJson(dbUrl($project, $dashboard))->json('data.tiles'));
}

// ---------------------------------------------------------------- authorisation

it('answers a guest with 401 JSON on every dashboard route', function (string $method, string $suffix) {
    $uuid = '018f0000-0000-7000-8000-000000000001';

    $response = $this->call($method, dbUrl($this->project, $suffix === '' ? '' : $uuid, str_replace('{id}', '', $suffix)), server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

    expect($response->getStatusCode())->toBe(401)->and($response->headers->has('Location'))->toBeFalse()->and($response->headers->getCookies())->toBe([]);
})->with([
    'list' => ['GET', ''],
    'create' => ['POST', ''],
    'show' => ['GET', '{id}'],
    'update' => ['PATCH', '{id}'],
    'delete' => ['DELETE', '{id}'],
    'add tile' => ['POST', '{id}/tiles'],
    'reorder' => ['PUT', '{id}/tiles/order'],
    'remove tile' => ['DELETE', '{id}/tiles/018f0000-0000-7000-8000-000000000002'],
]);

it('shows a non-member exactly what a missing project looks like, on every dashboard route', function () {
    $outsider = User::factory()->create();
    $dashboard = dbMake($this->member, $this->project);
    $insight = dbInsight($this->project);
    $tile = dbAddTile($this->member, $this->project, $dashboard['id'], $insight)->assertCreated()->json('data.id');

    $calls = [
        fn ($p) => $this->actingAs($outsider)->getJson(dbUrl($p)),
        fn ($p) => $this->actingAs($outsider)->postJson(dbUrl($p), ['name' => 'x']),
        fn ($p) => $this->actingAs($outsider)->getJson(dbUrl($p, $dashboard['id'])),
        fn ($p) => $this->actingAs($outsider)->patchJson(dbUrl($p, $dashboard['id']), ['name' => 'x']),
        fn ($p) => $this->actingAs($outsider)->deleteJson(dbUrl($p, $dashboard['id'])),
        fn ($p) => $this->actingAs($outsider)->postJson(dbUrl($p, $dashboard['id'], '/tiles'), ['saved_insight_id' => $insight]),
        fn ($p) => $this->actingAs($outsider)->putJson(dbUrl($p, $dashboard['id'], '/tiles/order'), ['tiles' => [$tile]]),
        fn ($p) => $this->actingAs($outsider)->deleteJson(dbUrl($p, $dashboard['id'], "/tiles/{$tile}")),
    ];

    foreach ($calls as $call) {
        $forbidden = $call($this->project)->assertNotFound();
        $missing = $call(999999)->assertNotFound();
        expect($forbidden->getContent())->toBe($missing->getContent())->and($forbidden->json())->toBe(ApiErrors::body('not_found', 'Not found.'));
    }
    expect(Dashboard::query()->count())->toBe(1)->and(DashboardTile::query()->count())->toBe(1);
});

it('lets an administrator who is not a member do everything, and any member edit what another made', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();
    $this->project->users()->attach($other);
    $insight = dbInsight($this->project);

    $made = dbMake($admin, $this->project);
    dbAddTile($admin, $this->project, $made['id'], $insight)->assertCreated();
    $this->actingAs($admin)->patchJson(dbUrl($this->project, $made['id']), ['name' => 'By admin'])->assertOk();
    $this->actingAs($other)->patchJson(dbUrl($this->project, $made['id']), ['name' => 'By member'])->assertOk()->assertJsonPath('data.created_by', $admin->name);
    $this->actingAs($other)->deleteJson(dbUrl($this->project, $made['id']))->assertNoContent();
});

it('answers a dashboard of another project, a missing one and a bad id with the same 404, for every verb', function () {
    $other = Project::factory()->create();
    $otherMember = User::factory()->create();
    $other->users()->attach($otherMember);
    $foreign = dbMake($otherMember, $other);
    $missing = '018f0000-0000-7000-8000-00000000dead';
    $numeric = (string) Dashboard::query()->where('uuid', $foreign['id'])->value('id');

    foreach ([
        ['getJson', ''], ['patchJson', '/x'], ['deleteJson', ''], ['postJson', '/tiles'], ['putJson', '/tiles/order'],
    ] as [$verb, $suffix]) {
        $body = in_array($verb, ['getJson', 'deleteJson'], true) ? [] : ['name' => 'x'];
        $path = $suffix === '/x' ? '' : $suffix;
        $a = $this->actingAs($this->member)->{$verb}(dbUrl($this->project, $foreign['id'], $path), ...($body === [] ? [] : [$body]))->assertNotFound();
        $b = $this->actingAs($this->member)->{$verb}(dbUrl($this->project, $missing, $path), ...($body === [] ? [] : [$body]))->assertNotFound();
        expect($a->getContent())->toBe($b->getContent());
    }
    $this->actingAs($this->member)->getJson(dbUrl($this->project, $numeric))->assertNotFound();
    $this->actingAs($this->member)->getJson(dbUrl($this->project, 'x'))->assertNotFound();
    expect(Dashboard::query()->where('uuid', $foreign['id'])->value('name'))->toBe('Growth');
});

// ---------------------------------------------------------------- CRUD

it('creates a dashboard and answers with it, empty and without internal ids', function () {
    $response = dbCreate($this->member, $this->project, ['name' => '  Growth ', 'description' => "Weekly numbers\n"])->assertCreated();
    $data = $response->json('data');

    expect($data['id'])->toMatch('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}\z/')
        ->and($data['name'])->toBe('Growth')
        ->and($data['description'])->toBe('Weekly numbers')
        ->and($data['tiles'])->toBe([])->and($data['tiles_count'])->toBe(0)
        ->and($data['last_viewed_at'])->toBeNull()
        ->and($data['created_by'])->toBe('Mia Member')
        ->and(array_keys($data))->toBe(['id', 'name', 'description', 'tiles_count', 'last_viewed_at', 'created_by', 'created_at', 'updated_at', 'tiles']);

    expect(Dashboard::query()->where('uuid', $data['id'])->firstOrFail()->only('project_id', 'created_by'))->toBe(['project_id' => $this->project->id, 'created_by' => $this->member->id]);
});

it('refuses a bad dashboard body with the field that is wrong', function () {
    foreach ([['name' => ''], ['name' => '   '], ['name' => str_repeat('a', 121)], ['name' => "a\u{200B}b"], ['name' => null], ['name' => ['x']], ['name' => 'ok', 'description' => str_repeat('a', 501)]] as $bad) {
        dbCreate($this->member, $this->project, $bad)->assertStatus(422)->assertJsonPath('error.kind', 'validation');
    }
    dbCreate($this->member, $this->project, ['name' => 'ok', 'project_id' => 1])->assertStatus(422)->assertJsonValidationErrors('body');
    $this->actingAs($this->member)->call('POST', dbUrl($this->project), content: 'name=x')->assertStatus(415);
    $this->actingAs($this->member)->call('POST', dbUrl($this->project), server: ['CONTENT_TYPE' => 'application/json'], content: '{nope')->assertStatus(400);
    expect(Dashboard::query()->count())->toBe(0);
});

it('refuses the dashboard past the limit with a 422 on limit', function () {
    config(['dashboards.max_dashboards_per_project' => 2]);
    dbMake($this->member, $this->project, 'one');
    dbMake($this->member, $this->project, 'two');

    dbCreate($this->member, $this->project, ['name' => 'three'])->assertStatus(422)->assertJsonValidationErrors('limit');
});

it('lists dashboards with their tile counts, newest change first, paged', function () {
    $a = dbMake($this->member, $this->project, 'a');
    Carbon::setTestNow('2026-03-15 10:01:00 UTC');
    $b = dbMake($this->member, $this->project, 'b');
    dbAddTile($this->member, $this->project, $a['id'], dbInsight($this->project));
    Carbon::setTestNow('2026-03-15 10:02:00 UTC');
    $this->actingAs($this->member)->patchJson(dbUrl($this->project, $a['id']), ['name' => 'a renamed'])->assertOk();
    $other = Project::factory()->create();
    $other->users()->attach($this->member);
    dbMake($this->member, $other, 'elsewhere');

    $list = $this->actingAs($this->member)->getJson(dbUrl($this->project))->assertOk();
    expect(array_column($list->json('data'), 'name'))->toBe(['a renamed', 'b'])
        ->and(array_column($list->json('data'), 'tiles_count'))->toBe([1, 0])
        ->and($list->json('data.0'))->not->toHaveKey('tiles')
        ->and($list->json('meta'))->toBe(['page' => 1, 'per_page' => 50, 'total' => 2, 'last_page' => 1]);

    $page = $this->actingAs($this->member)->getJson(dbUrl($this->project).'?per_page=1&page=2')->assertOk();
    expect(array_column($page->json('data'), 'name'))->toBe(['b']);
    $this->actingAs($this->member)->getJson(dbUrl($this->project).'?per_page=0')->assertStatus(422);
});

it('renames and re-describes a dashboard, and refuses empty or unknown bodies', function () {
    $made = dbMake($this->member, $this->project, 'Old', ['description' => 'text']);
    $url = dbUrl($this->project, $made['id']);

    $this->actingAs($this->member)->patchJson($url, ['name' => ' New '])->assertOk()->assertJsonPath('data.name', 'New')->assertJsonPath('data.description', 'text');
    $this->actingAs($this->member)->patchJson($url, ['description' => null])->assertOk()->assertJsonPath('data.description', null);
    $this->actingAs($this->member)->patchJson($url, [])->assertStatus(422)->assertJsonValidationErrors('body');
    $this->actingAs($this->member)->patchJson($url, ['tiles' => []])->assertStatus(422)->assertJsonValidationErrors('body');
    $this->actingAs($this->member)->patchJson($url, ['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
});

it('deletes a dashboard with its tiles and keeps the insights', function () {
    $made = dbMake($this->member, $this->project);
    dbAddTile($this->member, $this->project, $made['id'], dbInsight($this->project))->assertCreated();

    $this->actingAs($this->member)->deleteJson(dbUrl($this->project, $made['id']))->assertNoContent();

    expect(Dashboard::query()->count())->toBe(0)->and(DashboardTile::query()->count())->toBe(0)->and(SavedInsight::query()->count())->toBe(1);
    $this->actingAs($this->member)->deleteJson(dbUrl($this->project, $made['id']))->assertNotFound();
});

// ---------------------------------------------------------------- show

it('shows the dashboard with its tiles in position order, each with the summary of its insight', function () {
    $made = dbMake($this->member, $this->project);
    foreach (['first', 'second', 'third'] as $name) {
        dbAddTile($this->member, $this->project, $made['id'], dbInsight($this->project, $name, ['created_by' => $this->member->id]))->assertCreated();
    }

    $show = $this->actingAs($this->member)->getJson(dbUrl($this->project, $made['id']))->assertOk()->json('data');

    expect(array_column($show['tiles'], 'position'))->toBe([0, 1, 2])
        ->and(array_map(fn ($t) => $t['saved_insight']['name'], $show['tiles']))->toBe(['first', 'second', 'third'])
        ->and($show['tiles_count'])->toBe(3)
        ->and(array_keys($show['tiles'][0]))->toBe(['id', 'position', 'saved_insight'])
        ->and(array_keys($show['tiles'][0]['saved_insight']))->toBe(['id', 'name', 'description', 'type', 'date', 'state', 'created_by', 'created_at', 'updated_at'])
        ->and($show['tiles'][0]['saved_insight']['state']['status'])->toBe('ok')
        ->and($show['tiles'][0]['saved_insight']['created_by'])->toBe('Mia Member');
});

it('shows a tile of an insight that needs review, marked, never a 500', function () {
    $made = dbMake($this->member, $this->project);
    $good = dbInsight($this->project, 'good');
    $stale = dbInsight($this->project, 'stale', ['query' => ['v' => 1], 'query_version' => 1]);
    dbAddTile($this->member, $this->project, $made['id'], $stale)->assertCreated();
    dbAddTile($this->member, $this->project, $made['id'], $good)->assertCreated();

    $tiles = $this->actingAs($this->member)->getJson(dbUrl($this->project, $made['id']))->assertOk()->json('data.tiles');

    expect(array_map(fn ($t) => $t['saved_insight']['state']['status'], $tiles))->toBe(['needs_review', 'ok'])
        ->and($tiles[0]['saved_insight']['state']['reason'])->toBe('outdated_version');
});

it('records the view once per window, and not when the view is refused', function () {
    config(['dashboards.view_touch_seconds' => 300]);
    $made = dbMake($this->member, $this->project);
    $row = fn () => Dashboard::query()->where('uuid', $made['id'])->firstOrFail();
    expect($row()->last_viewed_at)->toBeNull();

    $this->actingAs($this->member)->getJson(dbUrl($this->project, $made['id']))->assertOk();
    expect($row()->last_viewed_at->toIso8601String())->toBe('2026-03-15T10:00:00+00:00');

    Carbon::setTestNow('2026-03-15 10:04:00 UTC');
    $this->actingAs($this->member)->getJson(dbUrl($this->project, $made['id']))->assertOk();
    expect($row()->last_viewed_at->toIso8601String())->toBe('2026-03-15T10:00:00+00:00');

    Carbon::setTestNow('2026-03-15 10:06:00 UTC');
    $show = $this->actingAs($this->member)->getJson(dbUrl($this->project, $made['id']))->assertOk();
    expect($row()->last_viewed_at->toIso8601String())->toBe('2026-03-15T10:06:00+00:00')
        ->and($show->json('data.last_viewed_at'))->toBe('2026-03-15T10:06:00+00:00')
        ->and($row()->updated_at->toIso8601String())->toBe('2026-03-15T10:00:00+00:00');

    // a refused view (a stranger) writes nothing
    Carbon::setTestNow('2026-03-15 11:00:00 UTC');
    $this->actingAs(User::factory()->create())->getJson(dbUrl($this->project, $made['id']))->assertNotFound();
    // and neither does a list, a rename or a tile change
    $this->actingAs($this->member)->getJson(dbUrl($this->project))->assertOk();
    $this->actingAs($this->member)->patchJson(dbUrl($this->project, $made['id']), ['name' => 'x'])->assertOk();
    expect($row()->last_viewed_at->toIso8601String())->toBe('2026-03-15T10:06:00+00:00');
});

// ---------------------------------------------------------------- tiles

it('adds a tile at the end, and adding the same insight again answers the same tile with 200', function () {
    $made = dbMake($this->member, $this->project);
    $a = dbInsight($this->project, 'a');
    $b = dbInsight($this->project, 'b');

    $first = dbAddTile($this->member, $this->project, $made['id'], $a)->assertCreated();
    $second = dbAddTile($this->member, $this->project, $made['id'], $b)->assertCreated();
    $again = dbAddTile($this->member, $this->project, $made['id'], $a)->assertOk();

    expect($first->json('data.position'))->toBe(0)->and($second->json('data.position'))->toBe(1)
        ->and($again->json('data.id'))->toBe($first->json('data.id'))
        ->and($again->json('data.saved_insight.name'))->toBe('a')
        ->and(dbNames($this->member, $this->project, $made['id']))->toBe(['a', 'b'])
        ->and(DashboardTile::query()->count())->toBe(2);
});

it('answers an insight of another project, a missing one and a bad id with the same 422', function () {
    $other = Project::factory()->create();
    $foreign = dbInsight($other);
    $made = dbMake($this->member, $this->project);

    $a = dbAddTile($this->member, $this->project, $made['id'], $foreign)->assertStatus(422);
    $b = dbAddTile($this->member, $this->project, $made['id'], '018f0000-0000-7000-8000-00000000dead')->assertStatus(422);
    $c = $this->actingAs($this->member)->postJson(dbUrl($this->project, $made['id'], '/tiles'), ['saved_insight_id' => 'nope'])->assertStatus(422);

    expect($a->json('errors.saved_insight_id'))->toBe($b->json('errors.saved_insight_id'))->and($c->json('errors'))->toHaveKey('saved_insight_id');
    $this->actingAs($this->member)->postJson(dbUrl($this->project, $made['id'], '/tiles'), [])->assertStatus(422)->assertJsonValidationErrors('saved_insight_id');
    $this->actingAs($this->member)->postJson(dbUrl($this->project, $made['id'], '/tiles'), ['saved_insight_id' => dbInsight($this->project), 'position' => 0])->assertStatus(422)->assertJsonValidationErrors('body');
    expect(DashboardTile::query()->count())->toBe(0);
});

it('refuses a tile past the limit of the dashboard with a 422 on limit, but still answers an existing one', function () {
    config(['dashboards.max_tiles_per_dashboard' => 2]);
    $made = dbMake($this->member, $this->project);
    $ids = [dbInsight($this->project, 'a'), dbInsight($this->project, 'b'), dbInsight($this->project, 'c')];
    dbAddTile($this->member, $this->project, $made['id'], $ids[0])->assertCreated();
    dbAddTile($this->member, $this->project, $made['id'], $ids[1])->assertCreated();

    dbAddTile($this->member, $this->project, $made['id'], $ids[2])->assertStatus(422)->assertJsonValidationErrors('limit');
    dbAddTile($this->member, $this->project, $made['id'], $ids[0])->assertOk();
    // another dashboard has its own room
    dbAddTile($this->member, $this->project, dbMake($this->member, $this->project, 'other')['id'], $ids[2])->assertCreated();
});

it('removes a tile, closes the gap, keeps the insight, and a tile of another dashboard is a 404', function () {
    $one = dbMake($this->member, $this->project, 'one');
    $two = dbMake($this->member, $this->project, 'two');
    $tiles = [];
    foreach (['a', 'b', 'c'] as $name) {
        $tiles[$name] = dbAddTile($this->member, $this->project, $one['id'], dbInsight($this->project, $name))->assertCreated()->json('data.id');
    }
    $onTwo = dbAddTile($this->member, $this->project, $two['id'], dbInsight($this->project, 'z'))->assertCreated()->json('data.id');

    // the tile belongs to dashboard two: through dashboard one it does not exist
    $this->actingAs($this->member)->deleteJson(dbUrl($this->project, $one['id'], "/tiles/{$onTwo}"))->assertNotFound();
    $this->actingAs($this->member)->deleteJson(dbUrl($this->project, $one['id'], '/tiles/018f0000-0000-7000-8000-00000000dead'))->assertNotFound();
    expect(DashboardTile::query()->count())->toBe(4);

    $this->actingAs($this->member)->deleteJson(dbUrl($this->project, $one['id'], "/tiles/{$tiles['b']}"))->assertNoContent();

    $shown = $this->actingAs($this->member)->getJson(dbUrl($this->project, $one['id']))->json('data.tiles');
    expect(array_column($shown, 'position'))->toBe([0, 1])->and(array_map(fn ($t) => $t['saved_insight']['name'], $shown))->toBe(['a', 'c'])
        ->and(SavedInsight::query()->count())->toBe(4);
    $this->actingAs($this->member)->deleteJson(dbUrl($this->project, $one['id'], "/tiles/{$tiles['b']}"))->assertNotFound();
});

it('reorders the tiles to exactly the given order and answers with them', function () {
    $made = dbMake($this->member, $this->project);
    $ids = [];
    foreach (['a', 'b', 'c'] as $name) {
        $ids[$name] = dbAddTile($this->member, $this->project, $made['id'], dbInsight($this->project, $name))->assertCreated()->json('data.id');
    }

    $response = $this->actingAs($this->member)->putJson(dbUrl($this->project, $made['id'], '/tiles/order'), ['tiles' => [$ids['c'], $ids['a'], $ids['b']]])->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$ids['c'], $ids['a'], $ids['b']])
        ->and(array_column($response->json('data'), 'position'))->toBe([0, 1, 2])
        ->and(dbNames($this->member, $this->project, $made['id']))->toBe(['c', 'a', 'b']);
});

it('refuses an order that is not exactly the current set, and changes nothing', function (Closure $tiles) {
    $made = dbMake($this->member, $this->project);
    $ids = [];
    foreach (['a', 'b'] as $name) {
        $ids[] = dbAddTile($this->member, $this->project, $made['id'], dbInsight($this->project, $name))->assertCreated()->json('data.id');
    }
    $otherDashboard = dbMake($this->member, $this->project, 'other');
    $foreign = dbAddTile($this->member, $this->project, $otherDashboard['id'], dbInsight($this->project, 'x'))->assertCreated()->json('data.id');

    $this->actingAs($this->member)->putJson(dbUrl($this->project, $made['id'], '/tiles/order'), ['tiles' => $tiles($ids, $foreign)])->assertStatus(422)->assertJsonPath('error.kind', 'validation');

    expect(dbNames($this->member, $this->project, $made['id']))->toBe(['a', 'b']);
})->with([
    'one missing' => [fn (array $i) => [$i[0]]],
    'a duplicate' => [fn (array $i) => [$i[0], $i[0]]],
    'a tile of another dashboard' => [fn (array $i, string $f) => [$i[0], $f]],
    'an extra one' => [fn (array $i, string $f) => [...$i, $f]],
    'empty' => [fn () => []],
    'not uuids' => [fn () => ['x', 'y']],
    'a map instead of a list' => [fn (array $i) => ['a' => $i[0], 'b' => $i[1]]],
]);

it('refuses a reorder without the tiles key, with other keys, or as a scalar', function () {
    $made = dbMake($this->member, $this->project);
    $url = dbUrl($this->project, $made['id'], '/tiles/order');

    $this->actingAs($this->member)->putJson($url, [])->assertStatus(422)->assertJsonValidationErrors('tiles');
    $this->actingAs($this->member)->putJson($url, ['tiles' => [], 'x' => 1])->assertStatus(422)->assertJsonValidationErrors('body');
    $this->actingAs($this->member)->putJson($url, ['tiles' => 'a'])->assertStatus(422)->assertJsonValidationErrors('tiles');
    $this->actingAs($this->member)->putJson($url, ['tiles' => []])->assertOk()->assertJsonPath('data', []);
});

it('puts the same insight on several dashboards and removes it from one without touching the other', function () {
    $insight = dbInsight($this->project);
    $one = dbMake($this->member, $this->project, 'one');
    $two = dbMake($this->member, $this->project, 'two');
    $tile = dbAddTile($this->member, $this->project, $one['id'], $insight)->assertCreated()->json('data.id');
    dbAddTile($this->member, $this->project, $two['id'], $insight)->assertCreated();

    $this->actingAs($this->member)->deleteJson(dbUrl($this->project, $one['id'], "/tiles/{$tile}"))->assertNoContent();

    expect(dbNames($this->member, $this->project, $one['id']))->toBe([])->and(dbNames($this->member, $this->project, $two['id']))->toBe(['Signups']);
});

it('keeps the tiles of two dashboards apart when both are reordered', function () {
    $one = dbMake($this->member, $this->project, 'one');
    $two = dbMake($this->member, $this->project, 'two');
    $a = dbAddTile($this->member, $this->project, $one['id'], dbInsight($this->project, 'a'))->json('data.id');
    $b = dbAddTile($this->member, $this->project, $one['id'], dbInsight($this->project, 'b'))->json('data.id');
    $c = dbAddTile($this->member, $this->project, $two['id'], dbInsight($this->project, 'c'))->json('data.id');
    $d = dbAddTile($this->member, $this->project, $two['id'], dbInsight($this->project, 'd'))->json('data.id');

    $this->actingAs($this->member)->putJson(dbUrl($this->project, $one['id'], '/tiles/order'), ['tiles' => [$b, $a]])->assertOk();

    expect(dbNames($this->member, $this->project, $one['id']))->toBe(['b', 'a'])->and(dbNames($this->member, $this->project, $two['id']))->toBe(['c', 'd']);
    // a tile of dashboard one cannot be moved through dashboard two
    $this->actingAs($this->member)->putJson(dbUrl($this->project, $two['id'], '/tiles/order'), ['tiles' => [$a, $d]])->assertStatus(422);
});

// ---------------------------------------------------------------- cascade

it('deletes the insights, dashboards and tiles of a project with the project, and nothing of another', function () {
    $other = Project::factory()->create();
    $otherMember = User::factory()->create();
    $other->users()->attach($otherMember);
    $kept = dbMake($otherMember, $other);
    dbAddTile($otherMember, $other, $kept['id'], dbInsight($other))->assertCreated();
    $made = dbMake($this->member, $this->project);
    dbAddTile($this->member, $this->project, $made['id'], dbInsight($this->project))->assertCreated();

    expect(app(DeleteProject::class)->handle($this->project, purgeEvents: false)->deleted)->toBeTrue();

    expect(Dashboard::query()->pluck('uuid')->all())->toBe([$kept['id']])
        ->and(SavedInsight::query()->where('project_id', $this->project->id)->count())->toBe(0)
        ->and(SavedInsight::query()->where('project_id', $other->id)->count())->toBe(1)
        ->and(DashboardTile::query()->count())->toBe(1);
});

it('keeps the dashboards and insights of a deleted user, shown without a creator', function () {
    $author = User::factory()->create(['name' => 'Gone Soon']);
    $this->project->users()->attach($author);
    $made = dbMake($author, $this->project);

    $author->delete();

    $this->actingAs($this->member)->getJson(dbUrl($this->project, $made['id']))->assertOk()->assertJsonPath('data.created_by', null);
});

it('sends no cache headers on any answer', function () {
    $made = dbMake($this->member, $this->project);

    expect($this->actingAs($this->member)->getJson(dbUrl($this->project, $made['id']))->headers->get('Cache-Control'))->toContain('no-store');
});
