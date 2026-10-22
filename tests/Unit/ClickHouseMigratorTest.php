<?php

use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Infrastructure\ClickHouse\ClickHouseMigrator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

afterEach(function () {
    foreach (migrationDirs() as $dir) {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $file) {
            unlink($dir.'/'.$file);
        }
        rmdir($dir);
    }
    migrationDirs(reset: true);
});

/**
 * Temp directories created by the current test, removed again in afterEach.
 *
 * @return list<string>
 */
function migrationDirs(?string $add = null, bool $reset = false): array
{
    static $dirs = [];

    if ($reset) {
        $dirs = [];
    }
    if ($add !== null) {
        $dirs[] = $add;
    }

    return $dirs;
}

/**
 * @param  array<string, string>  $files  filename => contents (written in the given order)
 */
function migrationDir(array $files = []): string
{
    $dir = sys_get_temp_dir().'/chmig_'.uniqid();
    mkdir($dir);
    migrationDirs(add: $dir);

    foreach ($files as $name => $contents) {
        file_put_contents($dir.'/'.$name, $contents);
    }

    return $dir;
}

function migratorFor(string $dir, int $lockWait = 120): ClickHouseMigrator
{
    return new ClickHouseMigrator(new ClickHouseClient('http://ch:8123', 'db', 'u', 'p'), $dir, $lockWait);
}

/** @param  list<array{version: string, checksum: string}>  $applied */
function fakeApplied(array $applied): void
{
    Http::fake(function ($request) use ($applied) {
        return str_contains($request->body(), 'SELECT version, checksum FROM schema_migrations')
            ? Http::response(json_encode(['data' => $applied]))
            : Http::response('');
    });
}

/** @return list<string> Bodies of all recorded requests, in order. */
function sentBodies(): array
{
    return array_map(fn (array $pair) => $pair[0]->body(), Http::recorded()->all());
}

it('splits sql files into statements and drops comments', function () {
    $sql = "-- yorum\nCREATE TABLE a (x UInt8) ENGINE = Memory;\n\nCREATE TABLE b (y UInt8) ENGINE = Memory;\n";

    expect(ClickHouseMigrator::statements($sql))->toBe([
        'CREATE TABLE a (x UInt8) ENGINE = Memory',
        'CREATE TABLE b (y UInt8) ENGINE = Memory',
    ]);
});

it('does not split on a semicolon followed by a newline inside a string literal', function () {
    $sql = "INSERT INTO t VALUES ('a;\nb');\nSELECT 2;";

    expect(ClickHouseMigrator::statements($sql))->toBe([
        "INSERT INTO t VALUES ('a;\nb')",
        'SELECT 2',
    ]);
});

it('understands doubled and backslash quote escapes', function () {
    expect(ClickHouseMigrator::statements("SELECT 'it''s; fine';\nSELECT 2"))
        ->toBe(["SELECT 'it''s; fine'", 'SELECT 2'])
        ->and(ClickHouseMigrator::statements("SELECT 'it\\'s; fine';\nSELECT 2"))
        ->toBe(["SELECT 'it\\'s; fine'", 'SELECT 2'])
        ->and(ClickHouseMigrator::statements("SELECT 'back\\\\'; SELECT 2"))
        ->toBe(["SELECT 'back\\\\'", 'SELECT 2']);
});

it('keeps double dashes that appear inside a string literal', function () {
    expect(ClickHouseMigrator::statements("SELECT '-- not a comment';\nSELECT 2"))
        ->toBe(["SELECT '-- not a comment'", 'SELECT 2']);
});

it('ignores semicolons inside block and line comments and drops the comments', function () {
    $sql = "SELECT 1 /* a; b;\n c */;\nSELECT 2 -- tail; here\n;\nSELECT 3";

    expect(ClickHouseMigrator::statements($sql))->toBe(['SELECT 1', 'SELECT 2', 'SELECT 3']);
});

it('produces no empty statement for a trailing comment', function () {
    expect(ClickHouseMigrator::statements("SELECT 1;\n-- the end\n"))->toBe(['SELECT 1'])
        ->and(ClickHouseMigrator::statements("SELECT 1;\n/* the end */"))->toBe(['SELECT 1'])
        ->and(ClickHouseMigrator::statements("-- only a comment\n"))->toBe([]);
});

it('splits two statements on one line', function () {
    expect(ClickHouseMigrator::statements('SELECT 1; SELECT 2;'))->toBe(['SELECT 1', 'SELECT 2']);
});

it('handles CRLF line endings', function () {
    $sql = "-- c\r\nSELECT 1;\r\n\r\nSELECT 'a;\r\nb';\r\n";

    expect(ClickHouseMigrator::statements($sql))->toBe(['SELECT 1', "SELECT 'a;\r\nb'"]);
});

it('keeps the last statement when the final semicolon is missing', function () {
    expect(ClickHouseMigrator::statements("SELECT 1;\nSELECT 2\n"))->toBe(['SELECT 1', 'SELECT 2']);
});

it('keeps semicolons inside backtick and double-quoted identifiers', function () {
    expect(ClickHouseMigrator::statements('CREATE TABLE `a;b` (x UInt8) ENGINE = Memory; SELECT "c;d" FROM `a;b`'))
        ->toBe(['CREATE TABLE `a;b` (x UInt8) ENGINE = Memory', 'SELECT "c;d" FROM `a;b`']);
});

it('applies only migrations that are not recorded yet', function () {
    $old = 'CREATE TABLE old (x UInt8) ENGINE = Memory;';
    $dir = migrationDir([
        '001_old.sql' => $old,
        '002_new.sql' => 'CREATE TABLE fresh (x UInt8) ENGINE = Memory;',
    ]);
    fakeApplied([['version' => '001_old.sql', 'checksum' => hash('sha256', $old)]]);

    $ran = migratorFor($dir)->migrate();

    expect($ran)->toBe(['002_new.sql']);
    Http::assertSent(fn ($r) => str_contains($r->body(), 'CREATE TABLE fresh'));
    Http::assertNotSent(fn ($r) => str_contains($r->body(), 'CREATE TABLE old'));
    Http::assertSent(fn ($r) => str_contains($r->body(), 'INSERT INTO schema_migrations'));
});

it('does nothing when the migrations directory has no sql files', function () {
    $dir = migrationDir();
    touch($dir.'/.gitkeep');

    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(migratorFor($dir)->migrate())->toBe([]);
});

it('bootstraps the tracking table and adds the checksum column to an existing one', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    migratorFor(migrationDir())->migrate();

    $bodies = sentBodies();
    expect($bodies[0])->toContain('CREATE TABLE IF NOT EXISTS schema_migrations')
        ->toContain('checksum String')
        ->and($bodies[1])->toBe('ALTER TABLE schema_migrations ADD COLUMN IF NOT EXISTS checksum String DEFAULT \'\'');
});

it('records the sha256 checksum of the raw file with the version', function () {
    $sql = "CREATE TABLE a (x UInt8) ENGINE = Memory;\r\n-- comment\n";
    $dir = migrationDir(['001_a.sql' => $sql]);
    fakeApplied([]);

    migratorFor($dir)->migrate();

    Http::assertSent(function (Request $r) use ($sql) {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

        return $r->body() === 'INSERT INTO schema_migrations (version, checksum) SELECT {v:String}, {c:String}'
            && $query['param_v'] === '001_a.sql'
            && $query['param_c'] === hash('sha256', $sql);
    });
});

it('skips an applied file whose checksum matches', function () {
    $sql = 'CREATE TABLE a (x UInt8) ENGINE = Memory;';
    $dir = migrationDir(['001_a.sql' => $sql]);
    fakeApplied([['version' => '001_a.sql', 'checksum' => hash('sha256', $sql)]]);

    expect(migratorFor($dir)->migrate())->toBe([]);
    Http::assertNotSent(fn ($r) => str_contains($r->body(), 'CREATE TABLE a'));
});

it('throws naming the file when an applied migration was edited', function () {
    $dir = migrationDir(['001_a.sql' => 'CREATE TABLE a (x UInt8, y UInt8) ENGINE = Memory;']);
    fakeApplied([['version' => '001_a.sql', 'checksum' => hash('sha256', 'CREATE TABLE a (x UInt8) ENGINE = Memory;')]]);

    migratorFor($dir)->migrate();
})->throws(RuntimeException::class, '001_a.sql');

it('applies nothing when an applied migration drifted', function () {
    $dir = migrationDir([
        '001_a.sql' => 'EDITED;',
        '002_b.sql' => 'CREATE TABLE b (x UInt8) ENGINE = Memory;',
    ]);
    fakeApplied([['version' => '001_a.sql', 'checksum' => hash('sha256', 'ORIGINAL;')]]);

    try {
        migratorFor($dir)->migrate();
        $this->fail('expected an exception');
    } catch (RuntimeException) {
        Http::assertNotSent(fn ($r) => str_contains($r->body(), 'CREATE TABLE b'));
        Http::assertNotSent(fn ($r) => str_contains($r->body(), 'INSERT INTO schema_migrations'));
    }
});

it('accepts a legacy row with an empty checksum', function () {
    $dir = migrationDir(['001_a.sql' => 'CREATE TABLE a (x UInt8) ENGINE = Memory;']);
    fakeApplied([['version' => '001_a.sql', 'checksum' => '']]);

    expect(migratorFor($dir)->migrate())->toBe([]);
    Http::assertNotSent(fn ($r) => str_contains($r->body(), 'CREATE TABLE a'));
});

it('applies files in sorted filename order even when written out of order', function () {
    $dir = migrationDir([
        '010_c.sql' => 'CREATE TABLE c (x UInt8) ENGINE = Memory;',
        '002_b.sql' => 'CREATE TABLE b (x UInt8) ENGINE = Memory;',
        '001_a.sql' => 'CREATE TABLE a (x UInt8) ENGINE = Memory;',
    ]);
    fakeApplied([]);

    $ran = migratorFor($dir)->migrate();

    expect($ran)->toBe(['001_a.sql', '002_b.sql', '010_c.sql']);

    $creates = array_values(array_filter(sentBodies(), fn (string $b) => str_starts_with($b, 'CREATE TABLE ') && ! str_contains($b, 'schema_migrations')));
    expect($creates)->toBe([
        'CREATE TABLE a (x UInt8) ENGINE = Memory',
        'CREATE TABLE b (x UInt8) ENGINE = Memory',
        'CREATE TABLE c (x UInt8) ENGINE = Memory',
    ]);
});

it('propagates a failing statement and does not record the version', function () {
    $dir = migrationDir(['001_a.sql' => 'CREATE TABLE a (x UInt8) ENGINE = Memory; BROKEN SQL;']);
    Http::fake(function ($request) {
        return match (true) {
            str_contains($request->body(), 'SELECT version, checksum') => Http::response('{"data":[]}'),
            str_contains($request->body(), 'BROKEN SQL') => Http::response('Code: 62. Syntax error', 400),
            default => Http::response(''),
        };
    });

    expect(fn () => migratorFor($dir)->migrate())->toThrow(RuntimeException::class, 'Syntax error');
    Http::assertNotSent(fn ($r) => str_contains($r->body(), 'INSERT INTO schema_migrations'));
});

it('waits on the migrate lock and times out when another runner holds it', function () {
    $dir = migrationDir(['001_a.sql' => 'SELECT 1;']);
    Http::fake(['*' => Http::response('{"data":[]}')]);

    $held = Cache::lock('clickhouse:migrate', 300);
    expect($held->get())->toBeTrue();

    expect(fn () => migratorFor($dir, 0)->migrate())->toThrow(LockTimeoutException::class);
    Http::assertNothingSent();

    $held->release();
    expect(migratorFor($dir, 0)->migrate())->toBe(['001_a.sql']);
});

it('releases the lock after a failed run', function () {
    $dir = migrationDir(['001_a.sql' => 'BROKEN;']);
    Http::fake(function ($request) {
        return str_contains($request->body(), 'BROKEN') ? Http::response('boom', 500) : Http::response('{"data":[]}');
    });

    expect(fn () => migratorFor($dir)->migrate())->toThrow(RuntimeException::class);

    $lock = Cache::lock('clickhouse:migrate', 300);
    expect($lock->get())->toBeTrue();
    $lock->release();
});
