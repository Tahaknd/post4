<?php

use App\Domain\Insights\InsightFailed;
use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Trends\DateRange;
use App\Domain\Insights\Trends\Interval;
use App\Domain\Insights\Trends\Metric;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Domain\Insights\Trends\TrendsSqlBuilder;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use App\Infrastructure\ClickHouse\ClickHouseReads;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use Tests\Support\RedisIntegration;

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
    return new TrendsQuery(['signup'], Metric::TotalEvents, new DateRange(new DateTimeImmutable('2026-03-01 UTC'), new DateTimeImmutable('2026-03-04 UTC')), Interval::Day, 'Europe/Istanbul');
}

function redisClient(array $answers): ClickHouseReads
{
    return new class($answers) implements ClickHouseReads
    {
        public int $calls = 0;

        public function __construct(private array $answers) {}

        public function select(string $sql, array $params = [], array $settings = [], ?int $timeout = null): array
        {
            $this->calls++;
            $next = array_shift($this->answers) ?? [];
            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }
    };
}

it('stores the result under project and definition hash with the configured ttl and serves it again', function () {
    $project = random_int(1_000_000_000, 2_000_000_000);
    $key = InsightRunner::cacheKey($project, redisDef());
    $real = RedisIntegration::DB_PREFIX.$this->it->storePrefix.$key;
    $this->it->track($real);
    $client = redisClient([[['series' => 'signup', 'bucket' => strtotime('2026-03-01 21:00 UTC'), 'value' => 3]]]);
    $runner = new InsightRunner(new ProjectScopedReader($client), new TrendsSqlBuilder, $this->cache);

    $first = $runner->trends($project, redisDef());
    $ttl = $this->it->raw()->ttl($real);
    $second = $runner->trends($project, redisDef());

    expect($this->it->raw()->exists($real))->toBe(1)
        ->and($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(45)
        ->and($client->calls)->toBe(1)
        ->and($second->toArray())->toBe($first->toArray())
        ->and($second->series[0]['data'])->toBe([0, 3, 0, 0])
        ->and($second->labels[1])->toBe('2026-03-02T00:00:00+03:00');
});

it('does not write a key for an error and another worker with its own repository sees the shared entry', function () {
    $project = random_int(1_000_000_000, 2_000_000_000);
    $real = RedisIntegration::DB_PREFIX.$this->it->storePrefix.InsightRunner::cacheKey($project, redisDef());
    $this->it->track($real);
    $failing = redisClient([new ClickHouseException('x', 159, ClickHouseErrorKind::Timeout)]);

    expect(fn () => (new InsightRunner(new ProjectScopedReader($failing), new TrendsSqlBuilder, $this->cache))->trends($project, redisDef()))->toThrow(InsightFailed::class)
        ->and($this->it->raw()->exists($real))->toBe(0);

    $ok = redisClient([[]]);
    (new InsightRunner(new ProjectScopedReader($ok), new TrendsSqlBuilder, $this->cache))->trends($project, redisDef());
    $other = redisClient([]);
    (new InsightRunner(new ProjectScopedReader($other), new TrendsSqlBuilder, $this->it->newRepository()))->trends($project, redisDef());

    expect($other->calls)->toBe(0);
});
