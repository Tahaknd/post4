<?php

use App\Domain\Insights\Funnel\FunnelQuery;
use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\InsightTypes;
use App\Domain\Insights\Runs\InsightGate;
use App\Domain\Insights\Runs\InsightRunStore;
use App\Http\ApiErrors;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use App\Jobs\RunFunnelInsight;
use App\Jobs\RunTrendsInsight;
use App\Models\Project;
use App\Models\User;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeQueueJob;
use Tests\Support\FunnelFixtures as F;
use Tests\Support\ScriptedReads;

/*
 * The funnel endpoint of the insight API. Everything the runs share with trends (the state machine, slots, single flight,
 * busy handling, the 404 matrix of the poll) is pinned for trends in InsightApiTest / RunTrendsInsightTest and is the SAME
 * code (InsightController::start, RunInsight); here: the funnel body, its validation, its definition, and a pass through
 * the whole flow (cache hit, queued run, poll, cancel, authorisation, tenant isolation) to prove the type is wired in.
 * Store: the in-memory array store; the queue is faked unless a test lets the job run inline on the sync queue.
 */

const FUNNEL_MARKER = 'ZZ-HOSTILE-MARKER';

beforeEach(function () {
    config([
        'insights.cache_store' => 'array',
        'insights.cache_ttl_seconds' => 60,
        'insights.cache_warning_interval_seconds' => 60,
        'insights.max_active_runs_per_project' => 20,
        'insights.max_active_runs_per_user' => 50,
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

function fBody(array $o = []): array
{
    return [...['steps' => [['event' => 'signup'], ['event' => 'purchase']], 'date_from' => '2026-03-01', 'date_to' => '2026-03-07'], ...$o];
}

function fPost(User $user, Project|int $project, array $body): TestResponse
{
    $id = $project instanceof Project ? $project->id : $project;

    return test()->actingAs($user)->postJson("/api/projects/{$id}/insights/funnel", $body);
}

function fPoll(User $user, Project|int $project, string $run): TestResponse
{
    $id = $project instanceof Project ? $project->id : $project;

    return test()->actingAs($user)->getJson("/api/projects/{$id}/insights/runs/{$run}");
}

function fQueue(User $user, Project $project, array $body = []): string
{
    Queue::fake();

    return fPost($user, $project, fBody($body))->assertStatus(202)->json('run_id');
}

function fLastJob(): RunFunnelInsight
{
    return Queue::pushed(RunFunnelInsight::class)->last();
}

// ---------------------------------------------------------------- wiring

it('has a POST route for every registered insight type, named after it', function () {
    foreach (InsightTypes::keys() as $type) {
        $route = Route::getRoutes()->getByName("api.insights.{$type}");

        expect($route)->not->toBeNull()
            ->and($route->uri())->toBe("api/projects/{project}/insights/{$type}")
            ->and($route->methods())->toBe(['POST'])
            ->and($route->gatherMiddleware())->toContain('throttle.user:insights.run');
    }
});

it('keeps the strings of the funnel body exactly as sent, like the trends one', function () {
    Queue::fake();

    fPost($this->member, $this->project, fBody([
        'steps' => [['event' => '  signup  ', 'filters' => [['key' => ' plan ', 'operator' => 'equals', 'value' => '']]], ['event' => ' b ']],
    ]))->assertStatus(202);

    $canonical = fLastJob()->canonical;
    expect($canonical['steps'][0]['event'])->toBe('  signup  ')
        ->and($canonical['steps'][0]['filters'])->toBe([['key' => ' plan ', 'operator' => 'equals', 'value' => '']])
        ->and($canonical['steps'][1]['event'])->toBe(' b ');
});

// ---------------------------------------------------------------- authorisation

it('answers a guest with 401 JSON, never a redirect', function () {
    $response = $this->call('POST', "/api/projects/{$this->project->id}/insights/funnel", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(fBody()));

    expect($response->getStatusCode())->toBe(401)
        ->and($response->headers->has('Location'))->toBeFalse()
        ->and($response->json('message'))->toBe('Unauthenticated.');
});

it('shows a non-member exactly what a missing project looks like, and lets a member and an administrator start a run', function () {
    Queue::fake();
    $outsider = User::factory()->create();
    $admin = User::factory()->admin()->create();

    $forbidden = fPost($outsider, $this->project, fBody())->assertNotFound();
    $missing = fPost($outsider, 999999, fBody())->assertNotFound();
    expect($forbidden->getContent())->toBe($missing->getContent())->and($forbidden->json())->toBe(ApiErrors::body('not_found', 'Not found.'));
    Queue::assertNothingPushed();

    fPost($this->member, $this->project, fBody())->assertStatus(202);
    fPost($admin, $this->project, fBody(['steps' => [['event' => 'x'], ['event' => 'y']]]))->assertStatus(202);
    Queue::assertPushed(RunFunnelInsight::class, 2);
    Queue::assertNotPushed(RunTrendsInsight::class);
});

// ---------------------------------------------------------------- validation

it('rejects an invalid body with 422, a fixed sentence and nothing queued', function (array $body, string $field) {
    Queue::fake();

    $response = fPost($this->member, $this->project, $body)->assertStatus(422);

    expect($response->json('errors'))->toHaveKey($field)
        ->and($response->json('error.kind'))->toBe('validation')
        ->and($response->getContent())->not->toContain(FUNNEL_MARKER);
    Queue::assertNothingPushed();
})->with([
    'no steps' => [['date_preset' => 'last_7_days'], 'steps'],
    'steps null' => [fBody(['steps' => null]), 'steps'],
    'steps text' => [fBody(['steps' => FUNNEL_MARKER]), 'steps'],
    'one step' => [fBody(['steps' => [['event' => FUNNEL_MARKER]]]), 'steps'],
    'nine steps' => [fBody(['steps' => array_fill(0, 9, ['event' => FUNNEL_MARKER])]), 'steps'],
    'steps a map' => [fBody(['steps' => ['a' => ['event' => FUNNEL_MARKER], 'b' => ['event' => 'x']]]), 'steps'],
    'step a string' => [fBody(['steps' => [FUNNEL_MARKER, 'b']]), 'steps.0'],
    'step without event' => [fBody(['steps' => [['filters' => []], ['event' => 'b']]]), 'steps.0.event'],
    'step unknown key' => [fBody(['steps' => [['event' => 'a', FUNNEL_MARKER => 1], ['event' => 'b']]]), 'steps.0'],
    'event empty' => [fBody(['steps' => [['event' => ''], ['event' => 'b']]]), 'steps.0.event'],
    'event too long' => [fBody(['steps' => [['event' => str_repeat('x', 201)], ['event' => 'b']]]), 'steps.0.event'],
    'event an array' => [fBody(['steps' => [['event' => [FUNNEL_MARKER]], ['event' => 'b']]]), 'steps.0.event'],
    'filters too many' => [fBody(['steps' => [['event' => 'a', 'filters' => array_fill(0, 6, ['key' => 'k', 'operator' => 'is_set', 'value' => null])], ['event' => 'b']]]), 'steps.0.filters'],
    'filters a map' => [fBody(['steps' => [['event' => 'a', 'filters' => ['x' => ['key' => 'k', 'operator' => 'is_set', 'value' => null]]], ['event' => 'b']]]), 'steps.0.filters'],
    'filter operator unknown' => [fBody(['steps' => [['event' => 'a', 'filters' => [['key' => 'k', 'operator' => FUNNEL_MARKER, 'value' => 'x']]], ['event' => 'b']]]), 'steps.0.filters.0.operator'],
    'filter needs a value' => [fBody(['steps' => [['event' => 'a', 'filters' => [['key' => 'k', 'operator' => 'equals']]], ['event' => 'b']]]), 'steps.0.filters.0.value'],
    'is_set takes none' => [fBody(['steps' => [['event' => 'a', 'filters' => [['key' => 'k', 'operator' => 'is_set', 'value' => 'x']]], ['event' => 'b']]]), 'steps.0.filters.0.value'],
    'window text' => [fBody(['window' => FUNNEL_MARKER]), 'window'],
    'window without unit' => [fBody(['window' => ['value' => 3]]), 'window.unit'],
    'window unit unknown' => [fBody(['window' => ['value' => 3, 'unit' => 'month']]), 'window.unit'],
    'window zero' => [fBody(['window' => ['value' => 0, 'unit' => 'day']]), 'window.value'],
    'window as text number' => [fBody(['window' => ['value' => '3', 'unit' => 'day']]), 'window.value'],
    'window extra key' => [fBody(['window' => ['value' => 3, 'unit' => 'day', 'x' => 1]]), 'window'],
    'order unknown' => [fBody(['order' => FUNNEL_MARKER]), 'order'],
    'order empty' => [fBody(['order' => '']), 'order'],
    'unknown field' => [fBody([FUNNEL_MARKER => 1]), 'body'],
    'breakdown is not supported' => [fBody(['breakdown' => ['column' => 'browser']]), 'body'],
    'both date modes' => [fBody(['date_preset' => 'last_7_days']), 'date_preset'],
    'one day only' => [['steps' => fBody()['steps'], 'date_from' => '2026-03-01'], 'date_preset'],
    'bad zone' => [fBody(['timezone' => 'Mars/Base']), 'timezone'],
    'bad day' => [fBody(['date_from' => '2026-02-30']), 'date_from'],
]);

it('rejects combinations the domain refuses with one fixed sentence on `definition` or `date_from`', function (array $body, string $field) {
    Queue::fake();

    $response = fPost($this->member, $this->project, $body)->assertStatus(422);

    expect($response->json('errors'))->toHaveKey($field)->and($response->getContent())->not->toContain(FUNNEL_MARKER);
    Queue::assertNothingPushed();
})->with([
    'window of 91 days' => [fBody(['window' => ['value' => 91, 'unit' => 'day']]), 'definition'],
    'window of 14 weeks' => [fBody(['window' => ['value' => 14, 'unit' => 'week']]), 'definition'],
    'twenty one filters over all steps' => [fBody(['steps' => array_map(
        fn (int $i) => ['event' => "e{$i}", 'filters' => array_fill(0, $i === 5 ? 1 : 5, ['key' => 'k', 'operator' => 'is_set', 'value' => null])],
        range(1, 5),
    )]), 'definition'],
    'a range of more than 366 days' => [fBody(['date_from' => '2024-01-01', 'date_to' => '2026-03-01']), 'date_from'],
    'end before start' => [fBody(['date_from' => '2026-03-05', 'date_to' => '2026-03-01']), 'date_from'],
]);

it('answers the wrong media type, a huge body and broken JSON before anything else', function () {
    $url = "/api/projects/{$this->project->id}/insights/funnel";
    $headers = ['HTTP_ACCEPT' => 'application/json'];
    Queue::fake();

    $form = $this->actingAs($this->member)->call('POST', $url, ['steps' => 'x'], server: $headers);
    $huge = $this->actingAs($this->member)->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'] + $headers, content: json_encode(fBody(['steps' => [['event' => str_repeat('x', 70000)], ['event' => 'b']]])));
    $broken = $this->actingAs($this->member)->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'] + $headers, content: '{"steps": [');

    expect([$form->getStatusCode(), $huge->getStatusCode(), $broken->getStatusCode()])->toBe([415, 413, 400]);
    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------- the definition the body becomes

it('turns the body into the canonical definition with the defaults and the project zone', function () {
    Queue::fake();
    $project = Project::factory()->create(['timezone' => 'Europe/Istanbul']);
    $project->users()->attach($this->member);

    fPost($this->member, $project, ['steps' => [['event' => 'a'], ['event' => 'b']], 'date_from' => '2026-03-01', 'date_to' => '2026-03-02'])->assertStatus(202);

    expect(fLastJob()->canonical)->toBe([
        'v' => FunnelQuery::VERSION,
        'steps' => [['event' => 'a', 'filters' => []], ['event' => 'b', 'filters' => []]],
        'window' => ['value' => 2, 'unit' => 'week'],
        'range' => ['from' => '2026-02-28T21:00:00.000Z', 'to' => '2026-03-02T21:00:00.000Z'],
        'order' => 'ordered',
    ]);
});

it('takes a window, a strict order and filters, and quantises a relative range to whole local days', function () {
    Queue::fake();

    fPost($this->member, $this->project, [
        'steps' => [['event' => 'a', 'filters' => [['key' => 'plan', 'operator' => 'contains', 'value' => 'p']]], ['event' => 'b']],
        'window' => ['value' => 36, 'unit' => 'hour'],
        'order' => 'strict_order',
        'date_preset' => 'last_7_days',
    ])->assertStatus(202);
    $first = fLastJob()->canonical;
    Carbon::setTestNow('2026-03-15 23:59:00 UTC');
    Queue::fake();
    fPost($this->member, $this->project, [
        'steps' => [['event' => 'a', 'filters' => [['key' => 'plan', 'operator' => 'contains', 'value' => 'p']]], ['event' => 'b']],
        'window' => ['value' => 36, 'unit' => 'hour'],
        'order' => 'strict_order',
        'date_preset' => 'last_7_days',
    ])->assertStatus(202);

    expect($first['window'])->toBe(['value' => 36, 'unit' => 'hour'])
        ->and($first['order'])->toBe('strict_order')
        ->and($first['range'])->toBe(['from' => '2026-03-09T00:00:00.000Z', 'to' => '2026-03-16T00:00:00.000Z'])
        ->and(fLastJob()->canonical)->toBe($first);   // the same definition all day: one cache entry
});

// ---------------------------------------------------------------- the run

it('queues a run on the insights queue with only ids and the canonical definition and answers 202', function () {
    Queue::fake();

    $response = fPost($this->member, $this->project, fBody())->assertStatus(202);
    $runId = $response->json('run_id');
    $poll = "/api/projects/{$this->project->id}/insights/runs/{$runId}";

    expect($response->json())->toBe(['status' => 'queued', 'run_id' => $runId, 'poll_url' => $poll])
        ->and($response->headers->get('Location'))->toBe($poll)
        ->and($response->headers->get('Retry-After'))->toBe('1');
    Queue::assertPushedOn('insights', RunFunnelInsight::class, fn (RunFunnelInsight $job) => $job->projectId === $this->project->id
        && $job->runId === $runId
        && $job->canonical === FunnelQuery::fromCanonical($job->canonical)->canonical());

    $record = app(InsightRunStore::class)->find($runId);
    expect($record['type'])->toBe('funnel')->and($record['status'])->toBe('queued')->and($record['user_id'])->toBe($this->member->id);
});

it('answers an identical POST of the same user with the same run, and another user gets a run of their own', function () {
    Queue::fake();
    $colleague = User::factory()->create();
    $this->project->users()->attach($colleague);

    $first = fPost($this->member, $this->project, fBody())->assertStatus(202)->json('run_id');
    $again = fPost($this->member, $this->project, fBody())->assertStatus(202)->json('run_id');
    $other = fPost($colleague, $this->project, fBody())->assertStatus(202)->json('run_id');

    expect($again)->toBe($first)->and($other)->not->toBe($first);
    Queue::assertPushed(RunFunnelInsight::class, 2);
});

it('runs end to end on the sync queue: done, with the counts and rates, then a cache hit with no run', function () {
    app()->instance(InsightRunner::class, F::runner(new ScriptedReads([F::levelRows([1 => 3, 2 => 1])]), Cache::store('array')));

    $post = fPost($this->member, $this->project, fBody())->assertStatus(202);
    $poll = fPoll($this->member, $this->project, $post->json('run_id'))->assertOk();

    expect($poll->json('status'))->toBe('done')
        ->and(array_column($poll->json('result.steps'), 'count'))->toBe([4, 1])
        ->and($poll->json('result.steps.1'))->toBe(['index' => 2, 'event' => 'purchase', 'count' => 1, 'dropped_off' => 3, 'conversion_from_previous' => 0.25, 'conversion_from_first' => 0.25])
        ->and($poll->json('result.entrants'))->toBe(4)->and($poll->json('result.converted'))->toBe(1)->and($poll->json('result.conversion_rate'))->toBe(0.25)
        ->and($poll->json('result.window_seconds'))->toBe(1209600)->and($poll->json('result.order'))->toBe('ordered');

    $hit = fPost($this->member, $this->project, fBody())->assertOk();
    expect($hit->json('status'))->toBe('done')->and($hit->json('run_id'))->toBeNull()->and($hit->json('result'))->toBe($poll->json('result'));
});

it('serves a fresh cached result to ANOTHER user of the project, but never to another project', function () {
    app()->instance(InsightRunner::class, F::runner(new ScriptedReads([F::levelRows([1 => 2])]), Cache::store('array')));
    $colleague = User::factory()->create();
    $this->project->users()->attach($colleague);
    $other = Project::factory()->create();
    $other->users()->attach($this->member);

    fPost($this->member, $this->project, fBody())->assertStatus(202);

    fPost($colleague, $this->project, fBody())->assertOk()->assertJsonPath('status', 'done');
    Queue::fake();
    fPost($this->member, $other, fBody())->assertStatus(202);   // another project: a miss
});

it('maps a ClickHouse failure to a failed poll answer with the kind and no raw text anywhere', function (ClickHouseErrorKind $kind, int $code) {
    $raw = "Code: {$code}. DB::Exception: SECRET-RAW select * from posthog.events where project_id = 7";
    app()->instance(InsightRunner::class, F::runner(new ScriptedReads([new ClickHouseException($raw, $code, $kind)]), Cache::store('array')));

    $post = fPost($this->member, $this->project, fBody())->assertStatus(202);
    $poll = fPoll($this->member, $this->project, $post->json('run_id'));

    expect($poll->getContent())->not->toContain('SECRET-RAW')->not->toContain('posthog.events')
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($poll->json('status'))->toBe('failed')
        ->and($poll->json('error.kind'))->toBe($kind->value)
        ->and($poll->json('error.message'))->toBe($kind->userMessage());
})->with([
    'memory is the query too large of a funnel' => [ClickHouseErrorKind::MemoryLimit, 241],
    'timeout' => [ClickHouseErrorKind::Timeout, 159],
    'rows' => [ClickHouseErrorKind::TooManyRows, 396],
    'unknown' => [ClickHouseErrorKind::Unknown, 999],
]);

it('cancels a queued funnel run: 204, idempotent, and the poll says cancelled', function () {
    $runId = fQueue($this->member, $this->project);
    $url = "/api/projects/{$this->project->id}/insights/runs/{$runId}";

    $this->actingAs($this->member)->deleteJson($url)->assertNoContent();
    $this->actingAs($this->member)->deleteJson($url)->assertNoContent();

    fPoll($this->member, $this->project, $runId)->assertOk()->assertExactJson(['status' => 'cancelled', 'run_id' => $runId]);
    // the same definition can be started again as a new run
    expect(fPost($this->member, $this->project, fBody())->assertStatus(202)->json('run_id'))->not->toBe($runId);
});

it('keeps the runs of a project to its own users: another member, another project and a non-member see the same 404', function () {
    $runId = fQueue($this->member, $this->project);
    $colleague = User::factory()->create();
    $this->project->users()->attach($colleague);
    $outsider = User::factory()->create();
    $other = Project::factory()->create();
    $other->users()->attach($this->member);
    $del = fn (User $user, Project|int $project, string $run) => $this->actingAs($user)->deleteJson('/api/projects/'.($project instanceof Project ? $project->id : $project)."/insights/runs/{$run}");

    $bodies = [
        fPoll($colleague, $this->project, $runId)->assertNotFound()->getContent(),
        fPoll($outsider, $this->project, $runId)->assertNotFound()->getContent(),
        fPoll($this->member, $other, $runId)->assertNotFound()->getContent(),
        $del($colleague, $this->project, $runId)->assertNotFound()->getContent(),
        $del($this->member, $other, $runId)->assertNotFound()->getContent(),
    ];

    expect(array_unique($bodies))->toHaveCount(1)->and(app(InsightRunStore::class)->find($runId)['status'])->toBe('queued');
});

it('shares the start throttle with trends: both types draw on the same insights.run budget', function () {
    Queue::fake();
    $budgets = config('ratelimit.user_throttles');
    $budgets['insights.run']['limit'] = 2;
    config(['ratelimit.user_throttles' => $budgets]);

    $this->actingAs($this->member)->postJson("/api/projects/{$this->project->id}/insights/trends", ['events' => ['a'], 'date_from' => '2026-03-01', 'date_to' => '2026-03-02'])->assertStatus(202);
    fPost($this->member, $this->project, fBody())->assertStatus(202);
    $third = fPost($this->member, $this->project, fBody(['steps' => [['event' => 'x'], ['event' => 'y']]]))->assertStatus(429);

    expect($third->json('error.kind'))->toBe('rate_limited');
});

it('does not let a funnel job run the record of another insight type', function () {
    Queue::fake();
    $trendsRun = $this->actingAs($this->member)->postJson("/api/projects/{$this->project->id}/insights/trends", ['events' => ['a'], 'date_from' => '2026-03-01', 'date_to' => '2026-03-02'])->assertStatus(202)->json('run_id');
    $reads = new ScriptedReads([F::levelRows([1 => 1])]);

    $job = new RunFunnelInsight($this->project->id, $trendsRun, F::query(['a', 'b'])->canonical(), $this->member->id);
    $job->setJob(new FakeQueueJob(1));
    $job->handle(F::runner($reads, Cache::store('array')), app(InsightRunStore::class), app(InsightGate::class));

    expect($reads->calls)->toBe([])
        ->and(app(InsightRunStore::class)->find($trendsRun)['status'])->toBe('queued');
});

it('treats hostile step values as plain data: they are valid and travel in the canonical definition only', function () {
    Queue::fake();
    $evil = "x'; DROP TABLE events; --";

    fPost($this->member, $this->project, fBody(['steps' => [
        ['event' => $evil, 'filters' => [['key' => '$.x', 'operator' => 'contains', 'value' => '%_\\']]],
        ['event' => '{from:DateTime64(3)}'],
    ]]))->assertStatus(202);

    expect(fLastJob()->canonical['steps'][0]['event'])->toBe($evil)
        ->and(fLastJob()->canonical['steps'][1]['event'])->toBe('{from:DateTime64(3)}');
});
