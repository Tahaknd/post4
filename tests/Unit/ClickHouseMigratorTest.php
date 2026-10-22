<?php

use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Infrastructure\ClickHouse\ClickHouseMigrator;
use Illuminate\Support\Facades\Http;

it('splits sql files into statements and drops comments', function () {
    $sql = "-- yorum\nCREATE TABLE a (x UInt8) ENGINE = Memory;\n\nCREATE TABLE b (y UInt8) ENGINE = Memory;\n";

    expect(ClickHouseMigrator::statements($sql))->toBe([
        'CREATE TABLE a (x UInt8) ENGINE = Memory',
        'CREATE TABLE b (y UInt8) ENGINE = Memory',
    ]);
});

it('applies only migrations that are not recorded yet', function () {
    $dir = sys_get_temp_dir().'/chmig_'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/001_old.sql', 'CREATE TABLE old (x UInt8) ENGINE = Memory;');
    file_put_contents($dir.'/002_new.sql', 'CREATE TABLE fresh (x UInt8) ENGINE = Memory;');

    Http::fake(function ($request) {
        return str_contains($request->body(), 'SELECT version FROM schema_migrations')
            ? Http::response('{"data":[{"version":"001_old.sql"}]}')
            : Http::response('');
    });

    $migrator = new ClickHouseMigrator(new ClickHouseClient('http://ch:8123', 'db', 'u', 'p'), $dir);
    $ran = $migrator->migrate();

    expect($ran)->toBe(['002_new.sql']);
    Http::assertSent(fn ($r) => str_contains($r->body(), 'CREATE TABLE fresh'));
    Http::assertNotSent(fn ($r) => str_contains($r->body(), 'CREATE TABLE old'));
    Http::assertSent(fn ($r) => str_contains($r->body(), 'INSERT INTO schema_migrations'));
});

it('does nothing when the migrations directory has no sql files', function () {
    $dir = sys_get_temp_dir().'/chmig_'.uniqid();
    mkdir($dir);
    touch($dir.'/.gitkeep');

    Http::fake(['*' => Http::response('{"data":[]}')]);

    $migrator = new ClickHouseMigrator(new ClickHouseClient('http://ch:8123', 'db', 'u', 'p'), $dir);

    expect($migrator->migrate())->toBe([]);
});
