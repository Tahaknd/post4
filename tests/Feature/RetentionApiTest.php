<?php

use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Retention\RetentionQuery;
use App\Domain\Insights\Runs\InsightGate;
use App\Domain\Insights\Runs\InsightRunStore;
use App\Http\ApiErrors;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use App\Jobs\RunFunnelInsight;
use App\Jobs\RunRetentionInsight;
use App\Models\Project;
use App\Models\User;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeQueueJob;
use Tests\Support\RetentionFixtures as R;
use Tests\Support\ScriptedReads;

/*
 * The retention endpoint of the insight API. Everything the runs share with the other types (the state machine, slots,
 * single flight, busy handling, the 404 matrix of the poll) is pinned for trends in InsightApiTest / RunTrendsInsightTest and
 * is the SAME code (InsightController::start, RunInsight); here: the retention body, its validation, its definition, and a
 * pass through the whole flow (cache hit, queued run, poll, cancel, authorisation, tenant isolation) to prove the type is
 * wired in. Store: the in-memory array store; the queue is faked unless a test lets the job run inline on the sync queue.
 * The clock is fixed at 2026-03-15 10:00 UTC (a Sunday): the first period of a daily table that starts 03-16 has not begun.
 */

const RETENTION_MARKER = 'ZZ-HOSTILE-MARKER';

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

function rBody(array $o = []): array
{
    return [...['target' => ['event' => 'signup'], 'returning' => ['event' => 'visit'], 'date_from' => '2026-03-09', 'date_to' => '2026-03-10', 'periods' => 3], ...$o];
}

function rPost(User $user, Project|int $project, array $body): TestResponse
{
    $id = $project instanceof Project ? $project->id : $project;

    return test()->actingAs($user)->postJson("/api/projects/{$id}/insights/retention", $body);
}

function rPoll(User $user, Project|int $project, string $run): TestResponse
{
    $id = $project instanceof Project ? $project->id : $project;

    return test()->actingAs($user)->getJson("/api/projects/{$id}/insights/runs/{$run}");
}

function rQueue(User $user, Project $project, array $body = []): string
{
    Queue::fake();

    return rPost($user, $project, rBody($body))->assertStatus(202)->json('run_id');
}

function rLastJob(): RunRetentionInsight
{
    return Queue::pushed(RunRetentionInsight::class)->last();
}

/** A filter list of n filters. */
function rFilters(int $n): array
{
    return array_fill(0, $n, ['key' => 'k', 'operator' => 'is_set', 'value' => null]);
}

// ---------------------------------------------------------------- wiring

it('keeps the strings of the retention body exactly as sent, like the other types', function () {
    Queue::fake();

    rPost($this->member, $this->project, rBody([
        'target' => ['event' => '  signup  ', 'filters' => [['key' => ' plan ', 'operator' => 'equals', 'value' => '']]],
        'returning' => ['event' => ' b '],
    ]))->assertStatus(202);

    $canonical = rLastJob()->canonical;
    expect($canonical['target']['event'])->toBe('  signup  ')
        ->and($canonical['target']['filters'])->toBe([['key' => ' plan ', 'operator' => 'equals', 'value' => '']])
        ->and($canonical['returning']['event'])->toBe(' b ');
});

// ---------------------------------------------------------------- authorisation

it('answers a guest with 401 JSON, never a redirect', function () {
    $response = $this->call('POST', "/api/projects/{$this->project->id}/insights/retention", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(rBody()));

    expect($response->getStatusCode())->toBe(401)
        ->and($response->headers->has('Location'))->toBeFalse()
        ->and($response->json('message'))->toBe('Unauthenticated.');
});

it('shows a non-member exactly what a missing project looks like, and lets a member and an administrator start a run', function () {
    Queue::fake();
    $outsider = User::factory()->create();
    $admin = User::factory()->admin()->create();

    $forbidden = rPost($outsider, $this->project, rBody())->assertNotFound();
    $missing = rPost($outsider, 999999, rBody())->assertNotFound();
    expect($forbidden->getContent())->toBe($missing->getContent())->and($forbidden->json())->toBe(ApiErrors::body('not_found', 'Not found.'));
    Queue::assertNothingPushed();

    rPost($this->member, $this->project, rBody())->assertStatus(202);
    rPost($admin, $this->project, rBody(['target' => ['event' => 'x']]))->assertStatus(202);
    Queue::assertPushed(RunRetentionInsight::class, 2);
    Queue::assertNotPushed(RunFunnelInsight::class);
});

// ---------------------------------------------------------------- validation

it('rejects an invalid body with 422, a fixed sentence and nothing queued', function (array $body, string $field) {
    Queue::fake();

    $response = rPost($this->member, $this->project, $body)->assertStatus(422);

    expect($response->json('errors'))->toHaveKey($field)
        ->and($response->json('error.kind'))->toBe('validation')
        ->and($response->getContent())->not->toContain(RETENTION_MARKER);
    Queue::assertNothingPushed();
})->with([
    'no target' => [['returning' => ['event' => 'a'], 'date_preset' => 'last_7_days'], 'target'],
    'no returning' => [['target' => ['event' => 'a'], 'date_preset' => 'last_7_days'], 'returning'],
    'target null' => [rBody(['target' => null]), 'target'],
    'target text' => [rBody(['target' => RETENTION_MARKER]), 'target'],
    'target a list' => [rBody(['target' => [RETENTION_MARKER]]), 'target'],
    'target without event' => [rBody(['target' => ['filters' => []]]), 'target.event'],
    'target unknown key' => [rBody(['target' => ['event' => 'a', RETENTION_MARKER => 1]]), 'target'],
    'event empty' => [rBody(['returning' => ['event' => '']]), 'returning.event'],
    'event too long' => [rBody(['returning' => ['event' => str_repeat('x', 201)]]), 'returning.event'],
    'event an array' => [rBody(['target' => ['event' => [RETENTION_MARKER]]]), 'target.event'],
    'filters too many' => [rBody(['target' => ['event' => 'a', 'filters' => rFilters(6)]]), 'target.filters'],
    'filters too many on returning' => [rBody(['returning' => ['event' => 'a', 'filters' => rFilters(6)]]), 'returning.filters'],
    'filters a map' => [rBody(['target' => ['event' => 'a', 'filters' => ['x' => ['key' => 'k', 'operator' => 'is_set', 'value' => null]]]]), 'target.filters'],
    'filter operator unknown' => [rBody(['target' => ['event' => 'a', 'filters' => [['key' => 'k', 'operator' => RETENTION_MARKER, 'value' => 'x']]]]), 'target.filters.0.operator'],
    'filter needs a value' => [rBody(['returning' => ['event' => 'a', 'filters' => [['key' => 'k', 'operator' => 'equals']]]]), 'returning.filters.0.value'],
    'is_set takes none' => [rBody(['returning' => ['event' => 'a', 'filters' => [['key' => 'k', 'operator' => 'is_set', 'value' => 'x']]]]), 'returning.filters.0.value'],
    'filter unknown key' => [rBody(['target' => ['event' => 'a', 'filters' => [['key' => 'k', 'operator' => 'is_set', 'value' => null, RETENTION_MARKER => 1]]]]), 'target.filters.0'],
    'period unknown' => [rBody(['period' => RETENTION_MARKER]), 'period'],
    'period hour' => [rBody(['period' => 'hour']), 'period'],
    'period empty' => [rBody(['period' => '']), 'period'],
    'periods zero' => [rBody(['periods' => 0]), 'periods'],
    'periods 31' => [rBody(['periods' => 31]), 'periods'],
    'periods as text' => [rBody(['periods' => '3']), 'periods'],
    'periods a float' => [rBody(['periods' => 3.5]), 'periods'],
    'periods null' => [rBody(['periods' => null]), 'periods'],
    'type unknown' => [rBody(['retention_type' => RETENTION_MARKER]), 'retention_type'],
    'type empty' => [rBody(['retention_type' => '']), 'retention_type'],
    'unknown field' => [rBody([RETENTION_MARKER => 1]), 'body'],
    'the funnel window is not a field' => [rBody(['window' => ['value' => 1, 'unit' => 'day']]), 'body'],
    'both date modes' => [rBody(['date_preset' => 'last_7_days']), 'date_preset'],
    'one day only' => [['target' => ['event' => 'a'], 'returning' => ['event' => 'b'], 'date_from' => '2026-03-01'], 'date_preset'],
    'bad zone' => [rBody(['timezone' => 'Mars/Base']), 'timezone'],
    'bad day' => [rBody(['date_from' => '2026-02-30']), 'date_from'],
]);

it('rejects combinations the domain refuses with one fixed sentence on `date_from`', function (array $body, string $field) {
    Queue::fake();

    $response = rPost($this->member, $this->project, $body)->assertStatus(422);

    expect($response->json('errors'))->toHaveKey($field)->and($response->getContent())->not->toContain(RETENTION_MARKER);
    Queue::assertNothingPushed();
})->with([
    'a range of more than 366 days' => [rBody(['date_from' => '2024-01-01', 'date_to' => '2026-03-01']), 'date_from'],
    'end before start' => [rBody(['date_from' => '2026-03-05', 'date_to' => '2026-03-01']), 'date_from'],
]);

it('answers the wrong media type, a huge body and broken JSON before anything else', function () {
    $url = "/api/projects/{$this->project->id}/insights/retention";
    $headers = ['HTTP_ACCEPT' => 'application/json'];
    Queue::fake();

    $form = $this->actingAs($this->member)->call('POST', $url, ['target' => 'x'], server: $headers);
    $huge = $this->actingAs($this->member)->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'] + $headers, content: json_encode(rBody(['target' => ['event' => str_repeat('x', 70000)]])));
    $broken = $this->actingAs($this->member)->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'] + $headers, content: '{"target": [');

    expect([$form->getStatusCode(), $huge->getStatusCode(), $broken->getStatusCode()])->toBe([415, 413, 400]);
    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------- the definition the body becomes

it('turns the body into the canonical definition with the defaults and the project zone', function () {
    Queue::fake();
    $project = Project::factory()->create(['timezone' => 'Europe/Istanbul']);
    $project->users()->attach($this->member);

    rPost($this->member, $project, ['target' => ['event' => 'a'], 'returning' => ['event' => 'b'], 'date_from' => '2026-03-01', 'date_to' => '2026-03-02'])->assertStatus(202);

    expect(rLastJob()->canonical)->toBe([
        'v' => RetentionQuery::VERSION,
        'target' => ['event' => 'a', 'filters' => []],
        'returning' => ['event' => 'b', 'filters' => []],
        'period' => 'day',
        'periods' => 8,
        'type' => 'first_time',
        'timezone' => 'Europe/Istanbul',
        'range' => ['from' => '2026-02-28T21:00:00.000Z', 'to' => '2026-03-02T21:00:00.000Z'],
    ]);
});

it('takes a period, a type, a zone and filters, and quantises a relative range to whole local days', function () {
    Queue::fake();
    $body = [
        'target' => ['event' => 'a', 'filters' => [['key' => 'plan', 'operator' => 'contains', 'value' => 'p']]],
        'returning' => ['event' => 'b'],
        'period' => 'week',
        'periods' => 5,
        'retention_type' => 'recurring',
        'timezone' => 'Asia/Kolkata',
        'date_preset' => 'last_30_days',
    ];

    rPost($this->member, $this->project, $body)->assertStatus(202);
    $first = rLastJob()->canonical;
    Carbon::setTestNow('2026-03-15 18:00:00 UTC');   // still 03-15 in Kolkata (UTC+5:30)
    Queue::fake();
    rPost($this->member, $this->project, $body)->assertStatus(202);

    expect($first['period'])->toBe('week')->and($first['periods'])->toBe(5)->and($first['type'])->toBe('recurring')->and($first['timezone'])->toBe('Asia/Kolkata')
        ->and($first['range'])->toBe(['from' => '2026-02-13T18:30:00.000Z', 'to' => '2026-03-15T18:30:00.000Z'])
        ->and(rLastJob()->canonical)->toBe($first);   // the same definition all local day: one cache entry
});

// ---------------------------------------------------------------- the run

it('queues a run on the insights queue with only ids and the canonical definition and answers 202', function () {
    Queue::fake();

    $response = rPost($this->member, $this->project, rBody())->assertStatus(202);
    $runId = $response->json('run_id');
    $poll = "/api/projects/{$this->project->id}/insights/runs/{$runId}";

    expect($response->json())->toBe(['status' => 'queued', 'run_id' => $runId, 'poll_url' => $poll])
        ->and($response->headers->get('Location'))->toBe($poll)
        ->and($response->headers->get('Retry-After'))->toBe('1');
    Queue::assertPushedOn('insights', RunRetentionInsight::class, fn (RunRetentionInsight $job) => $job->projectId === $this->project->id
        && $job->runId === $runId
        && $job->canonical === RetentionQuery::fromCanonical($job->canonical)->canonical());

    $record = app(InsightRunStore::class)->find($runId);
    expect($record['type'])->toBe('retention')->and($record['status'])->toBe('queued')->and($record['user_id'])->toBe($this->member->id);
});

it('answers an identical POST of the same user with the same run, and another user gets a run of their own', function () {
    Queue::fake();
    $colleague = User::factory()->create();
    $this->project->users()->attach($colleague);

    $first = rPost($this->member, $this->project, rBody())->assertStatus(202)->json('run_id');
    $again = rPost($this->member, $this->project, rBody())->assertStatus(202)->json('run_id');
    $other = rPost($colleague, $this->project, rBody())->assertStatus(202)->json('run_id');

    expect($again)->toBe($first)->and($other)->not->toBe($first);
    Queue::assertPushed(RunRetentionInsight::class, 2);
});

it('runs end to end on the sync queue: done, with the table, the nulls of unstarted periods and the averages, then a cache hit with no run', function () {
    // two daily cohorts, 03-09 and 03-10, three follow-up days; the clock is 03-15 10:00, so every period has started
    app()->instance(InsightRunner::class, R::runner(new ScriptedReads([R::rows([0 => [4, [2, 1, 1]], 1 => [2, [1, 0, 0]]])]), Cache::store('array')));

    $post = rPost($this->member, $this->project, rBody())->assertStatus(202);
    $poll = rPoll($this->member, $this->project, $post->json('run_id'))->assertOk();

    expect($poll->json('status'))->toBe('done')
        ->and($poll->json('result.period'))->toBe('day')->and($poll->json('result.periods'))->toBe(3)->and($poll->json('result.retention_type'))->toBe('first_time')
        ->and(array_column($poll->json('result.cohorts'), 'size'))->toBe([4, 2])
        ->and($poll->json('result.cohorts.0'))->toBe([
            'start' => strtotime('2026-03-09 UTC'), 'label' => '2026-03-09T00:00:00+00:00', 'size' => 4,
            'values' => [4, 2, 1, 1], 'rates' => [1, 0.5, 0.25, 0.25],
        ])
        ->and($poll->json('result.averages.rates'))->toBe([1, 0.5, 0.1667, 0.1667]);

    $hit = rPost($this->member, $this->project, rBody())->assertOk();
    expect($hit->json('status'))->toBe('done')->and($hit->json('run_id'))->toBeNull()->and($hit->json('result'))->toBe($poll->json('result'));
});

it('shows the periods that have not started as JSON null, not 0', function () {
    // today is 03-15: the cohort of 03-14 has period 0 and 1 (03-15), and then nothing yet
    app()->instance(InsightRunner::class, R::runner(new ScriptedReads([R::rows([0 => [4, [1, 0, 0]]])]), Cache::store('array')));

    $post = rPost($this->member, $this->project, rBody(['date_from' => '2026-03-14', 'date_to' => '2026-03-15']))->assertStatus(202);
    $poll = rPoll($this->member, $this->project, $post->json('run_id'))->assertOk();

    expect($poll->json('result.cohorts.0.values'))->toBe([4, 1, null, null])
        ->and($poll->json('result.cohorts.0.rates'))->toBe([1, 0.25, null, null])
        ->and($poll->json('result.averages.cohorts'))->toBe([1, 1, 0, 0]);
});

it('serves a fresh cached result to ANOTHER user of the project, but never to another project', function () {
    app()->instance(InsightRunner::class, R::runner(new ScriptedReads([R::rows([0 => [2, [1, 1, 1]]])]), Cache::store('array')));
    $colleague = User::factory()->create();
    $this->project->users()->attach($colleague);
    $other = Project::factory()->create();
    $other->users()->attach($this->member);

    rPost($this->member, $this->project, rBody())->assertStatus(202);

    rPost($colleague, $this->project, rBody())->assertOk()->assertJsonPath('status', 'done');
    Queue::fake();
    rPost($this->member, $other, rBody())->assertStatus(202);   // another project: a miss
});

it('maps a ClickHouse failure to a failed poll answer with the kind and no raw text anywhere', function (ClickHouseErrorKind $kind, int $code) {
    $raw = "Code: {$code}. DB::Exception: SECRET-RAW select * from posthog.events where project_id = 7";
    app()->instance(InsightRunner::class, R::runner(new ScriptedReads([new ClickHouseException($raw, $code, $kind)]), Cache::store('array')));

    $post = rPost($this->member, $this->project, rBody())->assertStatus(202);
    $poll = rPoll($this->member, $this->project, $post->json('run_id'));

    expect($poll->getContent())->not->toContain('SECRET-RAW')->not->toContain('posthog.events')
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($poll->json('status'))->toBe('failed')
        ->and($poll->json('error.kind'))->toBe($kind->value)
        ->and($poll->json('error.message'))->toBe($kind->userMessage());
})->with([
    'memory is the query too large of a retention table' => [ClickHouseErrorKind::MemoryLimit, 241],
    'timeout' => [ClickHouseErrorKind::Timeout, 159],
    'rows' => [ClickHouseErrorKind::TooManyRows, 396],
    'unknown' => [ClickHouseErrorKind::Unknown, 999],
]);

it('fails a run whose rows cannot belong to the definition, as unknown, with nothing cached', function () {
    // a cohort 9 in a table of two
    app()->instance(InsightRunner::class, R::runner(new ScriptedReads([R::rows([9 => [2, [1, 1, 1]]])]), Cache::store('array')));

    $post = rPost($this->member, $this->project, rBody())->assertStatus(202);
    $poll = rPoll($this->member, $this->project, $post->json('run_id'));

    expect($poll->json('status'))->toBe('failed')->and($poll->json('error.kind'))->toBe('unknown');
    Queue::fake();
    rPost($this->member, $this->project, rBody())->assertStatus(202);   // not served from the cache
});

it('cancels a queued retention run: 204, idempotent, and the poll says cancelled', function () {
    $runId = rQueue($this->member, $this->project);
    $url = "/api/projects/{$this->project->id}/insights/runs/{$runId}";

    $this->actingAs($this->member)->deleteJson($url)->assertNoContent();
    $this->actingAs($this->member)->deleteJson($url)->assertNoContent();

    rPoll($this->member, $this->project, $runId)->assertOk()->assertExactJson(['status' => 'cancelled', 'run_id' => $runId]);
    expect(rPost($this->member, $this->project, rBody())->assertStatus(202)->json('run_id'))->not->toBe($runId);
});

it('keeps the runs of a project to its own users: another member, another project and a non-member see the same 404', function () {
    $runId = rQueue($this->member, $this->project);
    $colleague = User::factory()->create();
    $this->project->users()->attach($colleague);
    $outsider = User::factory()->create();
    $other = Project::factory()->create();
    $other->users()->attach($this->member);
    $del = fn (User $user, Project|int $project, string $run) => $this->actingAs($user)->deleteJson('/api/projects/'.($project instanceof Project ? $project->id : $project)."/insights/runs/{$run}");

    $bodies = [
        rPoll($colleague, $this->project, $runId)->assertNotFound()->getContent(),
        rPoll($outsider, $this->project, $runId)->assertNotFound()->getContent(),
        rPoll($this->member, $other, $runId)->assertNotFound()->getContent(),
        $del($colleague, $this->project, $runId)->assertNotFound()->getContent(),
        $del($this->member, $other, $runId)->assertNotFound()->getContent(),
    ];

    expect(array_unique($bodies))->toHaveCount(1)->and(app(InsightRunStore::class)->find($runId)['status'])->toBe('queued');
});

it('shares the start throttle with the other types: all of them draw on the same insights.run budget', function () {
    Queue::fake();
    $budgets = config('ratelimit.user_throttles');
    $budgets['insights.run']['limit'] = 2;
    config(['ratelimit.user_throttles' => $budgets]);

    $this->actingAs($this->member)->postJson("/api/projects/{$this->project->id}/insights/trends", ['events' => ['a'], 'date_from' => '2026-03-01', 'date_to' => '2026-03-02'])->assertStatus(202);
    rPost($this->member, $this->project, rBody())->assertStatus(202);
    $third = rPost($this->member, $this->project, rBody(['target' => ['event' => 'x']]))->assertStatus(429);

    expect($third->json('error.kind'))->toBe('rate_limited');
});

it('does not let a retention job run the record of another insight type', function () {
    Queue::fake();
    $trendsRun = $this->actingAs($this->member)->postJson("/api/projects/{$this->project->id}/insights/trends", ['events' => ['a'], 'date_from' => '2026-03-01', 'date_to' => '2026-03-02'])->assertStatus(202)->json('run_id');
    $reads = new ScriptedReads([R::rows([0 => [1, [0, 0, 0]]])]);

    $job = new RunRetentionInsight($this->project->id, $trendsRun, R::query()->canonical(), $this->member->id);
    $job->setJob(new FakeQueueJob(1));
    $job->handle(R::runner($reads, Cache::store('array')), app(InsightRunStore::class), app(InsightGate::class));

    expect($reads->calls)->toBe([])
        ->and(app(InsightRunStore::class)->find($trendsRun)['status'])->toBe('queued');
});

it('treats hostile event values as plain data: they are valid and travel in the canonical definition only', function () {
    Queue::fake();
    $evil = "x'; DROP TABLE events; --";

    rPost($this->member, $this->project, rBody([
        'target' => ['event' => $evil, 'filters' => [['key' => '$.x', 'operator' => 'contains', 'value' => '%_\\']]],
        'returning' => ['event' => '{from:DateTime64(3)}'],
    ]))->assertStatus(202);

    expect(rLastJob()->canonical['target']['event'])->toBe($evil)
        ->and(rLastJob()->canonical['returning']['event'])->toBe('{from:DateTime64(3)}');
});
