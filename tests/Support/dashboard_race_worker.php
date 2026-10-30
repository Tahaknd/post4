<?php

/*
 * Child process of the dashboard race tests (tests/Integration/DashboardRaceTest.php). Not a test itself.
 *
 *   php dashboard_race_worker.php <label> <barrierFile> <jsonOps>
 *
 * Boots the whole application against the throwaway Postgres the parent passes in (refusing anything else, by the same rule
 * as TestCase), prints "READY", waits for <barrierFile> (so racing workers are released together), applies the operations in
 * order through the REAL actions, and prints ONE JSON line: {"label", "results": [{op, ok, error?, ...}]}.
 *
 * <jsonOps> is a list of {"op": "add"|"remove"|"reorder"|"delete_insight"|"create_insight"|"create_dashboard", ...ids}.
 * A validation refusal (limit, exact set) is a result with ok=false and the field names; anything else is "error".
 */

use App\Actions\Dashboards\AddTileToDashboard;
use App\Actions\Dashboards\CreateDashboard;
use App\Actions\Dashboards\RemoveTile;
use App\Actions\Dashboards\ReorderTiles;
use App\Actions\SavedInsights\CreateSavedInsight;
use App\Actions\SavedInsights\DeleteSavedInsight;
use App\Models\Dashboard;
use App\Models\DashboardTile;
use App\Models\Project;
use App\Models\SavedInsight;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestDatabaseGuard;

require __DIR__.'/../../vendor/autoload.php';

[, $label, $barrier, $json] = $argv;

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$problem = TestDatabaseGuard::problem('pgsql', (array) config('database.connections.pgsql'), getenv('TESTS_ALLOW_PGSQL'));

if (config('database.default') !== 'pgsql' || $problem !== null) {
    echo json_encode(['label' => $label, 'error' => 'refused: '.($problem ?? 'default connection is not pgsql')]), PHP_EOL;
    exit(1);
}

echo "READY\n";
fflush(STDOUT);

$deadline = microtime(true) + 60;
while (! file_exists($barrier) && microtime(true) < $deadline) {
    usleep(300);
}

$results = [];
foreach (json_decode($json, true, flags: JSON_THROW_ON_ERROR) as $spec) {
    try {
        $result = match ($spec['op']) {
            'add' => (function () use ($app, $spec) {
                $added = $app->make(AddTileToDashboard::class)->handle(Dashboard::query()->findOrFail($spec['dashboard']), SavedInsight::query()->findOrFail($spec['insight']));

                return ['created' => $added['created'], 'position' => $added['tile']->position];
            })(),
            'remove' => ['removed' => $app->make(RemoveTile::class)->handle(DashboardTile::query()->findOrFail($spec['tile']))],
            'reorder' => (function () use ($app, $spec) {
                $tiles = $app->make(ReorderTiles::class)->handle(Dashboard::query()->findOrFail($spec['dashboard']), $spec['order']);

                return ['order' => $tiles->pluck('uuid')->all()];
            })(),
            'delete_insight' => ['deleted' => $app->make(DeleteSavedInsight::class)->handle(SavedInsight::query()->findOrFail($spec['insight']))],
            'create_insight' => ['uuid' => $app->make(CreateSavedInsight::class)->handle(Project::query()->findOrFail($spec['project']), $spec['name'], null, 'trends', ['events' => ['signup'], 'date_preset' => 'last_7_days'])->uuid],
            'create_dashboard' => ['uuid' => $app->make(CreateDashboard::class)->handle(Project::query()->findOrFail($spec['project']), $spec['name'])->uuid],
        };
        $results[] = ['op' => $spec['op'], 'ok' => true, ...$result];
    } catch (ValidationException $e) {
        $results[] = ['op' => $spec['op'], 'ok' => false, 'fields' => array_keys($e->errors())];
    } catch (Throwable $e) {
        $results[] = [
            'op' => $spec['op'], 'ok' => false,
            'error' => $e::class.': '.$e->getMessage(),
            'sqlstate' => $e instanceof QueryException ? $e->errorInfo[0] ?? null : null,
        ];
    }
}

echo json_encode(['label' => $label, 'results' => $results]), PHP_EOL;
