<?php

/*
 * Projects are written through App\Actions\Projects only: it validates, runs in a transaction, writes the audit
 * line and keeps ProjectObserver's cache invalidation (a query-builder write skips the model events, so the
 * resolver would keep accepting a deleted or re-keyed API key). This guard greps app/ and database/ for the
 * ways a project row can be written around the actions. Factories and tests are not scanned: they build fixtures.
 */

/** Patterns that write a project row (or the projects table) directly. */
const PROJECT_WRITE_PATTERNS = [
    'DB::table(projects)' => '/DB::table\(\s*[\'"]projects[\'"]\s*\)/',
    'table(projects) on a connection' => '/->table\(\s*[\'"]projects[\'"]\s*\)/',
    'Project::query()->update(' => '/Project::query\(\)\s*->\s*update\(/',
    'Project::query()->delete(' => '/Project::query\(\)\s*->\s*delete\(/',
    'Project::query()->insert(' => '/Project::query\(\)\s*->\s*(insert|upsert|insertOrIgnore)\w*\(/',
    'Project::where(...)->delete/update(' => '/Project::(where|whereKey|whereIn|withoutGlobalScopes)\w*\([^;]*\)\s*->\s*(delete|update|forceDelete|increment|decrement)\(/',
    'Project::create(' => '/Project::create\(/',
    'Project::forceCreate(' => '/Project::forceCreate\(/',
    'Project::destroy(' => '/Project::destroy\(/',
    'Project::insert(' => '/Project::(insert|upsert|updateOrCreate|firstOrCreate|updateOrInsert)\w*\(/',
    'new Project' => '/new\s+Project\b(?!\w)/',
    '$project->forceDelete(' => '/->forceDelete\(/',
    'raw SQL on projects' => '/(insert\s+into|update|delete\s+from)\s+["`]?projects\b/i',
];

/**
 * @param  list<string>  $allowedDirectories  paths relative to the base path whose files may write projects
 * @return list<string> "file: rule" for every violation
 */
function projectWriteViolations(string $root, array $allowedDirectories): array
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

        // Comments may talk about forbidden calls (this very project does so in docblocks).
        $code = preg_replace('~/\*.*?\*/|(?<![:\'"])//[^\n]*|#[^\n]*~s', '', (string) file_get_contents($file->getPathname()));

        foreach (PROJECT_WRITE_PATTERNS as $rule => $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $violations[] = "{$relative}: {$rule}";
            }
        }
    }

    return $violations;
}

it('writes projects only inside App\Actions\Projects (and factories, which build fixtures)', function () {
    $violations = array_merge(
        projectWriteViolations(app_path(), ['app/Actions/Projects/']),
        // Migrations and seeders must not insert projects behind the actions' back either; the factory is the fixture builder.
        projectWriteViolations(database_path('seeders'), []),
    );

    expect($violations)->toBe([]);
});

it('detects every forbidden way of writing a project in a synthetic file', function (string $code) {
    $dir = sys_get_temp_dir().'/project-write-guard-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/Offender.php', "<?php\n{$code}\n");

    try {
        expect(projectWriteViolations($dir, []))->not->toBe([]);
    } finally {
        unlink($dir.'/Offender.php');
        rmdir($dir);
    }
})->with([
    'DB::table' => ["DB::table('projects')->insert(['name' => 'x']);"],
    'DB::table double quotes' => ['DB::table("projects")->where("id", 1)->delete();'],
    'query update' => ["Project::query()->update(['name' => 'x']);"],
    'query delete' => ['Project::query()->delete();'],
    'where delete' => ['Project::where("id", 1)->delete();'],
    'whereKey update' => ["Project::whereKey(1)->update(['api_key' => 'x']);"],
    'create' => ["Project::create(['name' => 'x']);"],
    'forceCreate' => ["Project::forceCreate(['name' => 'x']);"],
    'new Project' => ['$p = new Project(); $p->save();'],
    'destroy' => ['Project::destroy(1);'],
    'firstOrCreate' => ["Project::firstOrCreate(['name' => 'x']);"],
    'raw sql' => ["DB::statement('DELETE FROM projects');"],
    'forceDelete' => ['$project->forceDelete();'],
]);

it('does not flag the actions directory, comments or unrelated code', function () {
    expect(projectWriteViolations(app_path('Actions/Projects'), []))->not->toBe([], 'sanity: the actions DO write projects');
    expect(projectWriteViolations(app_path('Actions/Projects'), ['app/Actions/Projects/']))->toBe([]);

    $dir = sys_get_temp_dir().'/project-write-guard-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/Clean.php', "<?php\n// Project::create(...) is forbidden here\n/* DB::table('projects') */\n\$x = Project::query()->visibleTo(\$u)->get();\n\$y = User::create([]);\n\$z = new ProjectUser();\n");

    try {
        expect(projectWriteViolations($dir, []))->toBe([]);
    } finally {
        unlink($dir.'/Clean.php');
        rmdir($dir);
    }
});
