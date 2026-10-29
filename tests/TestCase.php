<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Abort before RefreshDatabase runs (setUpTraits() comes after the
     * application is created) if the resolved config is not fully isolated.
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $this->assertIsolatedTestEnvironment();
    }

    private function assertIsolatedTestEnvironment(): void
    {
        $config = $this->app['config'];
        $default = (string) $config->get('database.default');
        $connection = (array) $config->get("database.connections.{$default}");
        $driver = (string) ($connection['driver'] ?? $default);

        $problems = [];

        if ($this->app->configurationIsCached()) {
            $problems[] = 'config is cached (run php artisan config:clear)';
        }

        // SQLite in memory, or the throwaway Postgres of phpunit.pgsql.xml; see TestDatabaseGuard for the exact rule.
        $databaseProblem = TestDatabaseGuard::problem($driver, $connection, env('TESTS_ALLOW_PGSQL'));

        if ($databaseProblem !== null) {
            $problems[] = $databaseProblem;
        }

        if ($config->get('cache.default') !== 'array') {
            $problems[] = 'cache.default is not array';
        }

        if ($config->get('queue.default') !== 'sync') {
            $problems[] = 'queue.default is not sync';
        }

        if ($config->get('session.driver') !== 'array') {
            $problems[] = 'session.driver is not array';
        }

        if ($problems !== []) {
            throw new \RuntimeException('Refusing to run tests: '.implode('; ', $problems));
        }
    }
}
