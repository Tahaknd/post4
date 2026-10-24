<?php

/*
 * Projects and their memberships are written through App\Actions\Projects only: it validates, runs in a
 * transaction, writes the audit line and keeps ProjectObserver's cache invalidation (a query-builder write skips
 * the model events, so the resolver would keep accepting a deleted or re-keyed API key). This guard scans app/
 * and database/seeders for the ways a project or project_user row can be written around the actions, and for
 * statements that reset the id sequence (a reset makes new projects reuse ids whose ClickHouse events are still
 * there). Factories and tests are not scanned: they build fixtures.
 */

/** Rule name => pattern, matched against the code with comments removed (strings are kept: SQL lives there). */
const PROJECT_WRITE_RULES = [
    'DB::table(projects)' => '/DB::table\(\s*[\'"]projects[\'"]\s*\)/',
    '->table(projects) on a connection' => '/->table\(\s*[\'"]projects[\'"]\s*\)/',
    'Project::query()...->write' => '/Project::(query|on|withoutGlobalScopes?|withTrashed)\(\)?[^;]*->\s*(update|delete|forceDelete|upsert|insert\w*|increment|decrement|truncate)\(/s',
    'Project::where...->write' => '/Project::(where|whereKey|whereIn|whereNot|orWhere|latest|oldest|limit)\w*\([^;]*->\s*(update|delete|forceDelete|upsert|insert\w*|increment|decrement)\(/s',
    'Project::create' => '/Project::create\(/',
    'Project::forceCreate' => '/Project::forceCreate\(/',
    'Project::destroy' => '/Project::destroy\(/',
    'Project::upsert and friends' => '/Project::(insert|upsert|updateOrCreate|firstOrCreate|updateOrInsert|truncate)\w*\(/',
    'new Project' => '/new\s+Project\b(?!\w)/',
    '->forceDelete()' => '/->forceDelete\(/',
    'membership relation write' => '/->(users|projects)\(\)\s*->\s*(attach|sync|syncWithoutDetaching|detach|toggle|updateExistingPivot|save\w*|create\w*)\(/',
    'DB::table(project_user)' => '/DB::table\(\s*[\'"]project_user[\'"]\s*\)/',
    'ProjectUser write' => '/ProjectUser::(create|forceCreate|insert|upsert|destroy|updateOrCreate|firstOrCreate)\w*\(|ProjectUser::(query|where\w*)\([^;]*->\s*(update|delete|forceDelete|upsert|insert\w*)\(|new\s+ProjectUser\b(?!\w)/s',
    'raw write SQL on projects or project_user' => '/(insert\s+into|update|delete\s+from)\s+["`]?(projects|project_user)\b/i',
    'TRUNCATE' => '/\bTRUNCATE\b/i',
    'sequence reset' => '/ALTER\s+SEQUENCE|\bsetval\s*\(|RESTART\s+IDENTITY|\bsqlite_sequence\b/i',
];

/** The source with every comment removed. Tokenised, so a "#" or "//" inside a string ("Project #{$id}", a URL) hides nothing. */
function withoutComments(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }
            $code .= $token[1];
        } else {
            $code .= $token;
        }
    }

    return $code;
}

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

        $code = withoutComments((string) file_get_contents($file->getPathname()));

        foreach (PROJECT_WRITE_RULES as $rule => $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $violations[] = "{$relative}: {$rule}";
            }
        }
    }

    return $violations;
}

/** Writes $code to a throwaway directory and returns what the scanner reports for it. */
function scanSnippet(string $code): array
{
    $dir = sys_get_temp_dir().'/project-write-guard-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/Snippet.php', "<?php\n{$code}\n");

    try {
        return array_map(fn (string $line) => substr($line, strpos($line, ': ') + 2), projectWriteViolations($dir, []));
    } finally {
        unlink($dir.'/Snippet.php');
        rmdir($dir);
    }
}

it('writes projects and memberships only inside App\Actions\Projects (and factories, which build fixtures)', function () {
    $violations = array_merge(
        projectWriteViolations(app_path(), ['app/Actions/Projects/']),
        projectWriteViolations(database_path('seeders'), []),
    );

    expect($violations)->toBe([]);
});

it('detects each forbidden way of writing in a synthetic file, naming the rule', function (string $code, string $rule) {
    expect(scanSnippet($code))->toContain($rule);
})->with([
    'DB::table' => ["DB::table('projects')->insert(['name' => 'x']);", 'DB::table(projects)'],
    'DB::table double quotes' => ['DB::table("projects")->where("id", 1)->delete();', 'DB::table(projects)'],
    'connection table' => ["DB::connection('pgsql')->table('projects')->delete();", '->table(projects) on a connection'],
    'query update' => ["Project::query()->update(['name' => 'x']);", 'Project::query()...->write'],
    'query delete' => ['Project::query()->delete();', 'Project::query()...->write'],
    'query where delete across lines' => ["Project::query()\n    ->where('id', 1)\n    ->delete();", 'Project::query()...->write'],
    'query whereKey forceDelete' => ['Project::query()->whereKey(1)->forceDelete();', 'Project::query()...->write'],
    'query upsert' => ["Project::query()->upsert([['name' => 'x']], ['id']);", 'Project::query()...->write'],
    'withoutGlobalScopes delete' => ['Project::withoutGlobalScopes()->delete();', 'Project::query()...->write'],
    'static where delete' => ['Project::where("id", 1)->delete();', 'Project::where...->write'],
    'static whereKey update' => ["Project::whereKey(1)->update(['api_key' => 'x']);", 'Project::where...->write'],
    'static whereIn delete' => ['Project::whereIn("id", [1, 2])->delete();', 'Project::where...->write'],
    'create' => ["Project::create(['name' => 'x']);", 'Project::create'],
    'forceCreate' => ["Project::forceCreate(['name' => 'x']);", 'Project::forceCreate'],
    'destroy' => ['Project::destroy(1);', 'Project::destroy'],
    'firstOrCreate' => ["Project::firstOrCreate(['name' => 'x']);", 'Project::upsert and friends'],
    'updateOrCreate' => ["Project::updateOrCreate(['id' => 1], []);", 'Project::upsert and friends'],
    'new Project' => ['$p = new Project(); $p->save();', 'new Project'],
    'forceDelete' => ['$project->forceDelete();', '->forceDelete()'],
    'users attach' => ['$project->users()->attach(1);', 'membership relation write'],
    'users sync' => ['$project->users()->sync([1]);', 'membership relation write'],
    'users detach' => ['$project->users()->detach();', 'membership relation write'],
    'users syncWithoutDetaching' => ['$project->users()->syncWithoutDetaching([1]);', 'membership relation write'],
    'projects attach (from the user side)' => ['$user->projects()->attach($project);', 'membership relation write'],
    'project_user table' => ["DB::table('project_user')->insert([]);", 'DB::table(project_user)'],
    'ProjectUser create' => ['ProjectUser::create([]);', 'ProjectUser write'],
    'ProjectUser query delete' => ['ProjectUser::query()->where("id", 1)->delete();', 'ProjectUser write'],
    'new ProjectUser' => ['$m = new ProjectUser();', 'ProjectUser write'],
    'raw delete' => ["DB::statement('DELETE FROM projects');", 'raw write SQL on projects or project_user'],
    'raw insert into project_user' => ["DB::insert('insert into project_user (project_id) values (1)');", 'raw write SQL on projects or project_user'],
    'TRUNCATE' => ["DB::statement('TRUNCATE projects RESTART IDENTITY CASCADE');", 'TRUNCATE'],
    'ALTER SEQUENCE' => ["DB::statement('ALTER SEQUENCE projects_id_seq RESTART WITH 1');", 'sequence reset'],
    'setval' => ["DB::select(\"select setval('projects_id_seq', 1)\");", 'sequence reset'],
    'a write hidden behind a comment-like string before it' => ['$msg = "Project #{$id} // done"; Project::create([]);', 'Project::create'],
]);

it('is not fooled by "#" or "//" inside strings, which used to hide the rest of the line', function () {
    expect(scanSnippet('$a = "Project #1"; Project::create([]);'))->toContain('Project::create')
        ->and(scanSnippet("\$url = 'http://x.test'; Project::destroy(1);"))->toContain('Project::destroy');
});

it('does not flag comments, reads, unrelated models or the user relation being read', function () {
    $clean = <<<'PHP'
        // Project::create(...) is forbidden here, and so is DB::table('projects')
        /* $project->users()->attach(1); TRUNCATE */
        /** Project::query()->delete() */
        # ALTER SEQUENCE
        $x = Project::query()->visibleTo($u)->get();
        $y = User::create([]);
        $z = new ProjectUser_Not_A_Write();
        $n = $project->users()->count();
        $m = $user->projects()->whereKey(1)->exists();
        $o = Project::find(1);
        $p = DB::table('users')->delete();
        PHP;

    expect(scanSnippet($clean))->toBe([]);
});

it('does not flag the actions directory itself, which does write projects', function () {
    expect(projectWriteViolations(app_path('Actions/Projects'), []))->not->toBe([], 'sanity: the actions DO write projects')
        ->and(projectWriteViolations(app_path('Actions/Projects'), ['app/Actions/Projects/']))->toBe([]);
});
