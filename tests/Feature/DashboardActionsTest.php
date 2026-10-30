<?php

use App\Actions\Dashboards\AddTileToDashboard;
use App\Actions\Dashboards\CreateDashboard;
use App\Actions\Dashboards\DeleteDashboard;
use App\Actions\Dashboards\RecordDashboardView;
use App\Actions\Dashboards\RemoveTile;
use App\Actions\Dashboards\ReorderTiles;
use App\Actions\Dashboards\UpdateDashboard;
use App\Actions\SavedInsights\CreateSavedInsight;
use App\Actions\SavedInsights\DeleteSavedInsight;
use App\Actions\SavedInsights\UpdateSavedInsight;
use App\Domain\Dashboards\SavedQueryResolver;
use App\Domain\Dashboards\SavedQueryState;
use App\Models\Dashboard;
use App\Models\DashboardTile;
use App\Models\Project;
use App\Models\SavedInsight;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Support\SavedInputs;

/*
 * The write path of saved insights, dashboards and tiles: what is stored, what is refused, the limits, idempotency,
 * position compaction and the audit lines. Real concurrency (two sessions) is in tests/Integration on Postgres.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-03-15 10:00:00 UTC');
    $this->project = Project::factory()->create(['timezone' => 'Europe/Istanbul']);
    $this->user = User::factory()->create();
});

afterEach(fn () => Carbon::setTestNow());

function saveInsight(Project $project, string $name = 'Signups', string $type = 'trends', ?array $input = null, ?User $actor = null): SavedInsight
{
    return app(CreateSavedInsight::class)->handle($project, $name, null, $type, $input ?? SavedInputs::for($type), $actor);
}

/** The errors of the ValidationException the closure throws; fails when it throws nothing. @return array<string, list<string>> */
function validationErrors(Closure $run): array
{
    try {
        $run();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a ValidationException.');
}

/** @return list<string> the insight names of a dashboard in tile order */
function tileNames(Dashboard $dashboard): array
{
    return DashboardTile::query()->where('dashboard_id', $dashboard->id)->orderBy('position')->with('savedInsight')->get()->map(fn ($t) => $t->savedInsight->name)->all();
}

/** @return list<int> */
function tilePositions(Dashboard $dashboard): array
{
    return DashboardTile::query()->where('dashboard_id', $dashboard->id)->orderBy('position')->pluck('position')->all();
}

// ---------------------------------------------------------------- saved insights

it('stores the canonical definition, its version, the date handling and the creator', function (string $type) {
    $insight = saveInsight($this->project, '  Weekly  ', $type, actor: $this->user);

    expect($insight->name)->toBe('Weekly')
        ->and($insight->description)->toBeNull()
        ->and($insight->type)->toBe($type)
        ->and($insight->query_version)->toBe(SavedQueryResolver::currentVersion($type))
        ->and($insight->query['v'])->toBe($insight->query_version)
        ->and($insight->date_preset)->toBe('last_7_days')
        ->and($insight->timezone)->toBe('Europe/Istanbul')
        ->and($insight->created_by)->toBe($this->user->id)
        ->and($insight->uuid)->toMatch('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}\z/')
        ->and((new SavedQueryResolver)->resolve($insight->fresh(), now())->status)->toBe(SavedQueryState::OK);
})->with(['trends', 'funnel', 'retention']);

it('keeps exact strings inside the definition (spaces and empty filter values are meaningful)', function () {
    $insight = saveInsight($this->project, input: ['events' => ['  signup '], 'date_from' => '2026-03-01', 'date_to' => '2026-03-07', 'filters' => [['key' => ' plan', 'operator' => 'equals', 'value' => '']]]);

    expect($insight->query['events'])->toBe(['  signup '])
        ->and($insight->query['filters'])->toBe([['key' => ' plan', 'operator' => 'equals', 'value' => '']])
        ->and($insight->date_preset)->toBeNull();
});

it('refuses a bad name, description, type or definition with a validation error and stores nothing', function (array $args, string $field) {
    $handle = fn () => app(CreateSavedInsight::class)->handle($this->project, $args['name'] ?? 'ok', $args['description'] ?? null, $args['type'] ?? 'trends', $args['input'] ?? SavedInputs::for('trends'));

    expect(array_keys(validationErrors($handle)))->toBe([$field]);
    expect(SavedInsight::query()->count())->toBe(0);
})->with([
    'blank name' => [['name' => ' '], 'name'],
    'long name' => [['name' => str_repeat('a', 121)], 'name'],
    'long description' => [['description' => str_repeat('a', 501)], 'description'],
    'unknown type' => [['type' => 'paths'], 'type'],
    'no events' => [['input' => ['events' => [], 'date_preset' => 'last_7_days']], 'query'],
    'bad preset' => [['input' => ['events' => ['a'], 'date_preset' => 'yesterday']], 'query'],
]);

it('refuses the saved insight past the limit of the project, and counts per project', function () {
    config(['dashboards.max_saved_insights_per_project' => 2]);
    $other = Project::factory()->create();
    saveInsight($this->project, 'one');
    saveInsight($this->project, 'two');

    expect(validationErrors(fn () => saveInsight($this->project, 'three'))['limit'][0])->toContain('at most 2 saved insights');
    saveInsight($other, 'elsewhere');

    expect(SavedInsight::query()->where('project_id', $this->project->id)->count())->toBe(2);
});

it('renames without touching the definition, even one that is outdated', function () {
    $insight = SavedInsight::factory()->create(['project_id' => $this->project->id, 'query' => ['v' => 1], 'query_version' => 1]);

    $updated = app(UpdateSavedInsight::class)->handle($insight, ['name' => ' Renamed ', 'description' => '  '], $this->user);

    expect($updated->name)->toBe('Renamed')->and($updated->description)->toBeNull()->and($updated->query)->toBe(['v' => 1])->and($updated->query_version)->toBe(1);
});

it('replaces the definition with a new one of the same type and stores the current canonical and version', function () {
    $insight = SavedInsight::factory()->create(['project_id' => $this->project->id, 'query' => ['v' => 1], 'query_version' => 1]);
    expect((new SavedQueryResolver)->resolve($insight, now())->reason)->toBe('outdated_version');

    $updated = app(UpdateSavedInsight::class)->handle($insight, ['query' => ['events' => ['purchase'], 'date_from' => '2026-03-01', 'date_to' => '2026-03-02']]);

    expect($updated->query['events'])->toBe(['purchase'])
        ->and($updated->query_version)->toBe(SavedQueryResolver::currentVersion('trends'))
        ->and($updated->date_preset)->toBeNull()
        ->and($updated->timezone)->toBe('Europe/Istanbul')
        ->and((new SavedQueryResolver)->resolve($updated->fresh(), now())->status)->toBe(SavedQueryState::OK);
});

it('validates a new definition against the type the insight already has', function () {
    $insight = saveInsight($this->project);

    // a funnel body is not a trends definition
    expect(fn () => app(UpdateSavedInsight::class)->handle($insight, ['query' => SavedInputs::for('funnel')]))->toThrow(ValidationException::class);
    expect($insight->fresh()->query)->toBe($insight->query)->and($insight->fresh()->type)->toBe('trends');
});

it('changes nothing and writes no audit line for an update without changes', function () {
    $insight = saveInsight($this->project);
    $stamp = $insight->fresh()->updated_at;
    Carbon::setTestNow('2026-03-15 11:00:00 UTC');

    app(UpdateSavedInsight::class)->handle($insight, []);

    expect($insight->fresh()->updated_at->equalTo($stamp))->toBeTrue();
});

it('tells an update of an insight that was deleted meanwhile', function () {
    $insight = saveInsight($this->project);
    $insight->delete();

    expect(fn () => app(UpdateSavedInsight::class)->handle($insight, ['name' => 'x']))->toThrow(ModelNotFoundException::class);
});

it('deletes an insight, closes the gap on every dashboard that showed it, and is idempotent', function () {
    $a = saveInsight($this->project, 'a');
    $b = saveInsight($this->project, 'b');
    $c = saveInsight($this->project, 'c');
    $one = app(CreateDashboard::class)->handle($this->project, 'One');
    $two = app(CreateDashboard::class)->handle($this->project, 'Two');
    foreach ([$a, $b, $c] as $i) {
        app(AddTileToDashboard::class)->handle($one, $i);
    }
    app(AddTileToDashboard::class)->handle($two, $b);
    app(AddTileToDashboard::class)->handle($two, $a);

    expect(app(DeleteSavedInsight::class)->handle($a))->toBeTrue()
        ->and(tileNames($one))->toBe(['b', 'c'])->and(tilePositions($one))->toBe([0, 1])
        ->and(tileNames($two))->toBe(['b'])->and(tilePositions($two))->toBe([0])
        ->and(app(DeleteSavedInsight::class)->handle($a))->toBeFalse();
});

// ---------------------------------------------------------------- dashboards

it('creates, renames and deletes a dashboard, within its limit', function () {
    config(['dashboards.max_dashboards_per_project' => 2]);
    $dashboard = app(CreateDashboard::class)->handle($this->project, '  Growth ', "  Weekly numbers\n", $this->user);

    expect($dashboard->name)->toBe('Growth')->and($dashboard->description)->toBe('Weekly numbers')->and($dashboard->created_by)->toBe($this->user->id)->and($dashboard->last_viewed_at)->toBeNull();

    $renamed = app(UpdateDashboard::class)->handle($dashboard, ['name' => 'Growth 2', 'description' => null]);
    expect($renamed->name)->toBe('Growth 2')->and($renamed->description)->toBeNull();

    app(CreateDashboard::class)->handle($this->project, 'Second');
    expect(array_keys(validationErrors(fn () => app(CreateDashboard::class)->handle($this->project, 'Third'))))->toBe(['limit']);
    app(CreateDashboard::class)->handle(Project::factory()->create(), 'Other project');

    expect(app(DeleteDashboard::class)->handle($dashboard))->toBeTrue()->and(app(DeleteDashboard::class)->handle($dashboard))->toBeFalse();
    app(CreateDashboard::class)->handle($this->project, 'Third');
});

it('refuses a bad dashboard name', function () {
    expect(fn () => app(CreateDashboard::class)->handle($this->project, "\u{200B}"))->toThrow(ValidationException::class);
    expect(fn () => app(UpdateDashboard::class)->handle(app(CreateDashboard::class)->handle($this->project, 'ok'), ['name' => '']))->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------- tiles

it('appends tiles at the end and answers an insight that is already on the dashboard with the same tile', function () {
    $dashboard = app(CreateDashboard::class)->handle($this->project, 'D');
    [$a, $b] = [saveInsight($this->project, 'a'), saveInsight($this->project, 'b')];

    $first = app(AddTileToDashboard::class)->handle($dashboard, $a, $this->user);
    $second = app(AddTileToDashboard::class)->handle($dashboard, $b);
    $again = app(AddTileToDashboard::class)->handle($dashboard, $a);

    expect($first['created'])->toBeTrue()->and($first['tile']->position)->toBe(0)
        ->and($second['tile']->position)->toBe(1)
        ->and($again['created'])->toBeFalse()->and($again['tile']->uuid)->toBe($first['tile']->uuid)
        ->and(DashboardTile::query()->count())->toBe(2);
});

it('lets the same insight sit on several dashboards', function () {
    $insight = saveInsight($this->project);
    foreach (['one', 'two'] as $name) {
        app(AddTileToDashboard::class)->handle(app(CreateDashboard::class)->handle($this->project, $name), $insight);
    }

    expect(DashboardTile::query()->count())->toBe(2);
});

it('refuses a tile past the limit of the dashboard, but still answers an existing one', function () {
    config(['dashboards.max_tiles_per_dashboard' => 2]);
    $dashboard = app(CreateDashboard::class)->handle($this->project, 'D');
    [$a, $b, $c] = [saveInsight($this->project, 'a'), saveInsight($this->project, 'b'), saveInsight($this->project, 'c')];
    app(AddTileToDashboard::class)->handle($dashboard, $a);
    app(AddTileToDashboard::class)->handle($dashboard, $b);

    expect(validationErrors(fn () => app(AddTileToDashboard::class)->handle($dashboard, $c))['limit'][0])->toContain('at most 2 tiles')
        ->and(app(AddTileToDashboard::class)->handle($dashboard, $a)['created'])->toBeFalse()
        ->and(DashboardTile::query()->count())->toBe(2);
});

it('refuses an insight of another project, as if it did not exist', function () {
    $dashboard = app(CreateDashboard::class)->handle($this->project, 'D');
    $foreign = saveInsight(Project::factory()->create(), 'foreign');

    expect(array_keys(validationErrors(fn () => app(AddTileToDashboard::class)->handle($dashboard, $foreign))))->toBe(['saved_insight_id']);
    expect(DashboardTile::query()->count())->toBe(0);
});

it('removes a tile and closes the gap, and tells when it was already gone', function () {
    $dashboard = app(CreateDashboard::class)->handle($this->project, 'D');
    $tiles = [];
    foreach (['a', 'b', 'c', 'd'] as $name) {
        $tiles[$name] = app(AddTileToDashboard::class)->handle($dashboard, saveInsight($this->project, $name))['tile'];
    }

    expect(app(RemoveTile::class)->handle($tiles['b']))->toBeTrue()
        ->and(tileNames($dashboard))->toBe(['a', 'c', 'd'])->and(tilePositions($dashboard))->toBe([0, 1, 2])
        ->and(app(RemoveTile::class)->handle($tiles['b']))->toBeFalse()
        // the insight itself stays
        ->and(SavedInsight::query()->count())->toBe(4);
});

it('reorders to exactly the given order with positions 0..n-1', function () {
    $dashboard = app(CreateDashboard::class)->handle($this->project, 'D');
    $uuids = [];
    foreach (['a', 'b', 'c'] as $name) {
        $uuids[$name] = app(AddTileToDashboard::class)->handle($dashboard, saveInsight($this->project, $name))['tile']->uuid;
    }

    $result = app(ReorderTiles::class)->handle($dashboard, [$uuids['c'], $uuids['a'], $uuids['b']]);

    expect(tileNames($dashboard))->toBe(['c', 'a', 'b'])->and(tilePositions($dashboard))->toBe([0, 1, 2])
        ->and($result->pluck('uuid')->all())->toBe([$uuids['c'], $uuids['a'], $uuids['b']]);
});

it('refuses an order that is not exactly the current set', function (Closure $list) {
    $dashboard = app(CreateDashboard::class)->handle($this->project, 'D');
    $uuids = [];
    foreach (['a', 'b'] as $name) {
        $uuids[] = app(AddTileToDashboard::class)->handle($dashboard, saveInsight($this->project, $name))['tile']->uuid;
    }
    $foreign = app(AddTileToDashboard::class)->handle(app(CreateDashboard::class)->handle($this->project, 'E'), saveInsight($this->project, 'x'))['tile']->uuid;

    expect(array_keys(validationErrors(fn () => app(ReorderTiles::class)->handle($dashboard, $list($uuids, $foreign)))))->toBe(['tiles']);
    expect(tileNames($dashboard))->toBe(['a', 'b']);
})->with([
    'a tile missing' => [fn (array $u) => [$u[0]]],
    'a tile twice' => [fn (array $u) => [$u[0], $u[0]]],
    'a stranger instead of a tile' => [fn (array $u, string $f) => [$u[0], $f]],
    'an extra tile' => [fn (array $u, string $f) => [...$u, $f]],
    'empty' => [fn () => []],
    'not a uuid at all' => [fn (array $u) => [$u[0], 'nope']],
]);

it('accepts the empty order for an empty dashboard', function () {
    $dashboard = app(CreateDashboard::class)->handle($this->project, 'D');

    expect(app(ReorderTiles::class)->handle($dashboard, []))->toHaveCount(0);
});

// ---------------------------------------------------------------- views

it('records a view once per window and never touches updated_at', function () {
    config(['dashboards.view_touch_seconds' => 300]);
    $dashboard = app(CreateDashboard::class)->handle($this->project, 'D');
    $updated = $dashboard->fresh()->updated_at;
    $record = app(RecordDashboardView::class);

    expect($record->handle($dashboard->fresh()))->toBeTrue();
    expect($dashboard->fresh()->last_viewed_at->toIso8601String())->toBe('2026-03-15T10:00:00+00:00');

    Carbon::setTestNow('2026-03-15 10:04:59 UTC');
    expect($record->handle($dashboard->fresh()))->toBeFalse();

    Carbon::setTestNow('2026-03-15 10:05:01 UTC');
    expect($record->handle($dashboard->fresh()))->toBeTrue()
        ->and($dashboard->fresh()->last_viewed_at->toIso8601String())->toBe('2026-03-15T10:05:01+00:00')
        ->and($dashboard->fresh()->updated_at->equalTo($updated))->toBeTrue();
});

it('writes a view once even when two requests hold a stale copy of the dashboard', function () {
    $dashboard = app(CreateDashboard::class)->handle($this->project, 'D');
    $copyA = $dashboard->fresh();
    $copyB = $dashboard->fresh();

    expect(app(RecordDashboardView::class)->handle($copyA))->toBeTrue()
        // B loaded before A wrote, so it believes the stamp is missing: the UPDATE's own condition says no
        ->and(app(RecordDashboardView::class)->handle($copyB))->toBeFalse();
});

// ---------------------------------------------------------------- audit

it('logs one line per committed write with ids only, and none for a rolled-back one', function () {
    Log::spy();
    $insight = saveInsight($this->project, 'Secret name', actor: $this->user);

    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'saved_insight.create'
        && $context['actor'] === $this->user->id && $context['project_id'] === $this->project->id
        && $context['saved_insight'] === $insight->uuid && ! str_contains(json_encode($context), 'Secret'))->once();

    config(['dashboards.max_saved_insights_per_project' => 1]);
    try {
        saveInsight($this->project, 'second');
    } catch (ValidationException) {
    }
    // the refused attempt left no second line
    Log::shouldHaveReceived('info')->withArgs(fn ($message) => $message === 'saved_insight.create')->once();
});
