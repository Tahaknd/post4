<?php

use App\Domain\Insights\InsightFailed;
use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use Tests\Support\RedisIntegration;
use Tests\Support\ScriptedReads;
use Tests\Support\TrendsFixtures as T;

/*
 * The insight cache against a real Redis (database 15, random key prefix; see Tests\Support\RedisIntegration):
 * ttl, the real key name with both prefixes, and that the stored value survives phpredis serialization.
 * Opt-in like the limiter tests:
 *
 *   docker compose exec -T -e REDIS_INTEGRATION_HOST=redis app php artisan test --group=redis
 */

pest()->group('redis');

beforeEach(function () {
    config(['insights.cache_ttl_seconds' => 45, 'insights.cache_warning_interval_seconds' => 60]);
    InsightRunner::resetWarningThrottle();
    $this->it = RedisIntegration::connect();
    $this->cache = $this->it->newRepository();
});

afterEach(function () {
    if (isset($this->it)) {
        $this->it->cleanup();
    }
    InsightRunner::resetWarningThrottle();
});

function redisDef(): TrendsQuery
{
    return T::query(['from' => '2026-03-01', 'to' => '2026-03-04', 'timezone' => 'Europe/Istanbul']);
}

it('stores the result under project and definition hash with the configured ttl and serves it again', function () {
    $project = random_int(1_000_000_000, 2_000_000_000);
    $key = InsightRunner::cacheKey($project, redisDef());
    $real = RedisIntegration::DB_PREFIX.$this->it->storePrefix.$key;
    $this->it->track($real);
    $client = new ScriptedReads([[['series' => 'signup', 'bucket' => strtotime('2026-03-01 21:00 UTC'), 'value' => 3]]]);
    $runner = new InsightRunner(new ProjectScopedReader($client), $this->cache);

    $first = $runner->trends($project, redisDef());
    $ttl = $this->it->raw()->ttl($real);
    $second = $runner->trends($project, redisDef());

    expect($this->it->raw()->exists($real))->toBe(1)
        ->and($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(45)
        ->and(count($client->calls))->toBe(1)
        ->and($second->toArray())->toBe($first->toArray())
        ->and($second->series[0]['data'])->toBe([0, 3, 0, 0])
        ->and($second->labels[1])->toBe('2026-03-02T00:00:00+03:00');
});

it('does not write a key for an error and another worker with its own repository sees the shared entry', function () {
    $project = random_int(1_000_000_000, 2_000_000_000);
    $real = RedisIntegration::DB_PREFIX.$this->it->storePrefix.InsightRunner::cacheKey($project, redisDef());
    $this->it->track($real);
    $failing = new ScriptedReads([new ClickHouseException('x', 159, ClickHouseErrorKind::Timeout)]);

    expect(fn () => (new InsightRunner(new ProjectScopedReader($failing), $this->cache))->trends($project, redisDef()))->toThrow(InsightFailed::class)
        ->and($this->it->raw()->exists($real))->toBe(0);

    $ok = new ScriptedReads([[]]);
    (new InsightRunner(new ProjectScopedReader($ok), $this->cache))->trends($project, redisDef());
    $other = new ScriptedReads([]);
    (new InsightRunner(new ProjectScopedReader($other), $this->it->newRepository()))->trends($project, redisDef());

    expect($other->calls)->toBe([]);
});
