<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeViteBuild;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

// Integration needs a real Postgres and runs only under phpunit.pgsql.xml (scripts/test-postgres.sh). The group
// "pgsql-integration" is excluded by the default phpunit.xml, so even `php artisan test tests/Integration` finds nothing to run
// there. DatabaseTruncation, not RefreshDatabase: it migrates once per run and truncates between tests, with no
// wrapping transaction, so a second database session (a racing resolver) sees what a test committed and a test
// sees what the second session committed.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->group('pgsql-integration')
    ->in('Integration');

// Every Feature test renders pages against a throwaway public/ directory with a Vite manifest, so none of them
// depends on `npm run build` having run. `$this->publicPath` is that directory.
pest()->beforeEach(fn () => $this->publicPath = FakeViteBuild::install())
    ->afterEach(fn () => FakeViteBuild::remove($this->publicPath))
    ->in('Feature');

// A test that forgets Http::fake() must never reach a real service (e.g. ClickHouse).
pest()->beforeEach(fn () => Http::preventStrayRequests())->in('Feature', 'Unit');

function jsonRequest(string $body): Request
{
    return Request::create('/e/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
}
