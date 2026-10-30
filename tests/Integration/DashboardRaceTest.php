<?php

use App\Domain\Dashboards\SavedQueryResolver;
use App\Domain\Dashboards\SavedQueryState;
use App\Models\Dashboard;
use App\Models\DashboardTile;
use App\Models\Project;
use App\Models\SavedInsight;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Support\DashboardRace;

/*
 * Saved insights, dashboards and tiles on a REAL Postgres, with several writers at once: each a child process with its own
 * application and its own database session (tests/Support/dashboard_race_worker.php), released together from a barrier.
 * The row locks of the actions are what is being tested: the limits are exact, positions stay unique and contiguous, a
 * delete and an add cannot deadlock. A worker that reports anything but success or a validation refusal fails the test
 * (a deadlock shows as SQLSTATE 40P01, a lost race on the unique tile as 23505).
 */

afterEach(fn () => DashboardRace::stop());

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->dashboard = Dashboard::factory()->create(['project_id' => $this->project->id]);
});

function racePositions(Dashboard $dashboard): array
{
    return DashboardTile::query()->where('dashboard_id', $dashboard->id)->orderBy('position')->pluck('position')->all();
}

function raceInsights(Project $project, int $count): array
{
    return SavedInsight::factory()->count($count)->create(['project_id' => $project->id])->all();
}

function raceTile(Dashboard $dashboard, SavedInsight $insight, int $position): DashboardTile
{
    return DashboardTile::create(['uuid' => (string) Str::uuid7(), 'project_id' => $dashboard->project_id, 'dashboard_id' => $dashboard->id, 'saved_insight_id' => $insight->id, 'position' => $position]);
}

it('stores the canonical definition as jsonb and reads it back as the same definition', function () {
    $insight = SavedInsight::factory()->create(['project_id' => $this->project->id]);

    $row = SavedInsight::query()->findOrFail($insight->id);

    expect(DB::scalar('select data_type from information_schema.columns where table_name = ? and column_name = ?', ['saved_insights', 'query']))->toBe('jsonb')
        ->and($row->query)->toEqual($insight->query)
        ->and((new SavedQueryResolver)->resolve($row, now())->status)->toBe(SavedQueryState::OK);
});

it('refuses a tile across projects on the real engine, and cascades from the project', function () {
    $foreign = SavedInsight::factory()->create();

    expect(fn () => raceTile($this->dashboard, $foreign, 0))->toThrow(QueryException::class);

    $own = SavedInsight::factory()->create(['project_id' => $this->project->id]);
    raceTile($this->dashboard, $own, 0);
    $this->project->delete();

    expect(DashboardTile::query()->count())->toBe(0)->and(SavedInsight::query()->where('id', $own->id)->exists())->toBeFalse();
});

it('lets exactly one of several concurrent adds take the last place of a dashboard', function () {
    config(['dashboards.max_tiles_per_dashboard' => 3]);
    $insights = raceInsights($this->project, 6);
    raceTile($this->dashboard, $insights[0], 0);
    raceTile($this->dashboard, $insights[1], 1);

    $ops = [];
    foreach (range(2, 5) as $i) {
        $ops["add{$i}"] = [['op' => 'add', 'dashboard' => $this->dashboard->id, 'insight' => $insights[$i]->id]];
    }
    $results = DashboardRace::run($ops);

    DashboardRace::assertNoErrors($results);
    $all = DashboardRace::all($results);
    expect(count(array_filter($all, fn ($r) => $r['ok'])))->toBe(1)
        ->and(count(array_filter($all, fn ($r) => ! $r['ok'] && $r['fields'] === ['limit'])))->toBe(3)
        ->and(racePositions($this->dashboard))->toBe([0, 1, 2]);
});

it('creates one tile when the same insight is added concurrently, and answers the others with it', function () {
    [$insight] = raceInsights($this->project, 1);

    $ops = [];
    foreach (range(1, 5) as $i) {
        $ops["add{$i}"] = [['op' => 'add', 'dashboard' => $this->dashboard->id, 'insight' => $insight->id]];
    }
    $results = DashboardRace::run($ops);

    DashboardRace::assertNoErrors($results);
    $all = DashboardRace::all($results);
    expect(count(array_filter($all, fn ($r) => $r['ok'] && $r['created'])))->toBe(1)
        ->and(count(array_filter($all, fn ($r) => $r['ok'] && ! $r['created'])))->toBe(4)
        ->and(DashboardTile::query()->count())->toBe(1);
});

it('gives concurrent adds of different insights the positions 0..n-1 with no duplicate', function () {
    config(['dashboards.max_tiles_per_dashboard' => 30]);
    $insights = raceInsights($this->project, 8);

    $ops = [];
    foreach ($insights as $i => $insight) {
        $ops["add{$i}"] = [['op' => 'add', 'dashboard' => $this->dashboard->id, 'insight' => $insight->id]];
    }
    $results = DashboardRace::run($ops);

    DashboardRace::assertNoErrors($results);
    expect(racePositions($this->dashboard))->toBe(range(0, 7));
});

it('lets exactly the free places of the project be taken by concurrent saved insight creations, and likewise dashboards', function () {
    config(['dashboards.max_saved_insights_per_project' => 5, 'dashboards.max_dashboards_per_project' => 4]);
    raceInsights($this->project, 3);   // 2 places left
    // the dashboard of beforeEach is the first of 4: 3 places left

    $ops = [];
    foreach (range(1, 5) as $i) {
        $ops["insight{$i}"] = [['op' => 'create_insight', 'project' => $this->project->id, 'name' => "i{$i}"]];
        $ops["dashboard{$i}"] = [['op' => 'create_dashboard', 'project' => $this->project->id, 'name' => "d{$i}"]];
    }
    $results = DashboardRace::run($ops);

    DashboardRace::assertNoErrors($results);
    $all = DashboardRace::all($results);
    $ok = fn (string $op) => count(array_filter($all, fn ($r) => $r['op'] === $op && $r['ok']));
    expect($ok('create_insight'))->toBe(2)->and($ok('create_dashboard'))->toBe(3)
        ->and(SavedInsight::query()->count())->toBe(5)->and(Dashboard::query()->count())->toBe(4);
});

it('applies concurrent reorders one after the other: positions stay a permutation and the winner is one of the requests', function () {
    config(['dashboards.max_tiles_per_dashboard' => 30]);
    $tiles = [];
    foreach (raceInsights($this->project, 6) as $i => $insight) {
        $tiles[] = raceTile($this->dashboard, $insight, $i);
    }
    $uuids = array_map(fn ($t) => $t->uuid, $tiles);
    $forward = $uuids;
    $backward = array_reverse($uuids);
    $rotated = [...array_slice($uuids, 2), ...array_slice($uuids, 0, 2)];

    $results = DashboardRace::run([
        'forward' => [['op' => 'reorder', 'dashboard' => $this->dashboard->id, 'order' => $forward]],
        'backward' => [['op' => 'reorder', 'dashboard' => $this->dashboard->id, 'order' => $backward]],
        'rotated' => [['op' => 'reorder', 'dashboard' => $this->dashboard->id, 'order' => $rotated]],
    ]);

    DashboardRace::assertNoErrors($results);
    expect(count(array_filter(DashboardRace::all($results), fn ($r) => $r['ok'])))->toBe(3)
        ->and(racePositions($this->dashboard))->toBe(range(0, 5));
    $final = DashboardTile::query()->where('dashboard_id', $this->dashboard->id)->orderBy('position')->pluck('uuid')->all();
    expect([$forward, $backward, $rotated])->toContain($final);
});

it('keeps the tiles contiguous when removes, adds and reorders race', function () {
    config(['dashboards.max_tiles_per_dashboard' => 30]);
    $tiles = [];
    foreach (raceInsights($this->project, 6) as $i => $insight) {
        $tiles[] = raceTile($this->dashboard, $insight, $i);
    }
    $extra = raceInsights($this->project, 3);

    $results = DashboardRace::run([
        'remove' => [['op' => 'remove', 'tile' => $tiles[1]->id], ['op' => 'remove', 'tile' => $tiles[4]->id]],
        'add' => array_map(fn ($i) => ['op' => 'add', 'dashboard' => $this->dashboard->id, 'insight' => $i->id], $extra),
        'reorder' => [['op' => 'reorder', 'dashboard' => $this->dashboard->id, 'order' => array_map(fn ($t) => $t->uuid, array_reverse($tiles))]],
    ]);

    // a reorder that lost the race to a remove or an add is refused as stale (422), never an error
    DashboardRace::assertNoErrors($results);
    expect(DashboardTile::query()->where('dashboard_id', $this->dashboard->id)->count())->toBe(7)
        ->and(racePositions($this->dashboard))->toBe(range(0, 6));
});

it('does not deadlock when an insight is deleted while it is added to dashboards and removed from others', function () {
    config(['dashboards.max_tiles_per_dashboard' => 30]);
    $second = Dashboard::factory()->create(['project_id' => $this->project->id]);
    [$doomed, $kept] = raceInsights($this->project, 2);
    $onFirst = raceTile($this->dashboard, $doomed, 0);
    raceTile($this->dashboard, $kept, 1);
    raceTile($second, $doomed, 0);

    for ($round = 0; $round < 3; $round++) {
        $results = DashboardRace::run([
            'delete' => [['op' => 'delete_insight', 'insight' => $doomed->id]],
            'addFirst' => [['op' => 'add', 'dashboard' => $this->dashboard->id, 'insight' => $doomed->id]],
            'addSecond' => [['op' => 'add', 'dashboard' => $second->id, 'insight' => $doomed->id]],
            'remove' => [['op' => 'remove', 'tile' => $onFirst->id]],
        ]);
        DashboardRace::stop();

        // an add that lost to the delete finds no insight (a 422); a remove of a tile that went with it is "false": neither is an error
        foreach (DashboardRace::all($results) as $one) {
            expect($one)->not->toHaveKey('error');
        }
        expect(SavedInsight::query()->whereKey($doomed->id)->exists())->toBeFalse();
        expect(DashboardTile::query()->where('saved_insight_id', $doomed->id)->count())->toBe(0);

        // recreate for the next round
        $doomed = SavedInsight::factory()->create(['project_id' => $this->project->id]);
        $onFirst = raceTile($this->dashboard, $doomed, (int) DashboardTile::query()->where('dashboard_id', $this->dashboard->id)->count());
        raceTile($second, $doomed, (int) DashboardTile::query()->where('dashboard_id', $second->id)->count());
    }
});
