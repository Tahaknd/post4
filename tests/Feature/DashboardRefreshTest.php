<?php

use App\Domain\Dashboards\Refresh\ClickHouseHealth;
use App\Domain\Dashboards\Refresh\DashboardRefresh;
use App\Domain\Dashboards\Refresh\QueueBacklog;
use App\Domain\Dashboards\Refresh\RefreshSummary;
use App\Domain\Dashboards\SavedQueryResolver;
use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Runs\InsightRunStarter;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Jobs\RunInsight;
use App\Jobs\RunTrendsInsight;
use App\Models\Dashboard;
use App\Models\DashboardTile;
use App\Models\Project;
use App\Models\SavedInsight;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\ScriptedReads;
use Tests\Support\TrendsFixtures as T;

/*
 * The scheduled refresh with fakes for ClickHouse health and the queue backlog, and the faked queue: what a tick queues
 * (always as the scheduler, on the low priority queue, flagged for the dashboard cache), what it leaves alone and why.
 */

final class FakeHealth implements ClickHouseHealth
{
    public function __construct(public bool $healthy = true) {}

    public function isHealthy(): bool
    {
        return $this->healthy;
    }
}

final class FakeBacklog implements QueueBacklog
{
    public function __construct(public int|Throwable $size = 0) {}

    public function size(): int
    {
        if ($this->size instanceof Throwable) {
            throw $this->size;
        }

        return $this->size;
    }
}

beforeEach(function () {
    config([
        'insights.cache_store' => 'array',
        'insights.cache_ttl_seconds' => 60,
        'insights.max_active_runs_per_project' => 20,
        'dashboards.refresh.enabled' => true,
        'dashboards.refresh.jitter_seconds' => 0,
        'dashboards.refresh.max_insights_per_tick' => 30,
        'dashboards.refresh.max_insights_per_project_per_tick' => 5,
        'dashboards.refresh.max_queue_backlog' => 10,
        'dashboards.refresh.stale_after_seconds' => 840,
        'dashboards.refresh.result_ttl_seconds' => 2100,
    ]);
    Cache::store('array')->flush();
    LimiterOutage::reset();
    InsightRunner::resetWarningThrottle();
    Carbon::setTestNow('2026-03-15 10:00:00 UTC');
    Queue::fake();
    $this->health = new FakeHealth;
    $this->backlog = new FakeBacklog;
    app()->instance(ClickHouseHealth::class, $this->health);
    app()->instance(QueueBacklog::class, $this->backlog);
    $this->project = Project::factory()->create(['timezone' => 'UTC']);
});

afterEach(fn () => Carbon::setTestNow());

/** A dashboard viewed `$viewedMinutesAgo` minutes ago with one tile per insight (a name => row override map or a list of names). */
function viewedDashboard(Project $project, array $insights, int $viewedMinutesAgo = 5): Dashboard
{
    $dashboard = Dashboard::factory()->create(['project_id' => $project->id, 'last_viewed_at' => now()->subMinutes($viewedMinutesAgo)]);
    foreach ($insights as $i => $row) {
        $insight = $row instanceof SavedInsight ? $row : SavedInsight::factory()->create(['project_id' => $project->id, 'name' => 'i'.$i, ...(is_array($row) ? $row : [])]);
        DashboardTile::create(['uuid' => (string) Str::uuid7(), 'project_id' => $project->id, 'dashboard_id' => $dashboard->id, 'saved_insight_id' => $insight->id, 'position' => $i]);
    }

    return $dashboard;
}

/** A trends insight with its own event, so its canonical query differs from the others. */
function trendsFor(Project $project, string $event): SavedInsight
{
    $query = TrendsQuery::fromInput(['events' => [$event], 'date_preset' => 'last_7_days'], new DateTimeZone('UTC'), now());

    return SavedInsight::factory()->create(['project_id' => $project->id, 'name' => $event, 'query' => $query->canonical()]);
}

function tick(): RefreshSummary
{
    return app(DashboardRefresh::class)->handle();
}

function storeFor(Project $project, SavedInsight $insight, int $minutesAgo): void
{
    $query = app(SavedQueryResolver::class)->resolve($insight, now())->query;
    Carbon::setTestNow(now()->subMinutes($minutesAgo));
    app(InsightRunner::class)->rememberForDashboard($project->id, $query, T::runner(new ScriptedReads([T::dayRows()]), new Repository(new ArrayStore))->run(1, T::definition()));
    Carbon::setTestNow(now()->addMinutes($minutesAgo));
}

// ---------------------------------------------------------------- skip rules

it('does nothing when it is switched off', function () {
    config(['dashboards.refresh.enabled' => false]);
    viewedDashboard($this->project, [[]]);

    $summary = tick();

    expect($summary->skipped)->toBe('disabled')->and($summary->line())->toBe('dashboards:refresh skipped (disabled)');
    Queue::assertNothingPushed();
});

it('is skipped while ClickHouse is not healthy', function () {
    $this->health->healthy = false;
    viewedDashboard($this->project, [[]]);

    expect(tick()->skipped)->toBe('clickhouse_unhealthy');
    Queue::assertNothingPushed();
});

it('is skipped when the insight queues hold more than the threshold, and runs at the threshold', function () {
    viewedDashboard($this->project, [[]]);

    $this->backlog->size = 11;
    expect(tick()->skipped)->toBe('queue_backed_up');
    Queue::assertNothingPushed();

    $this->backlog->size = 10;
    expect(tick()->queued)->toBe(1);
});

it('is skipped when the queue cannot be asked', function () {
    $this->backlog->size = new RuntimeException('redis down');
    viewedDashboard($this->project, [[]]);

    expect(tick()->skipped)->toBe('queue_unavailable');
    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------- what is refreshed

it('refreshes only dashboards viewed within the window', function () {
    config(['dashboards.refresh.viewed_within_days' => 3]);
    viewedDashboard($this->project, [['name' => 'recent']], viewedMinutesAgo: 60 * 24 * 2);
    viewedDashboard($this->project, [trendsFor($this->project, 'old')], viewedMinutesAgo: 60 * 24 * 4);
    $never = Dashboard::factory()->create(['project_id' => $this->project->id, 'last_viewed_at' => null]);
    DashboardTile::create(['uuid' => (string) Str::uuid7(), 'project_id' => $this->project->id, 'dashboard_id' => $never->id, 'saved_insight_id' => trendsFor($this->project, 'never')->id, 'position' => 0]);

    $summary = tick();

    expect($summary->dashboards)->toBe(1)->and($summary->queued)->toBe(1);
    Queue::assertPushed(RunTrendsInsight::class, 1);
});

it('queues each run as the scheduler on the refresh queue, flagged for the dashboard cache, with today as the range', function () {
    viewedDashboard($this->project, [[]]);
    Carbon::setTestNow('2026-04-20 08:00:00 UTC');
    Dashboard::query()->update(['last_viewed_at' => now()]);

    tick();

    Queue::assertPushedOn('insights-refresh', RunTrendsInsight::class, function (RunInsight $job) {
        return $job->userId === InsightRunStarter::SYSTEM_USER_ID
            && $job->userSlot === null && $job->forDashboard === true
            && $job->canonical['range'] === ['from' => '2026-04-14T00:00:00.000Z', 'to' => '2026-04-21T00:00:00.000Z'];
    });
});

it('skips an insight that needs review and counts it', function () {
    viewedDashboard($this->project, [['query' => ['v' => 1], 'query_version' => 1], ['type' => 'paths'], trendsFor($this->project, 'good')]);

    $summary = tick();

    expect($summary->invalid)->toBe(2)->and($summary->queued)->toBe(1)->and($summary->insights)->toBe(3);
    Queue::assertPushed(RunTrendsInsight::class, 1);
});

it('runs an insight that sits on several dashboards once', function () {
    $shared = trendsFor($this->project, 'shared');
    viewedDashboard($this->project, [$shared]);
    viewedDashboard($this->project, [$shared]);

    $summary = tick();

    expect($summary->insights)->toBe(1)->and($summary->queued)->toBe(1)->and($summary->dashboards)->toBe(2);
});

it('runs two insights with the same canonical query of one project once, and of two projects twice', function () {
    $other = Project::factory()->create(['timezone' => 'UTC']);
    viewedDashboard($this->project, [trendsFor($this->project, 'same'), SavedInsight::factory()->create(['project_id' => $this->project->id, 'name' => 'twin', 'query' => trendsFor($this->project, 'same')->query])]);
    viewedDashboard($other, [trendsFor($other, 'same')]);

    $summary = tick();

    expect($summary->deduped)->toBe(1)->and($summary->queued)->toBe(2);
    Queue::assertPushed(RunTrendsInsight::class, 2);
});

it('leaves a fresh dashboard result alone and refreshes a stale or missing one', function () {
    $fresh = trendsFor($this->project, 'fresh');
    $stale = trendsFor($this->project, 'stale');
    $missing = trendsFor($this->project, 'missing');
    viewedDashboard($this->project, [$fresh, $stale, $missing]);
    storeFor($this->project, $fresh, 5);
    storeFor($this->project, $stale, 20);

    $summary = tick();

    expect($summary->fresh)->toBe(1)->and($summary->queued)->toBe(2);
    $events = Queue::pushed(RunTrendsInsight::class)->map(fn ($job) => $job->canonical['events'][0])->sort()->values()->all();
    expect($events)->toBe(['missing', 'stale']);
});

it('copies a result the interactive cache already has, without a query or a job', function () {
    $insight = trendsFor($this->project, 'warm');
    viewedDashboard($this->project, [$insight]);
    $query = app(SavedQueryResolver::class)->resolve($insight, now())->query;
    T::runner(new ScriptedReads([T::dayRows()]), Cache::store('array'))->run($this->project->id, $query);

    $summary = tick();

    expect($summary->fromCache)->toBe(1)->and($summary->queued)->toBe(0)
        ->and(app(InsightRunner::class)->cachedForDashboard($this->project->id, $query))->not->toBeNull();
    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------- bounds and fairness

it('queues at most the budget of the tick and counts the rest as capped', function () {
    config(['dashboards.refresh.max_insights_per_tick' => 3, 'dashboards.refresh.max_insights_per_project_per_tick' => 10]);
    viewedDashboard($this->project, array_map(fn ($n) => trendsFor($this->project, "e{$n}"), range(1, 8)));

    $summary = tick();

    expect($summary->queued)->toBe(3)->and($summary->capped)->toBe(5);
    Queue::assertPushed(RunTrendsInsight::class, 3);
});

it('queues at most the share of one project, so another project still gets its turn', function () {
    config(['dashboards.refresh.max_insights_per_tick' => 30, 'dashboards.refresh.max_insights_per_project_per_tick' => 2]);
    $other = Project::factory()->create(['timezone' => 'UTC']);
    viewedDashboard($this->project, array_map(fn ($n) => trendsFor($this->project, "a{$n}"), range(1, 6)));
    viewedDashboard($other, [trendsFor($other, 'b1')]);

    $summary = tick();

    expect($summary->queued)->toBe(3)->and($summary->capped)->toBe(4);
    $counts = Queue::pushed(RunTrendsInsight::class)->pluck('projectId')->countBy()->all();
    expect($counts[$this->project->id])->toBe(2)->and($counts[$other->id])->toBe(1);
});

it('serves the oldest cached result first when it cannot do everything, so every insight gets its turn', function () {
    config(['dashboards.refresh.max_insights_per_tick' => 2, 'dashboards.refresh.max_insights_per_project_per_tick' => 10]);
    $insights = [];
    foreach (['a', 'b', 'c', 'd'] as $name) {
        $insights[$name] = trendsFor($this->project, $name);
    }
    viewedDashboard($this->project, array_values($insights));
    storeFor($this->project, $insights['a'], 30);
    storeFor($this->project, $insights['b'], 20);
    storeFor($this->project, $insights['c'], 25);
    // d was never computed: first, then the oldest (a)

    tick();

    expect(Queue::pushed(RunTrendsInsight::class)->map(fn ($j) => $j->canonical['events'][0])->sort()->values()->all())->toBe(['a', 'd']);
});

it('does not queue the same run twice in two ticks while it is still waiting', function () {
    viewedDashboard($this->project, [[]]);

    tick();
    tick();

    Queue::assertPushed(RunTrendsInsight::class, 1);
});

it('stops asking a project that answers busy, and keeps going for the others', function () {
    config(['insights.max_active_runs_per_project' => 1]);
    $other = Project::factory()->create(['timezone' => 'UTC']);
    viewedDashboard($this->project, [trendsFor($this->project, 'a'), trendsFor($this->project, 'b'), trendsFor($this->project, 'c')]);
    viewedDashboard($other, [trendsFor($other, 'x')]);

    $summary = tick();

    expect($summary->queued)->toBe(2)->and($summary->busy)->toBe(2);
});

it('spreads the start of the runs over the jitter window', function () {
    config(['dashboards.refresh.jitter_seconds' => 60, 'dashboards.refresh.max_insights_per_project_per_tick' => 20]);
    viewedDashboard($this->project, array_map(fn ($n) => trendsFor($this->project, "j{$n}"), range(1, 12)));

    tick();

    $delays = Queue::pushed(RunTrendsInsight::class)->map(fn ($job) => $job->delay ?? 0);
    expect($delays)->toHaveCount(12)->and($delays->min())->toBeGreaterThanOrEqual(0)->and($delays->max())->toBeLessThanOrEqual(60)
        ->and($delays->unique()->count())->toBeGreaterThan(1);
});

it('adds no delay without jitter', function () {
    viewedDashboard($this->project, [[]]);

    tick();

    expect(Queue::pushed(RunTrendsInsight::class)->first()->delay)->toBeNull();
});

// ---------------------------------------------------------------- the command and the schedule

it('prints one summary line, exits 0 and writes the log line only with --log', function () {
    Log::spy();
    viewedDashboard($this->project, [[]]);

    $code = Artisan::call('dashboards:refresh');
    expect($code)->toBe(0)->and(Artisan::output())->toContain('dashboards:refresh dashboards=1 insights=1 queued=1');
    Log::shouldNotHaveReceived('log');

    Cache::store('array')->flush();
    Artisan::call('dashboards:refresh', ['--log' => true]);
    Log::shouldHaveReceived('log')->withArgs(fn ($level, $message, $context) => $level === 'info' && $message === 'dashboards:refresh' && $context['queued'] === 1)->once();
});

it('exits 0 for a tick that is skipped on purpose', function () {
    $this->health->healthy = false;

    expect(Artisan::call('dashboards:refresh'))->toBe(0)->and(Artisan::output())->toContain('skipped (clickhouse_unhealthy)');
});

it('is scheduled every 15 minutes inside the scheduler process, without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'dashboards:refresh');

    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/15 * * * *')->and($event->withoutOverlapping)->toBeTrue();
});

it('has a default budget that fits the capacity it shares', function () {
    $config = require base_path('config/dashboards.php');

    // one tick cannot hold more than the insights queue can run in a few minutes, and the TTL outlives a missed tick
    expect($config['refresh']['stale_after_seconds'])->toBeLessThan($config['refresh']['interval_minutes'] * 60)
        ->and($config['refresh']['result_ttl_seconds'])->toBeGreaterThanOrEqual(2 * $config['refresh']['interval_minutes'] * 60)
        ->and($config['refresh']['max_insights_per_project_per_tick'])->toBeLessThan($config['refresh']['max_insights_per_tick'])
        ->and($config['refresh']['queue'])->toBe('insights-refresh');
});
