<?php

use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Infrastructure\ClickHouse\ClickHouseMigrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function commandMigrations(string $name, string $sql): string
{
    $dir = sys_get_temp_dir().'/chmig_cmd_'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/'.$name, $sql);

    return $dir;
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
    Http::fake(['*' => Http::response('{"data":[]}')]);
    $this->app->instance(ClickHouseMigrator::class, new ClickHouseMigrator(new ClickHouseClient('http://ch:8123', 'db', 'u', 'p'), $dir));

    $this->artisan('clickhouse:migrate')
        ->expectsOutputToContain('001_a.sql')
        ->assertExitCode(0);

    removeDir($dir);
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
