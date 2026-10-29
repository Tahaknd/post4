<?php

use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Infrastructure\ClickHouse\ClickHouseMigrator;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use App\Infrastructure\ClickHouse\ScopedRelations;
use App\Infrastructure\ClickHouse\ScopedViewDriftCheck;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function commandMigrations(string $name, string $sql): string
{
    $dir = sys_get_temp_dir().'/chmig_cmd_'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/'.$name, $sql);

    return $dir;
}

/**
 * What DESCRIBE TABLE <relation> answers when every relation is in order; $overrides replaces the columns of one relation
 * (by the name in the statement).
 *
 * @param  array<string, list<string>>  $overrides
 */
function describeAnswer(string $body, array $overrides = []): mixed
{
    $name = trim(substr($body, strlen('DESCRIBE TABLE ')));
    $columns = $overrides[$name] ?? match ($name) {
        'events', 'project_events_v' => ProjectScopedReader::COLUMNS,
        'person_distinct_ids' => ScopedRelations::personDistinctIds()->tableColumns(),
        'project_person_distinct_ids_v' => ScopedRelations::PERSON_DISTINCT_IDS_COLUMNS,
    };

    return Http::response(json_encode(['data' => array_map(fn ($c) => ['name' => $c], $columns)]));
}

function removeDir(string $dir): void
{
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $file) {
        unlink($dir.'/'.$file);
    }
    rmdir($dir);
}

it('reports applied migrations', function () {
    $dir = commandMigrations('001_a.sql', 'SELECT 1;');
    $this->app->instance(ClickHouseMigrator::class, new ClickHouseMigrator(new ClickHouseClient('http://ch:8123', 'db', 'u', 'p'), $dir));
    $this->app->instance(ScopedViewDriftCheck::class, new ScopedViewDriftCheck(new ClickHouseClient('http://ch:8123', 'db', 'u', 'p')));
    Http::fake(function ($request) {
        // the drift check describes every relation and its view: all complete
        return str_starts_with($request->body(), 'DESCRIBE TABLE')
            ? describeAnswer($request->body())
            : Http::response('{"data":[]}');
    });

    $this->artisan('clickhouse:migrate')
        ->expectsOutputToContain('001_a.sql')
        ->assertExitCode(0);

    removeDir($dir);
});

it('exits non-zero with a clear message when the scoped view lacks a column of events', function () {
    $dir = commandMigrations('001_a.sql', 'SELECT 1;');
    Http::fake(function ($request) {
        if (str_starts_with($request->body(), 'DESCRIBE TABLE')) {
            // the view was not refreshed after events got a new column
            return describeAnswer($request->body(), ['events' => [...ProjectScopedReader::COLUMNS, 'future_column']]);
        }

        return Http::response('{"data":[]}');
    });
    $client = new ClickHouseClient('http://ch:8123', 'db', 'u', 'p');
    $this->app->instance(ClickHouseMigrator::class, new ClickHouseMigrator($client, $dir));
    $this->app->instance(ScopedViewDriftCheck::class, new ScopedViewDriftCheck($client));

    try {
        $this->artisan('clickhouse:migrate')
            ->expectsOutputToContain('events table has columns ProjectScopedReader::COLUMNS does not list: future_column')
            ->expectsOutputToContain('CREATE OR REPLACE VIEW')
            ->assertExitCode(1);
    } finally {
        removeDir($dir);
    }
});

it('fails loudly when a migration fails', function () {
    $dir = commandMigrations('001_a.sql', 'BROKEN;');
    Http::fake(function ($request) {
        return str_contains($request->body(), 'BROKEN') ? Http::response('Code: 62. Syntax error', 400) : Http::response('{"data":[]}');
    });
    $this->app->instance(ClickHouseMigrator::class, new ClickHouseMigrator(new ClickHouseClient('http://ch:8123', 'db', 'u', 'p'), $dir));

    try {
        expect(fn () => Artisan::call('clickhouse:migrate'))->toThrow(RuntimeException::class, 'Syntax error');
    } finally {
        removeDir($dir);
    }
});

describe('ScopedViewDriftCheck', function () {
    /**
     * @param  array<string, list<string>>  $others  columns of the person relation (`person_distinct_ids`, `project_person_distinct_ids_v`)
     */
    function driftCheckWith(array $events, array $view, array $others = []): ScopedViewDriftCheck
    {
        // a fresh factory: stubs of an earlier fake() would win over this one
        Http::swap(new Factory);
        Http::fake(fn ($request) => describeAnswer($request->body(), ['events' => $events, 'project_events_v' => $view] + $others));

        return new ScopedViewDriftCheck(new ClickHouseClient('http://ch:8123', 'db', 'u', 'p'));
    }

    it('finds no problem when events, the view and COLUMNS agree (in any order)', function () {
        expect(driftCheckWith(array_reverse(ProjectScopedReader::COLUMNS), ProjectScopedReader::COLUMNS)->problems())->toBe([]);
    });

    it('reports a column missing from the view, an extra one, and one missing from events', function () {
        $without = array_values(array_diff(ProjectScopedReader::COLUMNS, ['browser']));

        $missingInView = driftCheckWith(ProjectScopedReader::COLUMNS, $without)->problems();
        $extraInView = driftCheckWith(ProjectScopedReader::COLUMNS, [...ProjectScopedReader::COLUMNS, 'x'])->problems();
        $missingInTable = driftCheckWith($without, ProjectScopedReader::COLUMNS)->problems();

        expect($missingInView[0])->toContain('view project_events_v lacks')->toContain('browser')
            ->and(end($missingInView))->toContain('CREATE OR REPLACE VIEW')
            ->and($extraInView[0])->toContain('view project_events_v has columns')->toContain('x')
            ->and($missingInTable[0])->toContain('events table lacks')->toContain('browser');
    });

    it('reports a problem instead of throwing when the columns cannot be read', function () {
        Http::fake(['*' => Http::response('Code: 60. DB::Exception: Table does not exist', 404)]);

        $problems = (new ScopedViewDriftCheck(new ClickHouseClient('http://ch:8123', 'db', 'u', 'p')))->problems();

        expect($problems)->toHaveCount(1)->and($problems[0])->toContain('cannot read the columns');
    });

    it('checks the person relation too: the hidden is_deleted column is expected, anything else is drift', function () {
        $table = ScopedRelations::personDistinctIds()->tableColumns();
        $view = ScopedRelations::PERSON_DISTINCT_IDS_COLUMNS;

        $ok = driftCheckWith(ProjectScopedReader::COLUMNS, ProjectScopedReader::COLUMNS, ['person_distinct_ids' => $table, 'project_person_distinct_ids_v' => $view])->problems();
        $newColumn = driftCheckWith(ProjectScopedReader::COLUMNS, ProjectScopedReader::COLUMNS, ['person_distinct_ids' => [...$table, 'merged_at'], 'project_person_distinct_ids_v' => $view])->problems();
        $viewMissing = driftCheckWith(ProjectScopedReader::COLUMNS, ProjectScopedReader::COLUMNS, ['person_distinct_ids' => $table, 'project_person_distinct_ids_v' => ['project_id', 'distinct_id', 'person_id']])->problems();
        $leaksHidden = driftCheckWith(ProjectScopedReader::COLUMNS, ProjectScopedReader::COLUMNS, ['person_distinct_ids' => $table, 'project_person_distinct_ids_v' => [...$view, 'is_deleted']])->problems();

        expect($ok)->toBe([])
            ->and($newColumn[0])->toContain('person_distinct_ids table has columns ScopedRelations::PERSON_DISTINCT_IDS_COLUMNS does not list: merged_at')
            ->and($viewMissing[0])->toContain('view project_person_distinct_ids_v lacks')->toContain('version, created_at')
            ->and($leaksHidden[0])->toContain('view project_person_distinct_ids_v has columns')->toContain('is_deleted')
            ->and(end($leaksHidden))->toContain('clickhouse:view-sql');
    });
});
