<?php

use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Runs\InsightGate;
use App\Domain\Insights\Runs\InsightRunStore;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use App\Jobs\RunTrendsInsight;
use App\Models\Project;
use App\Models\User;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\ProjectCache;
use Tests\Support\ScriptedReads;
use Tests\Support\TrendsFixtures as T;

/*
 * The insight JSON API: authorisation matrix, validation, cache hit, queued path, poll states, failure mapping,
 * throttles, quantisation of relative ranges. The store is the in-memory array store; the queue is faked unless a
 * test wants the job to run inline (the phpunit queue is `sync`) with a scripted ClickHouse.
 */

const API_MARKER = 'ZZ-HOSTILE-MARKER';

beforeEach(function () {
    config([
        'insights.cache_store' => 'array',
        'insights.cache_ttl_seconds' => 60,
        'insights.cache_warning_interval_seconds' => 60,
        'insights.max_runs_per_project' => 20,
    ]);
    Cache::store('array')->flush();
    LimiterOutage::reset();
    InsightRunner::resetWarningThrottle();
    Carbon::setTestNow('2026-03-15 10:00:00 UTC');
    $this->project = Project::factory()->create(['timezone' => 'UTC']);
    $this->member = User::factory()->create();
    $this->project->users()->attach($this->member);
});

afterEach(fn () => Carbon::setTestNow());

function apiBody(array $o = []): array
{
    return [...['events' => ['signup'], 'date_from' => '2026-03-01', 'date_to' => '2026-03-07'], ...$o];
}

function apiPost(User $user, Project|int $project, array $body, array $headers = []): TestResponse
{
    $id = $project instanceof Project ? $project->id : $project;

    return test()->actingAs($user)->postJson("/api/projects/{$id}/insights/trends", $body, $headers);
}

function apiPoll(User $user, Project|int $project, string $run): TestResponse
{
    $id = $project instanceof Project ? $project->id : $project;

    return test()->actingAs($user)->getJson("/api/projects/{$id}/insights/runs/{$run}");
}

/** Queues one run through the API (queue faked) and returns its id. */
function apiQueueRun(User $user, Project $project, array $body = []): string
{
    Queue::fake();

    return apiPost($user, $project, apiBody($body))->assertStatus(202)->json('run_id');
}

/** The job class pushed last: its canonical definition rebuilt as a query. */
function apiLastDefinition(): TrendsQuery
{
    $jobs = Queue::pushed(RunTrendsInsight::class);

    return TrendsQuery::fromCanonical($jobs->last()->canonical);
}

// ---------------------------------------------------------------- authorisation

it('answers a guest with 401 JSON, with or without an Accept header, and never redirects or starts a session', function (array $headers) {
    $response = $this->call('POST', "/api/projects/{$this->project->id}/insights/trends", server: ['CONTENT_TYPE' => 'application/json'] + $headers, content: json_encode(apiBody()));

    expect($response->getStatusCode())->toBe(401)
        ->and($response->headers->has('Location'))->toBeFalse()
        ->and($response->json('message'))->toBe('Unauthenticated.')
        ->and($response->headers->getCookies())->toBe([]);

    $poll = $this->call('GET', "/api/projects/{$this->project->id}/insights/runs/".InsightRunStore::newId(), server: $headers);
    expect($poll->getStatusCode())->toBe(401)->and($poll->headers->has('Location'))->toBeFalse();
})->with([
    'json accept' => [['HTTP_ACCEPT' => 'application/json']],
    'no accept' => [[]],
    'html accept' => [['HTTP_ACCEPT' => 'text/html']],
]);

it('answers a stale session cookie with 401 JSON too, not a redirect', function () {
    $response = $this->withCookie(config('session.cookie'), 'stale')->call('GET', "/api/projects/{$this->project->id}/insights/runs/".InsightRunStore::newId());

    expect($response->getStatusCode())->toBe(401)->and($response->headers->has('Location'))->toBeFalse();
});

it('shows a non-member exactly what a missing or deleted project looks like', function () {
    $outsider = User::factory()->create();
    $deleted = Project::factory()->create();
    $deletedId = $deleted->id;
    $deleted->delete();
    Queue::fake();

    $forbidden = apiPost($outsider, $this->project, apiBody())->assertNotFound();
    $missing = apiPost($outsider, 999999, apiBody())->assertNotFound();
    $gone = apiPost($this->member, $deletedId, apiBody())->assertNotFound();

    expect($forbidden->getContent())->toBe($missing->getContent())->toBe($gone->getContent())
        ->and($forbidden->json())->toBe(['message' => 'Not found.']);
    Queue::assertNothingPushed();

    $run = InsightRunStore::newId();
    expect(apiPoll($outsider, $this->project, $run)->getContent())->toBe(apiPoll($outsider, 999999, $run)->getContent());
});

it('lets a member and an administrator who is not a member start a run', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();

    apiPost($this->member, $this->project, apiBody())->assertStatus(202);
    apiPost($admin, $this->project, apiBody(['events' => ['other']]))->assertStatus(202);

    Queue::assertPushed(RunTrendsInsight::class, 2);
});

it('lets only the creator, or an administrator, read a run, and only through its own project', function () {
    $runId = apiQueueRun($this->member, $this->project);
    $colleague = User::factory()->create();
    $this->project->users()->attach($colleague);
    $admin = User::factory()->admin()->create();
    $otherProject = Project::factory()->create();
    $otherProject->users()->attach($this->member);

    apiPoll($this->member, $this->project, $runId)->assertOk()->assertJson(['status' => 'queued']);
    apiPoll($admin, $this->project, $runId)->assertOk();

    $colleagueView = apiPoll($colleague, $this->project, $runId)->assertNotFound();
    $wrongProject = apiPoll($this->member, $otherProject, $runId)->assertNotFound();
    $unknown = apiPoll($this->member, $this->project, InsightRunStore::newId())->assertNotFound();
    expect($colleagueView->getContent())->toBe($wrongProject->getContent())->toBe($unknown->getContent());
});

it('re-authorises every poll: membership removed or project deleted means 404', function () {
    $runId = apiQueueRun($this->member, $this->project);
    apiPoll($this->member, $this->project, $runId)->assertOk();

    $this->project->users()->detach($this->member);
    apiPoll($this->member, $this->project, $runId)->assertNotFound();

    $this->project->users()->attach($this->member);
    apiPoll($this->member, $this->project, $runId)->assertOk();
    $this->project->delete();
    apiPoll($this->member, $this->project->id, $runId)->assertNotFound();
});

it('treats a malformed run id as an unknown route', function (string $run) {
    $this->actingAs($this->member)->getJson("/api/projects/{$this->project->id}/insights/runs/{$run}")->assertNotFound();
})->with(['short' => 'abc', 'upper' => str_repeat('A', 32), 'long' => str_repeat('a', 33), 'dots' => '..%2f..', 'punct' => str_repeat('a', 31).'-']);

it('uses 128 bit random hex run ids that never repeat', function () {
    Queue::fake();
    $ids = [];
    foreach (range(1, 20) as $i) {
        $ids[] = apiPost($this->member, $this->project, apiBody(['events' => ["e{$i}"]]))->json('run_id');
    }

    expect($ids)->each->toMatch('/\A[0-9a-f]{32}\z/')
        ->and(array_unique($ids))->toHaveCount(20);
});

it('takes the project from the URL only: a body project id is an unsupported field', function () {
    $other = Project::factory()->create();
    Queue::fake();

    apiPost($this->member, $this->project, apiBody(['project_id' => $other->id]))->assertStatus(422)->assertJsonValidationErrors(['body']);
    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------- validation

it('rejects hostile and malformed bodies with 422 and fixed messages that never echo the input', function (array $body, string $errorKey) {
    Queue::fake();

    $response = apiPost($this->member, $this->project, $body)->assertStatus(422)->assertJsonValidationErrors([$errorKey]);
    $raw = $response->getContent();

    expect($raw)->not->toContain(API_MARKER)->not->toContain(strtolower(API_MARKER));
    Queue::assertNothingPushed();
})->with([
    'no events' => [['date_from' => '2026-03-01', 'date_to' => '2026-03-02'], 'events'],
    'events not an array' => [apiBody(['events' => API_MARKER]), 'events'],
    'events as a map' => [apiBody(['events' => ['a' => API_MARKER]]), 'events'],
    'empty events' => [apiBody(['events' => []]), 'events'],
    'eleven events' => [apiBody(['events' => array_map(fn ($i) => API_MARKER.$i, range(1, 11))]), 'events'],
    'event too long' => [apiBody(['events' => [API_MARKER.str_repeat('x', 200)]]), 'events.0'],
    'event not text' => [apiBody(['events' => [['x' => API_MARKER]]]), 'events.0'],
    'empty event' => [apiBody(['events' => ['']]), 'events.0'],
    'duplicate events' => [apiBody(['events' => [API_MARKER, API_MARKER]]), 'events.0'],
    'unknown field' => [apiBody([API_MARKER => 1]), 'body'],
    'metric' => [apiBody(['metric' => API_MARKER]), 'metric'],
    'metric not text' => [apiBody(['metric' => ['a' => API_MARKER]]), 'metric'],
    'interval' => [apiBody(['interval' => API_MARKER]), 'interval'],
    'timezone' => [apiBody(['timezone' => API_MARKER]), 'timezone'],
    'timezone case' => [apiBody(['timezone' => 'europe/istanbul']), 'timezone'],
    'preset' => [['events' => ['a'], 'date_preset' => API_MARKER], 'date_preset'],
    'preset and absolute' => [apiBody(['date_preset' => 'last_7_days']), 'date_preset'],
    'no range' => [['events' => ['a']], 'date_preset'],
    'only date_from' => [['events' => ['a'], 'date_from' => '2026-03-01'], 'date_preset'],
    'date format' => [apiBody(['date_from' => API_MARKER]), 'date_from'],
    'date with time' => [apiBody(['date_from' => '2026-03-01T10:00:00Z']), 'date_from'],
    'impossible date' => [apiBody(['date_from' => '2026-02-30']), 'date_from'],
    'end before start' => [apiBody(['date_from' => '2026-03-07', 'date_to' => '2026-03-01']), 'date_from'],
    'range over 366 days' => [apiBody(['date_from' => '2024-01-01', 'date_to' => '2026-03-01']), 'date_from'],
    'breakdown scalar' => [apiBody(['breakdown' => API_MARKER]), 'breakdown'],
    'breakdown empty' => [apiBody(['breakdown' => []]), 'breakdown'],
    'breakdown both' => [apiBody(['breakdown' => ['column' => 'browser', 'property' => API_MARKER]]), 'breakdown'],
    'breakdown column' => [apiBody(['breakdown' => ['column' => API_MARKER]]), 'breakdown.column'],
    'breakdown unknown key' => [apiBody(['breakdown' => ['column' => 'browser', API_MARKER => 1]]), 'breakdown'],
    'breakdown top_n text' => [apiBody(['breakdown' => ['column' => 'browser', 'top_n' => '5']]), 'breakdown.top_n'],
    'breakdown top_n high' => [apiBody(['breakdown' => ['column' => 'browser', 'top_n' => 26]]), 'breakdown.top_n'],
    'breakdown property long' => [apiBody(['breakdown' => ['property' => API_MARKER.str_repeat('x', 200)]]), 'breakdown.property'],
    'filters map' => [apiBody(['filters' => ['a' => ['key' => API_MARKER, 'operator' => 'equals', 'value' => 'x']]]), 'filters'],
    'eleven filters' => [apiBody(['filters' => array_fill(0, 11, ['key' => API_MARKER, 'operator' => 'equals', 'value' => 'x'])]), 'filters'],
    'filter scalar' => [apiBody(['filters' => [API_MARKER]]), 'filters.0'],
    'filter unknown key' => [apiBody(['filters' => [['key' => 'a', 'operator' => 'equals', 'value' => 'x', API_MARKER => 1]]]), 'filters.0'],
    'filter operator' => [apiBody(['filters' => [['key' => 'a', 'operator' => API_MARKER, 'value' => 'x']]]), 'filters.0.operator'],
    'filter key missing' => [apiBody(['filters' => [['operator' => 'equals', 'value' => API_MARKER]]]), 'filters.0.key'],
    'filter key long' => [apiBody(['filters' => [['key' => API_MARKER.str_repeat('x', 200), 'operator' => 'equals', 'value' => 'x']]]), 'filters.0.key'],
    'filter value long' => [apiBody(['filters' => [['key' => 'a', 'operator' => 'equals', 'value' => API_MARKER.str_repeat('x', 1000)]]]), 'filters.0.value'],
    'filter value not text' => [apiBody(['filters' => [['key' => 'a', 'operator' => 'equals', 'value' => ['x' => API_MARKER]]]]), 'filters.0.value'],
    'equals without value' => [apiBody(['filters' => [['key' => API_MARKER, 'operator' => 'equals']]]), 'filters.0.value'],
    'is_set with value' => [apiBody(['filters' => [['key' => API_MARKER, 'operator' => 'is_set', 'value' => 'x']]]), 'filters.0.value'],
    'contains empty' => [apiBody(['filters' => [['key' => API_MARKER, 'operator' => 'contains', 'value' => '']]]), 'definition'],
    'too many rows' => [apiBody(['events' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'], 'interval' => 'hour', 'date_from' => '2026-01-01', 'date_to' => '2026-03-01', 'breakdown' => ['property' => API_MARKER]]), 'definition'],
]);

it('rejects a body that is not JSON, too large or malformed with a fixed status', function () {
    Queue::fake();
    $url = "/api/projects/{$this->project->id}/insights/trends";

    $form = $this->actingAs($this->member)->call('POST', $url, apiBody(), server: ['HTTP_ACCEPT' => 'application/json']);
    $huge = $this->actingAs($this->member)->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode(apiBody(['events' => [str_repeat('é', 70000)]])));
    $broken = $this->actingAs($this->member)->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{"events": [');
    $empty = $this->actingAs($this->member)->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json']);
    $badUtf8 = $this->actingAs($this->member)->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: "{\"events\": [\"a\xFF\"]}");

    expect($form->getStatusCode())->toBe(415)
        ->and($huge->getStatusCode())->toBe(413)
        ->and($broken->getStatusCode())->toBe(400)
        ->and($empty->getStatusCode())->toBe(400)
        ->and($badUtf8->getStatusCode())->toBe(400);
    foreach ([$form, $huge, $broken, $empty, $badUtf8] as $response) {
        expect($response->headers->get('Content-Type'))->toContain('application/json');
    }
    Queue::assertNothingPushed();
});

it('keeps strings exactly as sent: no trimming, and an empty filter value stays empty', function () {
    Queue::fake();

    apiPost($this->member, $this->project, apiBody([
        'events' => ['  signup  '],
        'filters' => [['key' => ' plan ', 'operator' => 'equals', 'value' => '']],
    ]))->assertStatus(202);

    $canonical = Queue::pushed(RunTrendsInsight::class)->last()->canonical;
    expect($canonical['events'])->toBe(['  signup  '])
        ->and($canonical['filters'])->toBe([['key' => ' plan ', 'operator' => 'equals', 'value' => '']]);
});

it('treats SQL looking values as plain data: they are valid and travel in the canonical definition only', function () {
    Queue::fake();
    $evil = "x'; DROP TABLE events; --";

    apiPost($this->member, $this->project, apiBody([
        'events' => [$evil],
        'breakdown' => ['property' => "a' OR 1=1"],
        'filters' => [['key' => '$.x', 'operator' => 'contains', 'value' => '%_\\']],
    ]))->assertStatus(202);

    expect(apiLastDefinition()->events)->toBe([$evil]);
});

// ---------------------------------------------------------------- quantisation of dates

it('defaults metric, interval and zone, and takes the project zone', function () {
    Queue::fake();
    $project = Project::factory()->create(['timezone' => 'Europe/Istanbul']);
    $project->users()->attach($this->member);

    apiPost($this->member, $project, ['events' => ['a'], 'date_from' => '2026-03-01', 'date_to' => '2026-03-02'])->assertStatus(202);

    expect(Queue::pushed(RunTrendsInsight::class)->last()->canonical)->toBe([
        'v' => 1, 'events' => ['a'], 'metric' => 'total_events',
        'range' => ['from' => '2026-02-28T21:00:00.000Z', 'to' => '2026-03-02T21:00:00.000Z'],
        'interval' => 'day', 'timezone' => 'Europe/Istanbul', 'breakdown' => null, 'filters' => [],
    ]);
});

it('resolves every preset to whole local days of the project zone', function (string $preset, string $from, string $to) {
    Queue::fake();
    Carbon::setTestNow('2026-03-15 10:00:00 UTC');

    apiPost($this->member, $this->project, ['events' => ['a'], 'date_preset' => $preset])->assertStatus(202);

    expect(Queue::pushed(RunTrendsInsight::class)->last()->canonical['range'])->toBe(['from' => $from, 'to' => $to]);
})->with([
    ['last_7_days', '2026-03-09T00:00:00.000Z', '2026-03-16T00:00:00.000Z'],
    ['last_30_days', '2026-02-14T00:00:00.000Z', '2026-03-16T00:00:00.000Z'],
    ['last_90_days', '2025-12-16T00:00:00.000Z', '2026-03-16T00:00:00.000Z'],
    ['this_month', '2026-03-01T00:00:00.000Z', '2026-03-16T00:00:00.000Z'],
    ['last_month', '2026-02-01T00:00:00.000Z', '2026-03-01T00:00:00.000Z'],
]);

it('gives two requests of the same local day the same definition and cache key, and a new day a new one', function () {
    $project = Project::factory()->create(['timezone' => 'Europe/Istanbul']);
    $project->users()->attach($this->member);
    Queue::fake();
    $keys = [];

    // Istanbul is UTC+3: the local day of 2026-03-10 runs from 2026-03-09 21:00Z to 2026-03-10 21:00Z
    foreach (['2026-03-09 21:00:00', '2026-03-10 05:30:00', '2026-03-10 20:59:59'] as $now) {
        Carbon::setTestNow($now.' UTC');
        apiPost($this->member, $project, ['events' => ['a'], 'date_preset' => 'last_7_days'])->assertStatus(202);
        $keys[] = InsightRunner::cacheKey($project->id, apiLastDefinition());
    }
    expect(array_unique($keys))->toHaveCount(1)
        ->and(apiLastDefinition()->canonical()['range'])->toBe(['from' => '2026-03-03T21:00:00.000Z', 'to' => '2026-03-10T21:00:00.000Z']);

    Carbon::setTestNow('2026-03-10 21:00:00 UTC'); // local midnight: a new local day
    apiPost($this->member, $project, ['events' => ['a'], 'date_preset' => 'last_7_days'])->assertStatus(202);
    $next = InsightRunner::cacheKey($project->id, apiLastDefinition());

    expect($next)->not->toBe($keys[0])
        ->and(apiLastDefinition()->canonical()['range'])->toBe(['from' => '2026-03-04T21:00:00.000Z', 'to' => '2026-03-11T21:00:00.000Z']);
});

it('snaps absolute dates to local midnights too, so equal days share a key whatever the time of the request', function () {
    Queue::fake();
    $keys = [];

    foreach (['2026-03-15 00:00:01', '2026-03-15 23:59:59'] as $now) {
        Carbon::setTestNow($now.' UTC');
        apiPost($this->member, $this->project, apiBody())->assertStatus(202);
        $keys[] = InsightRunner::cacheKey($this->project->id, apiLastDefinition());
    }

    expect(array_unique($keys))->toHaveCount(1)
        ->and(apiLastDefinition()->canonical()['range'])->toBe(['from' => '2026-03-01T00:00:00.000Z', 'to' => '2026-03-08T00:00:00.000Z']);
});

it('uses a different cache key for a different project, definition, zone or range', function () {
    Queue::fake();
    $keys = [];
    foreach ([apiBody(), apiBody(['events' => ['b']]), apiBody(['timezone' => 'Europe/Istanbul']), apiBody(['date_to' => '2026-03-08'])] as $body) {
        apiPost($this->member, $this->project, $body)->assertStatus(202);
        $keys[] = InsightRunner::cacheKey($this->project->id, apiLastDefinition());
    }
    $other = Project::factory()->create();
    $other->users()->attach($this->member);
    apiPost($this->member, $other, apiBody())->assertStatus(202);
    $keys[] = InsightRunner::cacheKey($other->id, apiLastDefinition());

    expect(array_unique($keys))->toHaveCount(5);
});

// ---------------------------------------------------------------- cache hit and queued path

it('answers 200 done straight from the result cache without queueing anything', function () {
    Queue::fake();
    apiPost($this->member, $this->project, apiBody())->assertStatus(202);
    $query = apiLastDefinition();
    $result = T::runner(new ScriptedReads([[['series' => 'signup', 'bucket' => strtotime('2026-03-02 UTC'), 'value' => 5]]]), Cache::store('array'))
        ->trends($this->project->id, $query);
    Queue::fake();

    $response = apiPost($this->member, $this->project, apiBody())->assertOk();

    expect($response->json())->toBe(['status' => 'done', 'result' => $result->toArray()])
        ->and($response->json('result.series.0.data'))->toBe([0, 5, 0, 0, 0, 0, 0])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    Queue::assertNothingPushed();
});

it('does not serve one project the cached result of another', function () {
    Queue::fake();
    apiPost($this->member, $this->project, apiBody())->assertStatus(202);
    T::runner(new ScriptedReads([T::dayRows()]), Cache::store('array'))->trends($this->project->id, apiLastDefinition());
    $other = Project::factory()->create();
    $other->users()->attach($this->member);
    Queue::fake();

    apiPost($this->member, $other, apiBody())->assertStatus(202);
});

it('queues a run on the insights queue with only ids and the canonical definition and answers 202', function () {
    Queue::fake();

    $response = apiPost($this->member, $this->project, apiBody())->assertStatus(202);
    $runId = $response->json('run_id');
    $poll = "/api/projects/{$this->project->id}/insights/runs/{$runId}";

    expect($response->json())->toBe(['status' => 'queued', 'run_id' => $runId, 'poll_url' => $poll])
        ->and($response->headers->get('Location'))->toBe($poll)
        ->and($response->headers->get('Retry-After'))->toBe('1')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    Queue::assertPushedOn('insights', RunTrendsInsight::class, fn (RunTrendsInsight $job) => $job->projectId === $this->project->id
        && $job->runId === $runId
        && $job->canonical === TrendsQuery::fromCanonical($job->canonical)->canonical());

    $record = (new InsightRunStore)->find($runId);
    expect($record['status'])->toBe('queued')->and($record['user_id'])->toBe($this->member->id)->and($record['project_id'])->toBe($this->project->id);
});

// ---------------------------------------------------------------- poll states

it('reports every run state with the documented shape', function () {
    $runId = apiQueueRun($this->member, $this->project);
    $store = new InsightRunStore;
    $result = T::runner(new ScriptedReads([T::dayRows()]))->trends($this->project->id, T::definition());

    $queued = apiPoll($this->member, $this->project, $runId)->assertOk();
    expect($queued->json())->toBe(['status' => 'queued', 'run_id' => $runId])
        ->and($queued->headers->get('Cache-Control'))->toContain('no-store')
        ->and($queued->headers->get('Retry-After'))->toBe('1');

    $store->markRunning($runId);
    expect(apiPoll($this->member, $this->project, $runId)->json())->toBe(['status' => 'running', 'run_id' => $runId]);

    $store->markDone($runId, $result);
    $done = apiPoll($this->member, $this->project, $runId)->assertOk();
    expect($done->json())->toBe(['status' => 'done', 'run_id' => $runId, 'result' => $result->toArray()])
        ->and($done->headers->has('Retry-After'))->toBeFalse();
});

it('maps every failure kind to a 200 poll answer with the kind, the fixed message and retryability', function (ClickHouseErrorKind $kind) {
    $runId = apiQueueRun($this->member, $this->project);
    (new InsightRunStore)->markFailed($runId, $kind);

    $response = apiPoll($this->member, $this->project, $runId)->assertOk();

    expect($response->json('status'))->toBe('failed')
        ->and($response->json('run_id'))->toBe($runId)
        ->and($response->json('error'))->toBe([
            'kind' => $kind->value,
            'message' => $kind->userMessage(),
            'retryable' => $kind->isRetryable(),
            'retry_after_seconds' => $kind->retryAfterSeconds() ?? ($kind->isRetryable() ? 5 : null),
        ])
        ->and($response->json())->not->toHaveKey('result');
})->with(array_map(fn (ClickHouseErrorKind $k) => [$k], ClickHouseErrorKind::cases()));

it('runs end to end on the sync queue: a scripted ClickHouse failure becomes a failed poll answer with no raw text anywhere', function (ClickHouseErrorKind $kind, int $code) {
    $raw = "Code: {$code}. DB::Exception: SECRET-RAW select * from posthog.events where project_id = 7";
    app()->instance(InsightRunner::class, T::runner(new ScriptedReads([new ClickHouseException($raw, $code, $kind)]), Cache::store('array')));

    $post = apiPost($this->member, $this->project, apiBody())->assertStatus(202);
    $poll = apiPoll($this->member, $this->project, $post->json('run_id'));

    expect($poll->getContent())->not->toContain('SECRET-RAW')->not->toContain('posthog.events')
        ->and($post->getContent())->not->toContain('SECRET-RAW')
        ->and(DB::table('failed_jobs')->count())->toBe(0);
    if ($kind === ClickHouseErrorKind::Busy) {
        // The sync queue cannot release, so the run stays queued for its retry (a real worker releases for 3 s).
        expect($poll->json('status'))->toBe('queued');
    } else {
        expect($poll->json('status'))->toBe('failed')->and($poll->json('error.kind'))->toBe($kind->value)->and($poll->json('error.message'))->toBe($kind->userMessage());
    }
})->with([
    'timeout' => [ClickHouseErrorKind::Timeout, 159],
    'memory' => [ClickHouseErrorKind::MemoryLimit, 241],
    'rows' => [ClickHouseErrorKind::TooManyRows, 396],
    'busy' => [ClickHouseErrorKind::Busy, 202],
    'constraint' => [ClickHouseErrorKind::ConstraintViolation, 452],
    'auth' => [ClickHouseErrorKind::AuthenticationFailed, 516],
    'unavailable' => [ClickHouseErrorKind::Unavailable, 0],
    'unknown' => [ClickHouseErrorKind::Unknown, 999],
]);

it('runs end to end on the sync queue: a success is a done poll answer, then a cache hit', function () {
    app()->instance(InsightRunner::class, T::runner(new ScriptedReads([[['series' => 'signup', 'bucket' => strtotime('2026-03-03 UTC'), 'value' => 2]]]), Cache::store('array')));

    $post = apiPost($this->member, $this->project, apiBody())->assertStatus(202);
    $poll = apiPoll($this->member, $this->project, $post->json('run_id'))->assertOk();

    expect($poll->json('status'))->toBe('done')
        ->and($poll->json('result.series.0.data'))->toBe([0, 0, 2, 0, 0, 0, 0])
        ->and($poll->json('result.timezone'))->toBe('UTC');

    apiPost($this->member, $this->project, apiBody())->assertOk()->assertJsonPath('status', 'done');
});

// ---------------------------------------------------------------- throttles, caps, outages

/** Overrides one family's limit (the family names contain a dot, so a dotted config key cannot address them). */
function apiThrottle(string $family, int $limit): void
{
    $budgets = config('ratelimit.user_throttles');
    $budgets[$family]['limit'] = $limit;
    config(['ratelimit.user_throttles' => $budgets]);
}

it('throttles starting runs per user with a 429 JSON answer and Retry-After', function () {
    Queue::fake();
    apiThrottle('insights.run', 2);

    apiPost($this->member, $this->project, apiBody(['events' => ['a']]))->assertStatus(202);
    apiPost($this->member, $this->project, apiBody(['events' => ['b']]))->assertStatus(202);
    $response = apiPost($this->member, $this->project, apiBody(['events' => ['c']]))->assertStatus(429)->assertHeader('Retry-After');

    expect($response->json('error.kind'))->toBe('rate_limited')
        ->and($response->json('error.retryable'))->toBeTrue()
        ->and($response->json('error.retry_after_seconds'))->toBeGreaterThan(0)
        ->and($response->json('message'))->toBe('Too many requests. Try again shortly.');
    Queue::assertPushed(RunTrendsInsight::class, 2);

    // another user has their own budget
    $colleague = User::factory()->create();
    $this->project->users()->attach($colleague);
    apiPost($colleague, $this->project, apiBody(['events' => ['d']]))->assertStatus(202);
});

it('throttles polling on its own, looser budget', function () {
    $runId = apiQueueRun($this->member, $this->project);
    apiThrottle('insights.poll', 2);
    apiThrottle('insights.run', 1);

    apiPoll($this->member, $this->project, $runId)->assertOk();
    apiPoll($this->member, $this->project, $runId)->assertOk();
    apiPoll($this->member, $this->project, $runId)->assertStatus(429)->assertHeader('Retry-After');

    $configured = (require base_path('config/ratelimit.php'))['user_throttles'];
    expect($configured['insights.run'])->toBe(['limit' => 60, 'window_seconds' => 60])
        ->and($configured['insights.poll'])->toBe(['limit' => 600, 'window_seconds' => 60]);
});

it('does not count requests the project binder already answered with 404', function () {
    apiThrottle('insights.run', 1);
    $outsider = User::factory()->create();
    Queue::fake();

    apiPost($outsider, $this->project, apiBody())->assertNotFound();
    apiPost($outsider, $this->project, apiBody())->assertNotFound();
    $mine = Project::factory()->create();
    $mine->users()->attach($outsider);
    apiPost($outsider, $mine, apiBody())->assertStatus(202);
});

it('caps the runs of one project that exist at once with 429 busy, per project, and frees a slot when a run ends', function () {
    config(['insights.max_runs_per_project' => 2]);
    Queue::fake();
    $other = Project::factory()->create();
    $other->users()->attach($this->member);

    $first = apiPost($this->member, $this->project, apiBody(['events' => ['a']]))->assertStatus(202)->json('run_id');
    apiPost($this->member, $this->project, apiBody(['events' => ['b']]))->assertStatus(202);
    $busy = apiPost($this->member, $this->project, apiBody(['events' => ['c']]))->assertStatus(429)->assertHeader('Retry-After', '3');

    expect($busy->json('error'))->toBe([
        'kind' => 'busy',
        'message' => 'Too many insights of this project are running. Try again in a few seconds.',
        'retryable' => true,
        'retry_after_seconds' => 3,
    ])->and($busy->json('message'))->toBe($busy->json('error.message'));
    Queue::assertPushed(RunTrendsInsight::class, 2);

    apiPost($this->member, $other, apiBody(['events' => ['a']]))->assertStatus(202); // another project is unaffected

    // the job of the first run ends: its slot is free again
    $record = (new InsightRunStore)->find($first);
    (new InsightGate)->releaseDispatchSlot($this->project->id, $first, $record['slot']);
    apiPost($this->member, $this->project, apiBody(['events' => ['c']]))->assertStatus(202);
});

it('answers 503 unavailable with Retry-After when the run store is down, queues nothing and leaks no exception text', function () {
    ProjectCache::breakStore();
    config(['insights.cache_store' => 'failing']);
    Queue::fake();

    $response = apiPost($this->member, $this->project, apiBody())->assertStatus(503)->assertHeader('Retry-After', '5');

    expect($response->json('error.kind'))->toBe('unavailable')
        ->and($response->json('error.retryable'))->toBeTrue()
        ->and($response->getContent())->not->toContain('FailingCacheStore')->not->toContain('Redis');
    Queue::assertNothingPushed();
});

it('answers a poll with 503 when the run store is down', function () {
    $runId = apiQueueRun($this->member, $this->project);
    ProjectCache::breakStore();
    config(['insights.cache_store' => 'failing']);

    apiPoll($this->member, $this->project, $runId)->assertStatus(503)->assertHeader('Retry-After', '5')->assertJsonPath('error.kind', 'unavailable');
});

it('releases the dispatch slot and the record when queueing the job fails and answers 503 without the exception text', function () {
    config(['insights.max_runs_per_project' => 1]);
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('redis exploded SECRET-QUEUE-TEXT'));

    $response = apiPost($this->member, $this->project, apiBody());

    expect($response->getStatusCode())->toBe(503)
        ->and($response->json('error.kind'))->toBe('unavailable')
        ->and($response->getContent())->not->toContain('SECRET-QUEUE-TEXT')
        // the one slot is free again: a second attempt is not answered 429
        ->and((new InsightGate)->acquireDispatchSlot($this->project->id, 'x'))->toBe(0);
});

// ---------------------------------------------------------------- errors stay JSON

it('keeps every /api error JSON with a fixed message, never an Inertia page or a redirect', function (string $method, string $uri, int $status) {
    $response = $this->actingAs($this->member)->call($method, $uri, server: ['HTTP_ACCEPT' => 'text/html']);

    expect($response->getStatusCode())->toBe($status)
        ->and($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->headers->has('X-Inertia'))->toBeFalse()
        ->and($response->getContent())->not->toContain('data-page')->not->toContain('Illuminate')->not->toContain('App\\');
})->with([
    ['GET', '/api/nothing', 404],
    ['GET', '/api/projects/1/insights/nothing', 404],
    ['GET', '/api/projects/abc/insights/runs/x', 404],
    ['DELETE', '/api/projects/1/insights/trends', 405],
    ['POST', '/api/projects/1/insights/runs/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 405],
]);

it('does not leak the model name or id in a 404 or the route in a 405', function () {
    $missing = $this->actingAs($this->member)->postJson('/api/projects/987654/insights/trends', apiBody());
    $method = $this->actingAs($this->member)->deleteJson("/api/projects/{$this->project->id}/insights/trends");

    expect($missing->getContent())->not->toContain('987654')->not->toContain('Project')
        ->and($method->json())->toBe(['message' => 'This method is not allowed here.'])
        ->and($method->headers->get('Allow'))->toContain('POST');
});
