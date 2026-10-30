<?php

use App\Domain\Dashboards\Refresh\ClickHouseHealth;
use App\Domain\Insights\InsightRunner;
use App\Infrastructure\ClickHouse\ClickHouseReads;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use App\Infrastructure\ClickHouse\ReadOnlyHealthProbe;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClickHouseIntegration as CH;

/*
 * Saved insights, a dashboard and the scheduled refresh against a real ClickHouse, through the real chain (HTTP, requests,
 * actions, starter, jobs on the sync queue, runner, scoped reader, read-only user): save a trends, a funnel and a retention
 * insight through the API, put them on a dashboard, open it, run `dashboards:refresh`, and the tile run endpoint answers
 * 200 from the dashboard cache without another query. Opt-in (group "clickhouse"):
 *
 *   docker compose exec -T app composer test:clickhouse
 *
 * Data: the insight API tenant A (see ClickHouseIntegration::seedApi()): signups [2, 2, 1] on 03-14..03-16 and one purchase.
 * The clock is 2026-03-20, so `last_7_days` is 03-14..03-20.
 */

pest()->group('clickhouse');

/** Counts the queries that reach ClickHouse, delegating to the real read-only client. */
final class RefreshCountingReads implements ClickHouseReads
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
        'dashboards.refresh.enabled' => true,
        'dashboards.refresh.jitter_seconds' => 0,
        'dashboards.refresh.result_ttl_seconds' => 2100,
        'dashboards.refresh.stale_after_seconds' => 840,
    ]);
    Cache::store('array')->flush();
    Carbon::setTestNow('2026-03-20 08:00:00 UTC');

    $this->ch = CH::connect();
    $this->ch->seedApiOnce();
    $this->reads = new RefreshCountingReads($this->ch->ro);
    app()->instance(InsightRunner::class, new InsightRunner(new ProjectScopedReader($this->reads), Cache::store('array')));
    app()->instance(ClickHouseHealth::class, new ReadOnlyHealthProbe($this->ch->ro));

    $this->project = Project::factory()->create(['id' => CH::API_A, 'timezone' => 'UTC']);
    $this->member = User::factory()->create();
    $this->project->users()->attach($this->member);
});

afterEach(fn () => Carbon::setTestNow());

afterAll(function () {
    CH::cleanupApiAfterAll();
});

/** @return array{ids: array<string, string>, dashboard: string} */
function chDashboardWithThreeInsights(object $t): array
{
    $bodies = [
        'trends' => ['events' => ['signup'], 'date_preset' => 'last_7_days'],
        'funnel' => ['steps' => [['event' => 'signup'], ['event' => 'purchase']], 'date_preset' => 'last_7_days'],
        'retention' => ['target' => ['event' => 'signup'], 'returning' => ['event' => 'purchase'], 'date_preset' => 'last_7_days', 'periods' => 3],
    ];

    $ids = [];
    foreach ($bodies as $type => $query) {
        $ids[$type] = $t->actingAs($t->member)->postJson("/api/projects/{$t->project->id}/saved-insights", ['name' => "Saved {$type}", 'type' => $type, 'query' => $query])
            ->assertCreated()->assertJsonPath('data.state.status', 'ok')->json('data.id');
    }

    $dashboard = $t->actingAs($t->member)->postJson("/api/projects/{$t->project->id}/dashboards", ['name' => 'Growth'])->assertCreated()->json('data.id');
    foreach ($ids as $id) {
        $t->actingAs($t->member)->postJson("/api/projects/{$t->project->id}/dashboards/{$dashboard}/tiles", ['saved_insight_id' => $id])->assertCreated();
    }

    return ['ids' => $ids, 'dashboard' => $dashboard];
}

it('refreshes a viewed dashboard so every tile is then served from the cache with no further query', function () {
    ['ids' => $ids, 'dashboard' => $dashboard] = chDashboardWithThreeInsights($this);

    // nothing viewed yet: nothing to refresh
    Artisan::call('dashboards:refresh');
    expect(Artisan::output())->toContain('dashboards=0')->and($this->reads->count)->toBe(0);

    $this->actingAs($this->member)->getJson("/api/projects/{$this->project->id}/dashboards/{$dashboard}")->assertOk()->assertJsonCount(3, 'data.tiles');

    expect(Artisan::call('dashboards:refresh'))->toBe(0)
        ->and(Artisan::output())->toContain('dashboards=1 insights=3 queued=3');
    // the jobs ran inline on the sync queue: one real query per insight
    expect($this->reads->count)->toBe(3);

    foreach ($ids as $type => $id) {
        $tile = $this->actingAs($this->member)->postJson("/api/projects/{$this->project->id}/saved-insights/{$id}/run")->assertOk();

        expect($tile->json('status'))->toBe('done')->and($tile->json('run_id'))->toBeNull()
            ->and($tile->json('source'))->toBe('dashboard_cache')
            ->and($tile->json('computed_at'))->toBe('2026-03-20T08:00:00+00:00');

        if ($type === 'trends') {
            expect($tile->json('result.series.0.data'))->toBe([2, 2, 1, 0, 0, 0, 0]);
        }
        // the same answer the insight endpoint gives for the same definition
        $direct = $this->actingAs($this->member)->postJson("/api/projects/{$this->project->id}/insights/{$type}", match ($type) {
            'trends' => ['events' => ['signup'], 'date_preset' => 'last_7_days'],
            'funnel' => ['steps' => [['event' => 'signup'], ['event' => 'purchase']], 'date_preset' => 'last_7_days'],
            'retention' => ['target' => ['event' => 'signup'], 'returning' => ['event' => 'purchase'], 'date_preset' => 'last_7_days', 'periods' => 3],
        })->assertOk();
        expect($tile->json('result'))->toBe($direct->json('result'));
    }
    expect($this->reads->count)->toBe(3);
});

it('leaves fresh results alone, and renews them once they are stale', function () {
    chDashboardWithThreeInsights($this);
    $dashboard = DB::table('dashboards')->value('uuid');
    $this->actingAs($this->member)->getJson("/api/projects/{$this->project->id}/dashboards/{$dashboard}")->assertOk();

    Artisan::call('dashboards:refresh');
    expect($this->reads->count)->toBe(3);

    Carbon::setTestNow('2026-03-20 08:10:00 UTC');
    Artisan::call('dashboards:refresh');
    expect(Artisan::output())->toContain('queued=0')->toContain('fresh=3')->and($this->reads->count)->toBe(3);

    // 15 minutes on: older than stale_after (14 min), the interactive cache (60 s) is long gone, still inside the TTL
    Carbon::setTestNow('2026-03-20 08:15:00 UTC');
    $this->actingAs($this->member)->postJson("/api/projects/{$this->project->id}/saved-insights/".DB::table('saved_insights')->where('type', 'trends')->value('uuid').'/run')->assertOk()->assertJsonPath('source', 'dashboard_cache');
    Artisan::call('dashboards:refresh');
    expect(Artisan::output())->toContain('queued=3')->and($this->reads->count)->toBe(6);

    $tile = $this->actingAs($this->member)->postJson("/api/projects/{$this->project->id}/saved-insights/".DB::table('saved_insights')->where('type', 'trends')->value('uuid').'/run')->assertOk();
    expect($tile->json('computed_at'))->toBe('2026-03-20T08:15:00+00:00')->and($this->reads->count)->toBe(6);
});

it('fills the dashboard cache when a tile is opened first, so the next viewer is served from it', function () {
    ['ids' => $ids] = chDashboardWithThreeInsights($this);

    $queued = $this->actingAs($this->member)->postJson("/api/projects/{$this->project->id}/saved-insights/{$ids['trends']}/run")->assertStatus(202);
    $poll = $this->actingAs($this->member)->getJson($queued->json('poll_url'))->assertOk();
    expect($poll->json('result.series.0.data'))->toBe([2, 2, 1, 0, 0, 0, 0]);

    Carbon::setTestNow('2026-03-20 08:05:00 UTC');
    $again = $this->actingAs($this->member)->postJson("/api/projects/{$this->project->id}/saved-insights/{$ids['trends']}/run")->assertOk();

    expect($again->json('source'))->toBe('dashboard_cache')->and($again->json('computed_at'))->toBe('2026-03-20T08:00:00+00:00')->and($this->reads->count)->toBe(1);
});

it('never refreshes an insight that needs review', function () {
    ['dashboard' => $dashboard] = chDashboardWithThreeInsights($this);
    DB::table('saved_insights')->where('type', 'funnel')->update(['query' => json_encode(['v' => 0]), 'query_version' => 0]);
    $this->actingAs($this->member)->getJson("/api/projects/{$this->project->id}/dashboards/{$dashboard}")->assertOk()
        ->assertJsonPath('data.tiles.1.saved_insight.state.reason', 'outdated_version');

    Artisan::call('dashboards:refresh');

    expect(Artisan::output())->toContain('queued=2')->toContain('invalid=1')->and($this->reads->count)->toBe(2);
});
