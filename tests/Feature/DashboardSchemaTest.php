<?php

use App\Models\Dashboard;
use App\Models\DashboardTile;
use App\Models\Project;
use App\Models\SavedInsight;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The schema itself: cascades, the composite keys that keep a tile inside one project, the unique tile and the
 * nullOnDelete of created_by. (SQLite enforces foreign keys in the test run; the Postgres suite repeats the
 * constraints on the real engine.)
 */

function tileRow(Dashboard $dashboard, SavedInsight $insight, int $position = 0): DashboardTile
{
    return DashboardTile::create([
        'uuid' => (string) Str::uuid7(),
        'project_id' => $dashboard->project_id,
        'dashboard_id' => $dashboard->id,
        'saved_insight_id' => $insight->id,
        'position' => $position,
    ]);
}

it('deletes the insights, dashboards and tiles of a project with the project', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $dashboard = Dashboard::factory()->create(['project_id' => $project->id]);
    $insight = SavedInsight::factory()->create(['project_id' => $project->id]);
    tileRow($dashboard, $insight);
    $keptDashboard = Dashboard::factory()->create(['project_id' => $other->id]);
    SavedInsight::factory()->create(['project_id' => $other->id]);

    $project->delete();

    expect(SavedInsight::query()->count())->toBe(1)
        ->and(Dashboard::query()->pluck('id')->all())->toBe([$keptDashboard->id])
        ->and(DashboardTile::query()->count())->toBe(0);
});

it('removes the tiles of a deleted insight or dashboard and nothing else', function () {
    $project = Project::factory()->create();
    $dashboard = Dashboard::factory()->create(['project_id' => $project->id]);
    $a = SavedInsight::factory()->create(['project_id' => $project->id]);
    $b = SavedInsight::factory()->create(['project_id' => $project->id]);
    tileRow($dashboard, $a, 0);
    tileRow($dashboard, $b, 1);

    $a->delete();
    expect(DashboardTile::query()->pluck('saved_insight_id')->all())->toBe([$b->id]);

    $dashboard->delete();
    expect(DashboardTile::query()->count())->toBe(0)->and(SavedInsight::query()->count())->toBe(1);
});

it('refuses a tile whose dashboard and insight belong to different projects', function () {
    $dashboard = Dashboard::factory()->create();
    $foreign = SavedInsight::factory()->create();

    expect(fn () => tileRow($dashboard, $foreign))->toThrow(QueryException::class);
    // and a tile that names a project other than its parents'
    $own = SavedInsight::factory()->create(['project_id' => $dashboard->project_id]);
    expect(fn () => DashboardTile::create([
        'uuid' => (string) Str::uuid7(), 'project_id' => $foreign->project_id,
        'dashboard_id' => $dashboard->id, 'saved_insight_id' => $own->id, 'position' => 0,
    ]))->toThrow(QueryException::class);
});

it('allows one tile per insight on a dashboard, and the same insight on another dashboard', function () {
    $project = Project::factory()->create();
    $one = Dashboard::factory()->create(['project_id' => $project->id]);
    $two = Dashboard::factory()->create(['project_id' => $project->id]);
    $insight = SavedInsight::factory()->create(['project_id' => $project->id]);
    tileRow($one, $insight);

    expect(fn () => tileRow($one, $insight, 1))->toThrow(QueryException::class);
    tileRow($two, $insight);
    expect(DashboardTile::query()->count())->toBe(2);
});

it('keeps the objects of a deleted user and clears created_by', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $insight = SavedInsight::factory()->create(['project_id' => $project->id, 'created_by' => $user->id]);
    $dashboard = Dashboard::factory()->create(['project_id' => $project->id, 'created_by' => $user->id]);

    $user->delete();

    expect($insight->fresh()->created_by)->toBeNull()->and($dashboard->fresh()->created_by)->toBeNull();
});

it('gives every object a unique uuid', function () {
    $insight = SavedInsight::factory()->create();

    expect(fn () => SavedInsight::factory()->create(['uuid' => $insight->uuid]))->toThrow(QueryException::class);
    expect(DB::table('dashboards')->count())->toBe(0);
});
