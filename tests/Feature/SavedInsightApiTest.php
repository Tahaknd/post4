<?php

use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Http\ApiErrors;
use App\Jobs\RunInsight;
use App\Jobs\RunTrendsInsight;
use App\Models\Project;
use App\Models\SavedInsight;
use App\Models\User;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\SavedInputs;
use Tests\Support\ScriptedReads;
use Tests\Support\TrendsFixtures as T;

/*
 * The saved insight JSON API: authorisation matrix (guest 401, non-member 404, administrator allowed), cross-project
 * isolation, validation through the request class of each type, the state policy (needs_review, never a 500) and the run
 * endpoint, which is the insight run contract with the stored query. Store: the in-memory array store; the queue is faked
 * unless a test lets the job run inline (the phpunit queue is `sync`) with a scripted ClickHouse.
 */

beforeEach(function () {
    config([
        'insights.cache_store' => 'array',
        'insights.cache_ttl_seconds' => 60,
        'insights.max_active_runs_per_project' => 20,
        'insights.max_active_runs_per_user' => 50,
        'dashboards.refresh.result_ttl_seconds' => 2100,
    ]);
    Cache::store('array')->flush();
    LimiterOutage::reset();
    InsightRunner::resetWarningThrottle();
    Carbon::setTestNow('2026-03-15 10:00:00 UTC');
    $this->project = Project::factory()->create(['timezone' => 'UTC']);
    $this->member = User::factory()->create(['name' => 'Mia Member']);
    $this->project->users()->attach($this->member);
});

afterEach(fn () => Carbon::setTestNow());

function siBody(string $type = 'trends', array $o = [], array $query = []): array
{
    return [...['name' => 'Weekly signups', 'type' => $type, 'query' => SavedInputs::for($type, $query)], ...$o];
}

function siCreate(User $user, Project|int $project, array $body): TestResponse
{
    return test()->actingAs($user)->postJson('/api/projects/'.($project instanceof Project ? $project->id : $project).'/saved-insights', $body);
}

/** A saved insight made through the API; returns the data of the response. */
function siMake(User $user, Project $project, string $type = 'trends', array $o = [], array $query = []): array
{
    return siCreate($user, $project, siBody($type, $o, $query))->assertCreated()->json('data');
}

/** The budget of a throttle family is set through the whole map (family names contain a dot, so a dotted config path would nest). */
function siBudget(string $family, int $limit): void
{
    $budgets = config('ratelimit.user_throttles');
    $budgets[$family]['limit'] = $limit;
    config(['ratelimit.user_throttles' => $budgets]);
}

function siUrl(Project|int $project, string $id = '', string $suffix = ''): string
{
    return '/api/projects/'.($project instanceof Project ? $project->id : $project).'/saved-insights'.($id === '' ? '' : "/{$id}").$suffix;
}

// ---------------------------------------------------------------- authorisation

it('answers a guest with 401 JSON on every route, never a redirect or a session', function (string $method, string $suffix) {
    $uuid = '018f0000-0000-7000-8000-000000000001';
    // '' is the collection, '/{id}' one insight, '/{id}/run' its run
    $url = str_contains($suffix, '{id}') ? siUrl($this->project, $uuid, str_replace('/{id}', '', $suffix)) : siUrl($this->project);

    $response = $this->call($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

    expect($response->getStatusCode())->toBe(401)
        ->and($response->headers->has('Location'))->toBeFalse()
        ->and($response->json('error.kind'))->toBe('unauthenticated')
        ->and($response->headers->getCookies())->toBe([]);
})->with([
    'list' => ['GET', ''],
    'create' => ['POST', ''],
    'show' => ['GET', '/{id}'],
    'update' => ['PATCH', '/{id}'],
    'delete' => ['DELETE', '/{id}'],
    'run' => ['POST', '/{id}/run'],
]);

it('shows a non-member exactly what a missing project looks like, on every route', function () {
    $outsider = User::factory()->create();
    $insight = siMake($this->member, $this->project);
    $id = $insight['id'];

    $calls = [
        fn ($project) => $this->actingAs($outsider)->getJson(siUrl($project)),
        fn ($project) => $this->actingAs($outsider)->postJson(siUrl($project), siBody()),
        fn ($project) => $this->actingAs($outsider)->getJson(siUrl($project, $id)),
        fn ($project) => $this->actingAs($outsider)->patchJson(siUrl($project, $id), ['name' => 'x']),
        fn ($project) => $this->actingAs($outsider)->deleteJson(siUrl($project, $id)),
        fn ($project) => $this->actingAs($outsider)->postJson(siUrl($project, $id, '/run')),
    ];

    foreach ($calls as $call) {
        $forbidden = $call($this->project)->assertNotFound();
        $missing = $call(999999)->assertNotFound();
        expect($forbidden->getContent())->toBe($missing->getContent())->and($forbidden->json())->toBe(ApiErrors::body('not_found', 'Not found.'));
    }
    expect(SavedInsight::query()->count())->toBe(1)->and(SavedInsight::query()->first()->name)->toBe('Weekly signups');
});

it('lets an administrator who is not a member list, create, edit, run and delete', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();

    $created = siCreate($admin, $this->project, siBody())->assertCreated()->json('data');
    $this->actingAs($admin)->getJson(siUrl($this->project))->assertOk()->assertJsonPath('meta.total', 1);
    $this->actingAs($admin)->patchJson(siUrl($this->project, $created['id']), ['name' => 'Renamed'])->assertOk();
    $this->actingAs($admin)->postJson(siUrl($this->project, $created['id'], '/run'))->assertStatus(202);
    $this->actingAs($admin)->deleteJson(siUrl($this->project, $created['id']))->assertNoContent();
});

it('lets any member of the project edit and delete what another member made', function () {
    $other = User::factory()->create();
    $this->project->users()->attach($other);
    $made = siMake($this->member, $this->project);

    $this->actingAs($other)->patchJson(siUrl($this->project, $made['id']), ['name' => 'By someone else'])->assertOk()->assertJsonPath('data.created_by', 'Mia Member');
    $this->actingAs($other)->deleteJson(siUrl($this->project, $made['id']))->assertNoContent();
});

it('answers an insight of another project, a missing one and a malformed id with the same 404', function () {
    $other = Project::factory()->create();
    $otherMember = User::factory()->create();
    $other->users()->attach($otherMember);
    $foreign = siMake($otherMember, $other);
    $own = siMake($this->member, $this->project);
    $missing = '018f0000-0000-7000-8000-00000000dead';

    foreach (['getJson', 'deleteJson'] as $verb) {
        $a = $this->actingAs($this->member)->{$verb}(siUrl($this->project, $foreign['id']))->assertNotFound();
        $b = $this->actingAs($this->member)->{$verb}(siUrl($this->project, $missing))->assertNotFound();
        expect($a->getContent())->toBe($b->getContent());
    }
    $this->actingAs($this->member)->patchJson(siUrl($this->project, $foreign['id']), ['name' => 'hijack'])->assertNotFound();
    $this->actingAs($this->member)->postJson(siUrl($this->project, $foreign['id'], '/run'))->assertNotFound();
    // the numeric id never works, nor does anything that is not a uuid
    $numeric = SavedInsight::query()->where('uuid', $own['id'])->value('id');
    $this->actingAs($this->member)->getJson(siUrl($this->project, (string) $numeric))->assertNotFound();
    $this->actingAs($this->member)->getJson(siUrl($this->project, 'not-a-uuid'))->assertNotFound();
    $this->actingAs($this->member)->deleteJson(siUrl($this->project, strtoupper($own['id'])))->assertNotFound();

    expect(SavedInsight::query()->where('uuid', $foreign['id'])->value('name'))->toBe('Weekly signups')->and(SavedInsight::query()->count())->toBe(2);
});

// ---------------------------------------------------------------- create

it('saves an insight of every type and answers with its definition, its state and no internal ids', function (string $type) {
    $response = siCreate($this->member, $this->project, siBody($type, ['description' => '  What we track  ']))->assertCreated();
    $data = $response->json('data');

    expect($data['id'])->toMatch('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}\z/')
        ->and($data['name'])->toBe('Weekly signups')
        ->and($data['description'])->toBe('What we track')
        ->and($data['type'])->toBe($type)
        ->and($data['query']['v'])->toBe($data['query_version'])
        ->and($data['date'])->toBe(['preset' => 'last_7_days', 'timezone' => 'UTC'])
        ->and($data['state'])->toBe(['status' => 'ok', 'reason' => null, 'migrated' => false])
        ->and($data['created_by'])->toBe('Mia Member')
        ->and(array_keys($data))->toBe(['id', 'name', 'description', 'type', 'query', 'query_version', 'date', 'state', 'created_by', 'created_at', 'updated_at']);

    $row = SavedInsight::query()->where('uuid', $data['id'])->firstOrFail();
    expect($row->project_id)->toBe($this->project->id)->and($row->created_by)->toBe($this->member->id)->and($row->query)->toBe($data['query']);
    // the response never carries the numeric id of the row or of the project
    expect(json_encode($response->json()))->not->toContain('"project_id"')->and($response->json('data'))->not->toHaveKey('project_id');
})->with(['trends', 'funnel', 'retention']);

it('stores the same canonical definition the insight endpoint would run for the same body', function () {
    $over = ['date_preset' => null, 'date_from' => '2026-03-01', 'date_to' => '2026-03-07', 'events' => ['signup', 'purchase']];
    $body = SavedInputs::for('trends', $over);
    $data = siMake($this->member, $this->project, query: $over);

    expect($data['query'])->toBe(TrendsQuery::fromInput($body, new DateTimeZone('UTC'), now())->canonical())
        ->and($data['date']['preset'])->toBeNull();
});

it('keeps the exact strings of a definition: spaces, and an empty filter value', function () {
    $data = siMake($this->member, $this->project, query: ['events' => ['  sign up '], 'filters' => [['key' => ' plan', 'operator' => 'equals', 'value' => '']]]);

    expect($data['query']['events'])->toBe(['  sign up '])->and($data['query']['filters'])->toBe([['key' => ' plan', 'operator' => 'equals', 'value' => '']]);
});

it('uses the zone of the project, or the one the query names, for a relative range', function () {
    $istanbul = Project::factory()->create(['timezone' => 'Europe/Istanbul']);
    $istanbul->users()->attach($this->member);

    expect(siMake($this->member, $istanbul)['date']['timezone'])->toBe('Europe/Istanbul')
        ->and(siMake($this->member, $istanbul, query: ['timezone' => 'Asia/Tokyo'])['date']['timezone'])->toBe('Asia/Tokyo');
});

it('trims the name, stores a blank description as null and refuses what a name must not be', function () {
    $ok = siMake($this->member, $this->project, o: ['name' => "  \u{00A0}Padded name  ", 'description' => '   ']);
    expect($ok['name'])->toBe('Padded name')->and($ok['description'])->toBeNull();

    foreach ([['name' => ''], ['name' => '   '], ['name' => str_repeat('a', 121)], ['name' => "a\u{202E}b"], ['name' => 5], ['name' => null], ['description' => str_repeat('a', 501)], ['description' => 5]] as $bad) {
        siCreate($this->member, $this->project, siBody(o: $bad))->assertStatus(422)->assertJsonPath('error.kind', 'validation');
    }
    expect(SavedInsight::query()->count())->toBe(1);
});

it('refuses a body that is not JSON, too large, malformed or not an object, and unknown keys', function () {
    $url = siUrl($this->project);

    $this->actingAs($this->member)->call('POST', $url, content: 'name=x')->assertStatus(415);
    $this->actingAs($this->member)->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{nope')->assertStatus(400);
    $this->actingAs($this->member)->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '')->assertStatus(400);
    $this->actingAs($this->member)->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => str_repeat('a', 80000)]))->assertStatus(413);
    $this->actingAs($this->member)->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '[1,2]')->assertStatus(422)->assertJsonValidationErrors('body');
    siCreate($this->member, $this->project, siBody(o: ['project_id' => 1]))->assertStatus(422)->assertJsonValidationErrors('body');
    siCreate($this->member, $this->project, siBody(o: ['created_by' => 1]))->assertStatus(422)->assertJsonValidationErrors('body');
    expect(SavedInsight::query()->count())->toBe(0);
});

it('refuses an unknown type, a missing or malformed query, and says which field', function () {
    siCreate($this->member, $this->project, siBody(o: ['type' => 'paths']))->assertStatus(422)->assertJsonValidationErrors('type');
    siCreate($this->member, $this->project, ['name' => 'x', 'type' => 'trends'])->assertStatus(422)->assertJsonValidationErrors('query');
    siCreate($this->member, $this->project, siBody(o: ['query' => 'last 7 days']))->assertStatus(422)->assertJsonValidationErrors('query');
    siCreate($this->member, $this->project, siBody(o: ['query' => []]))->assertStatus(422)->assertJsonValidationErrors('query');
    siCreate($this->member, $this->project, ['name' => 'x', 'query' => SavedInputs::for('trends')])->assertStatus(422)->assertJsonValidationErrors('type');
});

it('validates the query with the request class of its type: same sentences, under query.', function () {
    $trends = siCreate($this->member, $this->project, siBody(query: ['events' => []]))->assertStatus(422);
    expect($trends->json('errors'))->toHaveKey('query.events')
        ->and($trends->json('errors')['query.events'][0])->toBe('Provide a list of 1 to 10 event names.');

    $funnel = siCreate($this->member, $this->project, siBody('funnel', query: ['steps' => [['event' => 'a']]]))->assertStatus(422);
    expect(array_keys($funnel->json('errors'))[0])->toStartWith('query.');

    siCreate($this->member, $this->project, siBody(query: ['date_preset' => 'yesterday']))->assertStatus(422)->assertJsonValidationErrors('query.date_preset');
    siCreate($this->member, $this->project, siBody(query: ['date_from' => '2026-03-09', 'date_to' => '2026-03-01', 'date_preset' => null]))->assertStatus(422)->assertJsonValidationErrors('query.date_from');
    siCreate($this->member, $this->project, siBody(query: ['surprise' => 1]))->assertStatus(422)->assertJsonValidationErrors('query.body');
    // a retention body is not a trends body
    siCreate($this->member, $this->project, siBody('trends', ['query' => SavedInputs::for('retention')]))->assertStatus(422);
    expect(SavedInsight::query()->count())->toBe(0);
});

it('never echoes what the caller sent in an error', function () {
    $marker = 'ZZ-HOSTILE-MARKER';
    $response = siCreate($this->member, $this->project, siBody(query: ['events' => [$marker, $marker], 'metric' => $marker]))->assertStatus(422);

    expect($response->getContent())->not->toContain($marker);
});

it('refuses the saved insight past the limit with a 422 on limit', function () {
    config(['dashboards.max_saved_insights_per_project' => 2]);
    siMake($this->member, $this->project);
    siMake($this->member, $this->project);

    siCreate($this->member, $this->project, siBody())->assertStatus(422)->assertJsonValidationErrors('limit');
    // another project is unaffected
    $other = Project::factory()->create();
    $other->users()->attach($this->member);
    siMake($this->member, $other);
});

// ---------------------------------------------------------------- list and show

it('lists newest change first with a page, a size and a type filter, and never the query', function () {
    $first = siMake($this->member, $this->project, o: ['name' => 'first']);
    Carbon::setTestNow('2026-03-15 10:01:00 UTC');
    $second = siMake($this->member, $this->project, 'funnel', ['name' => 'second']);
    Carbon::setTestNow('2026-03-15 10:02:00 UTC');
    siMake($this->member, $this->project, 'retention', ['name' => 'third']);
    Carbon::setTestNow('2026-03-15 10:03:00 UTC');
    $this->actingAs($this->member)->patchJson(siUrl($this->project, $first['id']), ['name' => 'first, renamed'])->assertOk();

    $all = $this->actingAs($this->member)->getJson(siUrl($this->project))->assertOk();
    expect(array_column($all->json('data'), 'name'))->toBe(['first, renamed', 'third', 'second'])
        ->and($all->json('meta'))->toBe(['page' => 1, 'per_page' => 50, 'total' => 3, 'last_page' => 1])
        ->and($all->json('data.0'))->not->toHaveKey('query');

    $paged = $this->actingAs($this->member)->getJson(siUrl($this->project).'?per_page=2&page=2')->assertOk();
    expect(array_column($paged->json('data'), 'name'))->toBe(['second'])->and($paged->json('meta'))->toBe(['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);

    $funnels = $this->actingAs($this->member)->getJson(siUrl($this->project).'?type=funnel')->assertOk();
    expect(array_column($funnels->json('data'), 'id'))->toBe([$second['id']])->and($funnels->json('meta.total'))->toBe(1);
});

it('refuses bad paging instead of clamping it', function (string $query) {
    $this->actingAs($this->member)->getJson(siUrl($this->project).$query)->assertStatus(422)->assertJsonPath('error.kind', 'validation');
})->with(['?page=0', '?page=x', '?per_page=0', '?per_page=101', '?per_page=-1', '?type=paths', '?page[]=1']);

it('lists only the insights of the project in the URL', function () {
    $other = Project::factory()->create();
    $other->users()->attach($this->member);
    siMake($this->member, $other, o: ['name' => 'elsewhere']);
    siMake($this->member, $this->project, o: ['name' => 'here']);

    $names = array_column($this->actingAs($this->member)->getJson(siUrl($this->project))->json('data'), 'name');
    expect($names)->toBe(['here']);
});

it('shows one insight with its stored query', function () {
    $made = siMake($this->member, $this->project, 'retention');

    $shown = $this->actingAs($this->member)->getJson(siUrl($this->project, $made['id']))->assertOk()->json('data');

    expect($shown)->toBe($made);
});

it('sends no cache headers on any answer', function () {
    $made = siMake($this->member, $this->project);

    expect($this->actingAs($this->member)->getJson(siUrl($this->project, $made['id']))->headers->get('Cache-Control'))->toContain('no-store');
});

// ---------------------------------------------------------------- update and delete

it('renames, re-describes and re-defines, and keeps the type', function () {
    $made = siMake($this->member, $this->project);

    $renamed = $this->actingAs($this->member)->patchJson(siUrl($this->project, $made['id']), ['name' => ' New name ', 'description' => 'Text'])->assertOk()->json('data');
    expect($renamed['name'])->toBe('New name')->and($renamed['description'])->toBe('Text')->and($renamed['query'])->toBe($made['query']);

    $redefined = $this->actingAs($this->member)->patchJson(siUrl($this->project, $made['id']), ['query' => SavedInputs::for('trends', ['events' => ['purchase'], 'date_preset' => 'last_30_days'])])->assertOk()->json('data');
    expect($redefined['query']['events'])->toBe(['purchase'])->and($redefined['date']['preset'])->toBe('last_30_days')->and($redefined['name'])->toBe('New name')->and($redefined['type'])->toBe('trends');

    $cleared = $this->actingAs($this->member)->patchJson(siUrl($this->project, $made['id']), ['description' => null])->assertOk()->json('data');
    expect($cleared['description'])->toBeNull();
});

it('refuses an empty update, a type change, unknown keys and a query of another type', function () {
    $made = siMake($this->member, $this->project);
    $url = siUrl($this->project, $made['id']);

    $this->actingAs($this->member)->patchJson($url, [])->assertStatus(422)->assertJsonValidationErrors('body');
    $this->actingAs($this->member)->patchJson($url, ['type' => 'funnel'])->assertStatus(422)->assertJsonValidationErrors('body');
    $this->actingAs($this->member)->patchJson($url, ['project_id' => 3, 'name' => 'x'])->assertStatus(422)->assertJsonValidationErrors('body');
    $this->actingAs($this->member)->patchJson($url, ['query' => SavedInputs::for('funnel')])->assertStatus(422);
    $this->actingAs($this->member)->patchJson($url, ['query' => ['events' => []]])->assertStatus(422)->assertJsonValidationErrors('query.events');
    $this->actingAs($this->member)->patchJson($url, ['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
    $this->actingAs($this->member)->call('PATCH', $url, content: 'name=x')->assertStatus(415);

    expect(SavedInsight::query()->first()->only('name', 'type'))->toBe(['name' => 'Weekly signups', 'type' => 'trends']);
});

it('deletes an insight, and a second delete is a 404 like any missing one', function () {
    $made = siMake($this->member, $this->project);

    $this->actingAs($this->member)->deleteJson(siUrl($this->project, $made['id']))->assertNoContent();
    expect(SavedInsight::query()->count())->toBe(0);
    $this->actingAs($this->member)->deleteJson(siUrl($this->project, $made['id']))->assertNotFound();
    $this->actingAs($this->member)->getJson(siUrl($this->project, $made['id']))->assertNotFound();
});

// ---------------------------------------------------------------- state: stored definitions are validated again on every read

it('shows an insight whose stored definition no longer validates as needs_review, never as a 500', function (array $row, string $reason) {
    $insight = SavedInsight::factory()->create(['project_id' => $this->project->id, 'name' => 'Old one', ...$row]);

    $show = $this->actingAs($this->member)->getJson(siUrl($this->project, $insight->uuid))->assertOk();
    expect($show->json('data.state'))->toBe(['status' => 'needs_review', 'reason' => $reason, 'migrated' => false]);

    $list = $this->actingAs($this->member)->getJson(siUrl($this->project))->assertOk();
    expect($list->json('data.0.state.status'))->toBe('needs_review')->and($list->json('data.0.state.reason'))->toBe($reason);
})->with([
    'an older definition version' => [['query' => ['v' => 1, 'events' => ['x']], 'query_version' => 1], 'outdated_version'],
    'a rule that no longer holds' => [['query' => ['v' => 2, 'events' => []]], 'invalid_definition'],
    'garbage' => [['query' => []], 'invalid_definition'],
    'a type that was removed' => [['type' => 'paths'], 'unknown_type'],
    'a preset that does not exist' => [['date_preset' => 'yesterday'], 'invalid_date'],
]);

it('never runs an insight that needs review: 422 with the reason, nothing queued, nothing cached', function () {
    Queue::fake();
    $insight = SavedInsight::factory()->create(['project_id' => $this->project->id, 'query' => ['v' => 1], 'query_version' => 1]);

    $response = $this->actingAs($this->member)->postJson(siUrl($this->project, $insight->uuid, '/run'))->assertStatus(422);

    expect($response->json('error.kind'))->toBe('needs_review')->and($response->json('error.reason'))->toBe('outdated_version')
        ->and($response->json('error.retryable'))->toBeFalse()->and($response->json('message'))->toBe($response->json('error.message'));
    Queue::assertNothingPushed();
});

it('lets an insight that needs review be renamed, and repaired by saving a new query', function () {
    $insight = SavedInsight::factory()->create(['project_id' => $this->project->id, 'query' => ['v' => 1], 'query_version' => 1]);
    $url = siUrl($this->project, $insight->uuid);

    $this->actingAs($this->member)->patchJson($url, ['name' => 'Still old'])->assertOk()->assertJsonPath('data.state.status', 'needs_review');

    $fixed = $this->actingAs($this->member)->patchJson($url, ['query' => SavedInputs::for('trends')])->assertOk();
    expect($fixed->json('data.state.status'))->toBe('ok')->and($fixed->json('data.query_version'))->toBe(TrendsQuery::VERSION);
    Queue::fake();
    $this->actingAs($this->member)->postJson($url.'/run')->assertStatus(202);
});

it('still deletes an insight that needs review', function () {
    $insight = SavedInsight::factory()->create(['project_id' => $this->project->id, 'query' => [], 'type' => 'paths']);

    $this->actingAs($this->member)->deleteJson(siUrl($this->project, $insight->uuid))->assertNoContent();
});

// ---------------------------------------------------------------- run

it('queues a run of the stored query like the insight endpoint, and the job also fills the dashboard cache', function () {
    Queue::fake();
    $made = siMake($this->member, $this->project);

    $response = $this->actingAs($this->member)->postJson(siUrl($this->project, $made['id'], '/run'))->assertStatus(202);

    expect($response->json())->toHaveKeys(['status', 'run_id', 'poll_url'])
        ->and($response->json('status'))->toBe('queued')
        ->and($response->json('poll_url'))->toBe("/api/projects/{$this->project->id}/insights/runs/".$response->json('run_id'))
        ->and($response->headers->get('Location'))->toBe($response->json('poll_url'))
        ->and($response->headers->get('Retry-After'))->toBe('1');
    Queue::assertPushedOn('insights', RunTrendsInsight::class, function (RunInsight $job) use ($made) {
        return $job->forDashboard === true && $job->userId === $this->member->id && $job->canonical === $made['query'];
    });
});

it('has the very same 202 shape as the insight endpoint for the same canonical query', function () {
    Queue::fake();
    $over = ['date_preset' => null, 'date_from' => '2026-03-01', 'date_to' => '2026-03-07'];
    $body = SavedInputs::for('trends', $over);
    $made = siMake($this->member, $this->project, query: $over);

    $saved = $this->actingAs($this->member)->postJson(siUrl($this->project, $made['id'], '/run'))->assertStatus(202);
    $direct = $this->actingAs($this->member)->postJson("/api/projects/{$this->project->id}/insights/trends", $body)->assertStatus(202);

    // identical definition, same user: the SAME live run is answered (one run, one job)
    expect($saved->json('run_id'))->toBe($direct->json('run_id'))->and(array_keys($saved->json()))->toBe(array_keys($direct->json()));
    Queue::assertPushed(RunTrendsInsight::class, 1);
});

it('answers a repeated run request with the same live run', function () {
    Queue::fake();
    $made = siMake($this->member, $this->project);
    $url = siUrl($this->project, $made['id'], '/run');

    $first = $this->actingAs($this->member)->postJson($url)->assertStatus(202)->json('run_id');
    $second = $this->actingAs($this->member)->postJson($url)->assertStatus(202)->json('run_id');

    expect($second)->toBe($first);
    Queue::assertPushed(RunTrendsInsight::class, 1);
});

it('runs through the whole flow: 202, poll to done, then 200 from the dashboard cache with computed_at', function () {
    $reads = new ScriptedReads([T::dayRows()]);
    app()->instance(InsightRunner::class, T::runner($reads, Cache::store('array')));
    $over = ['date_preset' => null, 'date_from' => '2026-03-01', 'date_to' => '2026-03-03'];
    $made = siMake($this->member, $this->project, query: $over);
    $url = siUrl($this->project, $made['id'], '/run');

    $queued = $this->actingAs($this->member)->postJson($url)->assertStatus(202);
    $poll = $this->actingAs($this->member)->getJson($queued->json('poll_url'))->assertOk();
    expect($poll->json('status'))->toBe('done')->and($poll->json('result.series.0.data'))->toBe([4, 0, 9]);

    // 20 minutes later the interactive cache (60 s) is long gone, the dashboard cache (35 min) is not
    Carbon::setTestNow('2026-03-15 10:20:00 UTC');
    $cached = $this->actingAs($this->member)->postJson($url)->assertOk();

    expect($cached->json('status'))->toBe('done')->and($cached->json('run_id'))->toBeNull()
        ->and($cached->json('source'))->toBe('dashboard_cache')
        ->and($cached->json('computed_at'))->toBe('2026-03-15T10:00:00+00:00')
        ->and($cached->json('result'))->toBe($poll->json('result'))
        ->and(count($reads->calls))->toBe(1);

    // after the dashboard TTL it is a miss again
    Carbon::setTestNow('2026-03-15 10:40:00 UTC');
    Queue::fake();
    $this->actingAs($this->member)->postJson($url)->assertStatus(202);
});

it('serves the interactive cache with source cache and no computed_at', function () {
    $runner = T::runner(new ScriptedReads([T::dayRows()]), Cache::store('array'));
    app()->instance(InsightRunner::class, $runner);
    $over = ['date_preset' => null, 'date_from' => '2026-03-01', 'date_to' => '2026-03-03'];
    $body = SavedInputs::for('trends', $over);
    $made = siMake($this->member, $this->project, query: $over);
    $runner->run($this->project->id, TrendsQuery::fromInput($body, new DateTimeZone('UTC'), now()));
    Queue::fake();

    $response = $this->actingAs($this->member)->postJson(siUrl($this->project, $made['id'], '/run'))->assertOk();

    expect($response->json('source'))->toBe('cache')->and($response->json('computed_at'))->toBeNull()->and($response->json('result.series.0.data'))->toBe([4, 0, 9]);
    Queue::assertNothingPushed();
});

it('skips the dashboard cache for fresh true, and refuses other bodies', function () {
    $runner = T::runner(new ScriptedReads, Cache::store('array'));
    app()->instance(InsightRunner::class, $runner);
    $made = siMake($this->member, $this->project);
    $query = TrendsQuery::fromCanonical($made['query']);
    $runner->rememberForDashboard($this->project->id, $query, T::runner(new ScriptedReads([T::dayRows()]), new Repository(new ArrayStore))->run($this->project->id, T::definition()));
    $url = siUrl($this->project, $made['id'], '/run');

    $this->actingAs($this->member)->postJson($url)->assertOk()->assertJsonPath('source', 'dashboard_cache');
    Queue::fake();
    $this->actingAs($this->member)->postJson($url, ['fresh' => true])->assertStatus(202);

    $this->actingAs($this->member)->postJson($url, ['fresh' => 'yes'])->assertStatus(422)->assertJsonValidationErrors('fresh');
    $this->actingAs($this->member)->postJson($url, ['other' => 1])->assertStatus(422)->assertJsonValidationErrors('body');
    $this->actingAs($this->member)->call('POST', $url, content: 'x')->assertStatus(415);
});

it('resolves a relative range to today on every run, not to the day of saving', function () {
    Queue::fake();
    $made = siMake($this->member, $this->project);
    $url = siUrl($this->project, $made['id'], '/run');

    Carbon::setTestNow('2026-04-20 08:00:00 UTC');
    $this->actingAs($this->member)->postJson($url)->assertStatus(202);

    $job = Queue::pushed(RunTrendsInsight::class)->last();
    expect($job->canonical['range'])->toBe(['from' => '2026-04-14T00:00:00.000Z', 'to' => '2026-04-21T00:00:00.000Z'])
        ->and($made['query']['range']['to'])->toBe('2026-03-16T00:00:00.000Z');
});

it('answers busy like every run when the caps are reached', function () {
    Queue::fake();
    config(['insights.max_active_runs_per_user' => 1]);
    $a = siMake($this->member, $this->project, query: ['events' => ['a']]);
    $b = siMake($this->member, $this->project, query: ['events' => ['b']]);

    $this->actingAs($this->member)->postJson(siUrl($this->project, $a['id'], '/run'))->assertStatus(202);
    $busy = $this->actingAs($this->member)->postJson(siUrl($this->project, $b['id'], '/run'))->assertStatus(429);

    expect($busy->json('error.kind'))->toBe('busy')->and($busy->json('error.retryable'))->toBeTrue();
});

it('shares the insights.run throttle with the insight endpoint, and keeps reads and writes in their own families', function () {
    Queue::fake();
    siBudget('insights.run', 2);
    $made = siMake($this->member, $this->project);

    $this->actingAs($this->member)->postJson("/api/projects/{$this->project->id}/insights/trends", SavedInputs::for('trends'))->assertStatus(202);
    $this->actingAs($this->member)->postJson(siUrl($this->project, $made['id'], '/run'))->assertStatus(202);
    $this->actingAs($this->member)->postJson(siUrl($this->project, $made['id'], '/run'))->assertStatus(429)->assertJsonPath('error.kind', 'rate_limited');

    // reading and writing are other families
    $this->actingAs($this->member)->getJson(siUrl($this->project))->assertOk();
    siMake($this->member, $this->project, o: ['name' => 'another']);
});

it('throttles writes and reads separately per user', function () {
    siBudget('dashboards.write', 2);
    siBudget('dashboards.read', 2);

    siMake($this->member, $this->project);
    siMake($this->member, $this->project);
    siCreate($this->member, $this->project, siBody())->assertStatus(429)->assertHeader('Retry-After');

    $this->actingAs($this->member)->getJson(siUrl($this->project))->assertOk();
    $this->actingAs($this->member)->getJson(siUrl($this->project))->assertOk();
    $this->actingAs($this->member)->getJson(siUrl($this->project))->assertStatus(429);

    $other = User::factory()->create();
    $this->project->users()->attach($other);
    $this->actingAs($other)->getJson(siUrl($this->project))->assertOk();
});
