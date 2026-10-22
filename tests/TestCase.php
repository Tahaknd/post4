<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
        $default = $config->get('database.default');
        $database = $config->get("database.connections.{$default}.database");

        $problems = [];

        if ($this->app->configurationIsCached()) {
            $problems[] = 'config is cached (run php artisan config:clear)';
        }

        if ($default !== 'sqlite' || $database !== ':memory:') {
            $problems[] = "database={$default}/{$database}";
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
