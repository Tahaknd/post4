<?php

use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseGuard;

$pgsql = ['driver' => 'pgsql', 'host' => 'postgres-test', 'database' => 'posthog_test'];

it('accepts the in-memory sqlite database and refuses any other sqlite file', function () {
    expect(TestDatabaseGuard::problem('sqlite', ['database' => ':memory:'], null))->toBeNull()
        ->and(TestDatabaseGuard::problem('sqlite', ['database' => '/app/database/database.sqlite'], null))->toContain('sqlite')
        ->and(TestDatabaseGuard::problem('sqlite', [], null))->not->toBeNull();
});

it('accepts the throwaway postgres only when the flag, the host and the database name all match', function () use ($pgsql) {
    expect(TestDatabaseGuard::problem('pgsql', $pgsql, '1'))->toBeNull()
        ->and(TestDatabaseGuard::problem('pgsql', ['database' => 'app_test'] + $pgsql, '1'))->toBeNull();
});

it('refuses the postgres when any one of the conditions is missing, and says which', function (array $override, mixed $flag, string $expected) use ($pgsql) {
    expect(TestDatabaseGuard::problem('pgsql', $override + $pgsql, $flag))->toContain($expected);
})->with([
    'flag unset' => [[], null, 'TESTS_ALLOW_PGSQL is not 1'],
    'flag empty' => [[], '', 'TESTS_ALLOW_PGSQL is not 1'],
    'flag true' => [[], 'true', 'TESTS_ALLOW_PGSQL is not 1'],
    'flag 2' => [[], '2', 'TESTS_ALLOW_PGSQL is not 1'],
    'live host' => [['host' => 'postgres'], '1', 'host is not exactly postgres-test'],
    'ip host' => [['host' => '127.0.0.1'], '1', 'host is not exactly postgres-test'],
    'prefix host' => [['host' => 'postgres-test.example.com'], '1', 'host is not exactly postgres-test'],
    'upper case host' => [['host' => 'Postgres-Test'], '1', 'host is not exactly postgres-test'],
    'no host' => [['host' => null], '1', 'host is not exactly postgres-test'],
    'live database' => [['database' => 'posthog'], '1', 'does not end in _test'],
    'suffix in the middle' => [['database' => 'posthog_test_backup'], '1', 'does not end in _test'],
    'only the suffix' => [['database' => '_test'], '1', 'does not end in _test'],
    'no database' => [['database' => null], '1', 'does not end in _test'],
    'url redirect' => [['url' => 'postgres://u:p@prod.example.com/posthog_test'], '1', 'sets url'],
    'read hosts' => [['read' => ['host' => ['prod']]], '1', 'sets read'],
    'write hosts' => [['write' => ['host' => ['prod']]], '1', 'sets write'],
]);

it('refuses every other driver', function (string $driver) {
    expect(TestDatabaseGuard::problem($driver, ['database' => 'x_test', 'host' => 'postgres-test'], '1'))->toContain("database={$driver}/");
})->with(['mysql', 'mariadb', 'sqlsrv', 'oracle', '']);

it('lists all the problems of a connection at once', function () {
    $problem = TestDatabaseGuard::problem('pgsql', ['host' => 'postgres', 'database' => 'posthog'], null);

    expect($problem)->toContain('TESTS_ALLOW_PGSQL')->toContain('host')->toContain('_test');
});

it('makes the whole test run refuse a postgres connection that names the live host, even with the flag set', function () {
    $config = tempnam(sys_get_temp_dir(), 'pgsql-phpunit-').'.xml';
    $autoload = base_path('vendor/autoload.php');
    $health = base_path('tests/Feature/HealthTest.php');

    // Everything the guard checks is right EXCEPT the host: this is the "right flag, wrong server" mistake.
    file_put_contents($config, <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit bootstrap="{$autoload}" colors="false">
            <testsuites><testsuite name="Feature"><file>{$health}</file></testsuite></testsuites>
            <php>
                <env name="APP_ENV" value="testing" force="true"/>
                <env name="DB_CONNECTION" value="pgsql" force="true"/>
                <env name="DB_HOST" value="postgres" force="true"/>
                <env name="DB_PORT" value="1" force="true"/>
                <env name="DB_DATABASE" value="posthog_test" force="true"/>
                <env name="DB_URL" value="" force="true"/>
                <env name="TESTS_ALLOW_PGSQL" value="1" force="true"/>
                <env name="CACHE_STORE" value="array" force="true"/>
                <env name="QUEUE_CONNECTION" value="sync" force="true"/>
                <env name="SESSION_DRIVER" value="array" force="true"/>
                <env name="APP_KEY" value="base64:Ce5G4GmiUVXannm654GanbHgZ1SueerpQTjdyJxXfoI=" force="true"/>
            </php>
        </phpunit>
        XML);

    try {
        $inherited = array_keys(array_merge(getenv(), $_SERVER, $_ENV));
        $env = ['PATH' => getenv('PATH'), 'HOME' => getenv('HOME') ?: '/tmp'] + array_fill_keys($inherited, false);

        $process = new Process(['vendor/bin/pest', '-c', $config], base_path(), $env);
        $process->setTimeout(120);
        $process->run();

        expect($process->getExitCode())->not->toBe(0)
            ->and($process->getOutput().$process->getErrorOutput())->toContain('Refusing to run tests')->toContain('host is not exactly postgres-test');
    } finally {
        @unlink($config);
    }
});
