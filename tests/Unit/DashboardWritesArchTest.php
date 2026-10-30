<?php

/*
 * Saved insights, dashboards and tiles are written through App\Actions\SavedInsights and App\Actions\Dashboards only:
 * they validate, run in a transaction under the row locks that make the limits and the tile positions exact, and
 * write the audit line. This guard scans app/ and database/seeders for the ways a row of the three tables can be written
 * around them. Factories and tests build fixtures and are not scanned. (An instance method such as `$dashboard->delete()`
 * cannot be told from any other model by a pattern: the model docblocks and review cover it.)
 */

const DASHBOARD_MODELS = 'SavedInsight|Dashboard|DashboardTile';
const DASHBOARD_TABLES = 'saved_insights|dashboards|dashboard_tiles';

const DASHBOARD_WRITE_RULES = [
    'Model::create and friends' => '/\b('.DASHBOARD_MODELS.')::(create|forceCreate|destroy|insert\w*|upsert|updateOrCreate|firstOrCreate|updateOrInsert|truncate)\w*\(/',
    'Model::query()...->write' => '/\b('.DASHBOARD_MODELS.')::(query|on|where\w*|latest|oldest|limit|withoutGlobalScopes?)\([^;]*->\s*(update|delete|forceDelete|upsert|insert\w*|increment|decrement|create|forceCreate|updateOrCreate|firstOrCreate)\(/s',
    'new Model' => '/new\s+('.DASHBOARD_MODELS.')\b(?!\w)/',
    'DB::table(...)' => '/(DB::table|->table)\(\s*[\'"]('.DASHBOARD_TABLES.')[\'"]\s*\)/',
    'relation write' => '/->(savedInsights|dashboards|tiles|savedInsight)\(\)\s*->\s*(create|createMany|forceCreate|save|saveMany|insert\w*|update|delete|forceDelete|upsert|firstOrCreate|updateOrCreate)\(/',
    'raw write SQL' => '/(insert\s+into|update|delete\s+from|truncate\s+table?)\s+["`]?('.DASHBOARD_TABLES.')\b/i',
    'TRUNCATE' => '/\bTRUNCATE\b/i',
    '->forceDelete()' => '/->forceDelete\(/',
];

function dashboardCodeWithoutComments(string $source): string
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? str_repeat("\n", substr_count($token[1], "\n")) : $token[1];
        } else {
            $code .= $token;
        }
    }

    return $code;
}

/**
 * @param  list<string>  $allowedDirectories  paths relative to the base path whose files may write these tables
 * @return list<string> "file: rule" for every violation
 */
function dashboardWriteViolations(string $root, array $allowedDirectories): array
{
    $violations = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_starts_with($file->getPathname(), base_path().'/') ? substr($file->getPathname(), strlen(base_path()) + 1) : $file->getPathname();
        foreach ($allowedDirectories as $allowed) {
            if (str_starts_with($relative, $allowed)) {
                continue 2;
            }
        }

        $code = dashboardCodeWithoutComments((string) file_get_contents($file->getPathname()));
        foreach (DASHBOARD_WRITE_RULES as $rule => $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $violations[] = "{$relative}: {$rule}";
            }
        }
    }

    return $violations;
}

/** @return list<string> the rules a throwaway file with this code breaks */
function dashboardScanSnippet(string $code): array
{
    $dir = sys_get_temp_dir().'/dashboard-write-guard-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/Snippet.php', "<?php\n{$code}\n");

    try {
        return array_map(fn (string $line) => substr($line, strpos($line, ': ') + 2), dashboardWriteViolations($dir, []));
    } finally {
        unlink($dir.'/Snippet.php');
        rmdir($dir);
    }
}

it('writes saved insights, dashboards and tiles only inside their actions', function () {
    $violations = array_merge(
        dashboardWriteViolations(app_path(), ['app/Actions/SavedInsights/', 'app/Actions/Dashboards/']),
        dashboardWriteViolations(database_path('seeders'), []),
    );

    expect($violations)->toBe([]);
});

it('detects each forbidden way of writing in a synthetic file, naming the rule', function (string $code, string $rule) {
    expect(dashboardScanSnippet($code))->toContain($rule);
})->with([
    'create' => ["SavedInsight::create(['name' => 'x']);", 'Model::create and friends'],
    'updateOrCreate' => ["Dashboard::updateOrCreate(['id' => 1], []);", 'Model::create and friends'],
    'destroy' => ['DashboardTile::destroy(1);', 'Model::create and friends'],
    'query update' => ["Dashboard::query()->whereKey(1)->update(['name' => 'x']);", 'Model::query()...->write'],
    'where delete across lines' => ["DashboardTile::query()\n    ->where('dashboard_id', 1)\n    ->delete();", 'Model::query()...->write'],
    'query create' => ["SavedInsight::query()->create(['name' => 'x']);", 'Model::query()...->write'],
    'new model' => ['$x = new Dashboard; $x->save();', 'new Model'],
    'DB::table' => ["DB::table('dashboard_tiles')->insert([]);", 'DB::table(...)'],
    'connection table' => ["DB::connection('pgsql')->table('saved_insights')->delete();", 'DB::table(...)'],
    'relation create' => ['$project->dashboards()->create([]);', 'relation write'],
    'tiles relation save' => ['$dashboard->tiles()->save($tile);', 'relation write'],
    'raw insert' => ['DB::statement("insert into dashboards (name) values (1)");', 'raw write SQL'],
    'raw delete' => ['DB::statement("DELETE FROM saved_insights");', 'raw write SQL'],
    'truncate' => ['DB::statement("TRUNCATE dashboards");', 'TRUNCATE'],
    'force delete' => ['$dashboard->forceDelete();', '->forceDelete()'],
]);

it('is not fooled by reads, comments or strings that only mention the names', function (string $code) {
    expect(dashboardScanSnippet($code))->toBe([]);
})->with([
    'a read' => ["Dashboard::query()->where('project_id', 1)->orderBy('id')->get();"],
    'a find with a lock' => ['SavedInsight::query()->whereKey(1)->lockForUpdate()->first();'],
    'a comment' => ["// Dashboard::create([]); DB::table('dashboards')->insert([]);"],
    'a doc comment' => ["/** SavedInsight::query()->delete() is what NOT to do */\n\$x = 1;"],
    'a count' => ["DashboardTile::query()->where('dashboard_id', 1)->count();"],
    'a message' => ["\$m = 'update the dashboards page';"],
]);
