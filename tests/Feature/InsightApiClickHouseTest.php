<?php

use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Runs\InsightRunStore;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Domain\Insights\Trends\TrendsSqlBuilder;
use App\Infrastructure\ClickHouse\ClickHouseReads;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\ClickHouseIntegration as CH;

/*
 * The insight API against a real ClickHouse (25.8) through the real chain: HTTP request, FormRequest, queued job
 * on the sync driver, InsightRunner, scoped reader, read-only user, scoped view. Opt-in (group "clickhouse"):
 *
 *   docker compose exec -T app composer test:clickhouse
 *
 * Two tenants with fixed rows (see ClickHouseIntegration::seedApi()) and hand-computed numbers: A has signups
 * [2, 2, 1] on 03-14..03-16 (unique users [2, 1, 1]) and one purchase on 03-15; B has [3, 0, 4] (unique [2, 0, 1]).
 */

pest()->group('clickhouse');

/** Counts the queries that reach ClickHouse, delegating to the real read-only client. */
final class CountingReads implements ClickHouseReads
{
    public int $count = 0;

    public function __construct(private readonly ClickHouseReads $inner) {}

    public function select(string $sql, array $params = [], array $settings = [], ?int $timeout = null): array
    {
        $this->count++;

        return $this->inner->select($sql, $params, $settings, $timeout);
    }
}

beforeEach(function () {
    config([
        'insights.cache_store' => 'array',
        'insights.cache_ttl_seconds' => 60,
        'queue.default' => 'sync',
    ]);
    Cache::store('array')->flush();
    Carbon::setTestNow('2026-03-20 08:00:00 UTC');

    $this->ch = CH::connect();
    $this->ch->seedApiOnce();
    $this->reads = new CountingReads($this->ch->ro);
    app()->instance(InsightRunner::class, new InsightRunner(new ProjectScopedReader($this->reads), new TrendsSqlBuilder, Cache::store('array')));

    $this->a = Project::factory()->create(['id' => CH::API_A, 'timezone' => 'UTC']);
    $this->b = Project::factory()->create(['id' => CH::API_B, 'timezone' => 'UTC']);
    $this->c = Project::factory()->create(['id' => CH::API_C, 'timezone' => 'UTC']);
    $this->memberA = User::factory()->create();
    $this->memberB = User::factory()->create();
    $this->a->users()->attach($this->memberA);
    $this->b->users()->attach($this->memberB);
});

afterEach(fn () => Carbon::setTestNow());

afterAll(function () {
    CH::cleanupApiAfterAll();
});

function chBody(array $o = []): array
{
    return [...['events' => ['signup'], 'date_from' => '2026-03-14', 'date_to' => '2026-03-16'], ...$o];
}

function chPost(User $user, Project $project, array $body): TestResponse
{
    return test()->actingAs($user)->postJson("/api/projects/{$project->id}/insights/trends", $body);
}

/** POST, then poll the run once (the sync queue has already executed the job). */
function chRun(User $user, Project $project, array $body): TestResponse
{
    $post = chPost($user, $project, $body)->assertStatus(202);

    return test()->actingAs($user)->getJson($post->json('poll_url'));
}

it('computes the trends of each tenant exactly through the API', function () {
    $a = chRun($this->memberA, $this->a, chBody())->assertOk();
    $b = chRun($this->memberB, $this->b, chBody())->assertOk();

    expect($a->json('status'))->toBe('done')
        ->and($a->json('result.series.0.event'))->toBe('signup')
        ->and($a->json('result.series.0.data'))->toBe([2, 2, 1])
        ->and($a->json('result.labels.0'))->toBe('2026-03-14T00:00:00+00:00')
        ->and($b->json('result.series.0.data'))->toBe([3, 0, 4]);
});

it('counts unique users, several series and a breakdown exactly', function () {
    $users = chRun($this->memberA, $this->a, chBody(['metric' => 'unique_users']));
    $series = chRun($this->memberA, $this->a, chBody(['events' => ['signup', 'purchase']]));
    $byPlan = chRun($this->memberA, $this->a, chBody(['breakdown' => ['property' => 'plan', 'top_n' => 5]]));

    expect($users->json('result.series.0.data'))->toBe([2, 1, 1])
        ->and(array_column($series->json('result.series'), 'data', 'event'))->toBe(['signup' => [2, 2, 1], 'purchase' => [0, 1, 0]]);

    $groups = [];
    foreach ($byPlan->json('result.series') as $s) {
        $groups[$s['is_none'] ? '(none)' : $s['breakdown']] = $s['data'];
    }
    ksort($groups);
    expect($groups)->toBe(['(none)' => [2, 0, 0], 'free' => [0, 1, 0], 'pro' => [0, 1, 1]]);
});

it('filters by property and serves the identical result from the cache', function () {
    $body = chBody(['filters' => [['key' => 'plan', 'operator' => 'equals', 'value' => 'pro']]]);

    $api = chRun($this->memberA, $this->a, $body);
    $post = chPost($this->memberA, $this->a, $body); // cache hit now
    $post->assertOk();

    expect($api->json('result.series.0.data'))->toBe([0, 1, 1])
        ->and($post->json('result'))->toBe($api->json('result'));
});

it('resolves a relative preset on the server and answers the repeat from the cache with a single ClickHouse query', function () {
    config(['insights.cache_ttl_seconds' => 86400]); // longer than the simulated day, so only the key can make a miss
    Carbon::setTestNow('2026-03-16 12:00:00 UTC');
    $body = ['events' => ['signup'], 'date_preset' => 'last_7_days'];

    $first = chRun($this->memberA, $this->a, $body)->assertOk();
    $queriesAfterFirst = $this->reads->count;
    $second = chPost($this->memberA, $this->a, $body)->assertOk();
    Carbon::setTestNow('2026-03-16 23:59:00 UTC'); // later the same day: same key
    $third = chPost($this->memberA, $this->a, $body)->assertOk();

    expect($first->json('result.series.0.data'))->toBe([0, 0, 0, 0, 2, 2, 1])
        ->and($first->json('result.labels.0'))->toBe('2026-03-10T00:00:00+00:00')
        ->and($queriesAfterFirst)->toBe(1)
        ->and($this->reads->count)->toBe(1)
        ->and($second->json('result'))->toBe($first->json('result'))
        ->and($third->json('status'))->toBe('done');

    Carbon::setTestNow('2026-03-17 00:00:01 UTC'); // next local day: a new definition, a new query
    chRun($this->memberA, $this->a, $body)->assertOk();
    expect($this->reads->count)->toBe(2);
});

it('gives a project without rows zeros on the same axis', function () {
    $c = chRun(User::factory()->admin()->create(), $this->c, chBody())->assertOk();

    expect($c->json('result.series.0.data'))->toBe([0, 0, 0])
        ->and($c->json('result.buckets'))->toHaveCount(3);
});

it('keeps tenants apart through every route: a member of A never gets B data', function () {
    $runB = chPost($this->memberB, $this->b, chBody())->assertStatus(202)->json('run_id');
    $runA = chPost($this->memberA, $this->a, chBody())->assertStatus(202)->json('run_id');

    // start a run on B, read B's run through A's project, read A's run through B's project, and the plain guesses
    chPost($this->memberA, $this->b, chBody())->assertNotFound();
    $this->actingAs($this->memberA)->getJson("/api/projects/{$this->b->id}/insights/runs/{$runB}")->assertNotFound();
    $this->actingAs($this->memberA)->getJson("/api/projects/{$this->a->id}/insights/runs/{$runB}")->assertNotFound();
    $this->actingAs($this->memberB)->getJson("/api/projects/{$this->a->id}/insights/runs/{$runA}")->assertNotFound();
    $this->actingAs($this->memberB)->getJson("/api/projects/{$this->b->id}/insights/runs/{$runA}")->assertNotFound();
    // a body project id is not a way in
    chPost($this->memberA, $this->a, chBody(['project_id' => $this->b->id]))->assertStatus(422);

    // each member sees only their own tenant, even breaking down by distinct id (b1, b2 exist only in B)
    $byUser = chRun($this->memberA, $this->a, chBody(['breakdown' => ['column' => 'distinct_id']]));
    $labels = array_column($byUser->json('result.series'), 'breakdown');
    sort($labels);
    expect($labels)->toBe(['u1', 'u2', 'u3'])
        ->and($this->actingAs($this->memberA)->getJson("/api/projects/{$this->a->id}/insights/runs/{$runA}")->json('result.series.0.data'))->toBe([2, 2, 1])
        ->and($this->actingAs($this->memberB)->getJson("/api/projects/{$this->b->id}/insights/runs/{$runB}")->json('result.series.0.data'))->toBe([3, 0, 4]);
});

it('stops serving a run after the membership is removed', function () {
    $runId = chPost($this->memberA, $this->a, chBody())->assertStatus(202)->json('run_id');
    $this->actingAs($this->memberA)->getJson("/api/projects/{$this->a->id}/insights/runs/{$runId}")->assertOk()->assertJsonPath('status', 'done');

    $this->a->users()->detach($this->memberA);

    $this->actingAs($this->memberA)->getJson("/api/projects/{$this->a->id}/insights/runs/{$runId}")->assertNotFound();
});

it('treats hostile event names and filter values as data against the real database', function () {
    $response = chRun($this->memberA, $this->a, chBody(['events' => ["x'; DROP TABLE posthog.events; --"], 'filters' => [['key' => '$.a', 'operator' => 'contains', 'value' => "%_'\\"]]]))->assertOk();

    expect($response->json('status'))->toBe('done')
        ->and($response->json('result.series.0.data'))->toBe([0, 0, 0])
        ->and(DB::table('failed_jobs')->count())->toBe(0);
    // and the tables are untouched
    expect((int) $this->ch->admin->select('SELECT count() AS n FROM posthog.events WHERE project_id = '.CH::API_A)[0]['n'])->toBe(6);
});

it('rebuilds the same definition the runner would run for the posted body', function () {
    $post = chPost($this->memberA, $this->a, chBody(['interval' => 'day']))->assertStatus(202);
    $record = app(InsightRunStore::class)->find($post->json('run_id'));

    expect($record['status'])->toBe('done')
        ->and($record['hash'])->toBe(TrendsQuery::fromCanonical([
            'v' => 1, 'events' => ['signup'], 'metric' => 'total_events',
            'range' => ['from' => '2026-03-14T00:00:00.000Z', 'to' => '2026-03-17T00:00:00.000Z'],
            'interval' => 'day', 'timezone' => 'UTC', 'breakdown' => null, 'filters' => [],
        ])->hash());
});
