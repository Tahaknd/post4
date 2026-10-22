<?php

use Symfony\Component\Process\Process;

it('refuses to run when the config is not isolated', function () {
    // Bare phpunit config: no <php> pins, so the app resolves whatever the env says.
    $config = tempnam(sys_get_temp_dir(), 'bare-phpunit-').'.xml';
    $autoload = base_path('vendor/autoload.php');
    $features = base_path('tests/Feature');

    file_put_contents($config, <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit bootstrap="{$autoload}" colors="false">
            <testsuites>
                <testsuite name="Feature">
                    <directory>{$features}</directory>
                </testsuite>
            </testsuites>
        </phpunit>
        XML);

    try {
        // Start from an empty environment: Process merges the parent's env
        // ($_SERVER/$_ENV/getenv) unless a key is set to false, and the parent
        // carries the phpunit.xml pins.
        $inherited = array_keys(array_merge(getenv(), $_SERVER, $_ENV));
        $env = array_fill_keys($inherited, false);
        $env = [
            'PATH' => getenv('PATH'),
            'HOME' => getenv('HOME') ?: '/tmp',
            // Unreachable host/port: safe even if the guard were broken.
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'CACHE_STORE' => 'redis',
            'APP_ENV' => 'production',
        ] + $env;

        $process = new Process(['vendor/bin/pest', '-c', $config], base_path(), $env);
        $process->setTimeout(120);
        $process->run();

        expect($process->getExitCode())->not->toBe(0)
            ->and($process->getOutput().$process->getErrorOutput())->toContain('Refusing to run tests');
    } finally {
        @unlink($config);
    }
});
