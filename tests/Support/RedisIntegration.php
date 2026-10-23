<?php

namespace Tests\Support;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Assert;

/**
 * Helpers of the opt-in Redis tests (group "redis", see ProjectRateLimiterRedisTest).
 *
 * The phpunit environment pins REDIS_HOST to a dead address on purpose, so these helpers never go through
 * the app's redis config: they open their OWN connections to database 15 (never 0 = queues, 1 = app
 * cache), under a random cache prefix, and delete only the keys they registered.
 *
 * Production has TWO prefixes: the phpredis OPT_PREFIX (database.redis.options.prefix) and the cache
 * store prefix. The prefixed connection here has both, so a double prefix would show in the real key names,
 * which the unprefixed `raw()` connection reads back.
 */
final class RedisIntegration
{
    public const DATABASE = 15;

    /** 12:00:10 UTC: 50 s before the window ends. */
    public const TS = 1_792_670_410;

    /** Stands in for the phpredis OPT_PREFIX. */
    public const DB_PREFIX = 'it-db-';

    /** @var list<string> real key names (every prefix included) to delete afterwards */
    private array $keys = [];

    public readonly string $storePrefix;

    private function __construct(private readonly RedisManager $manager, private readonly Connection $raw)
    {
        $this->storePrefix = 'ratelimit-it-'.bin2hex(random_bytes(6)).':';
    }

    /** Fails (never skips) when REDIS_INTEGRATION_HOST is missing, and hard-stops unless both connections are database 15. */
    public static function connect(?Container $app = null): self
    {
        $app ??= app();
        $host = self::host();

        $manager = self::manager($app, $host);
        $raw = (new RedisManager($app, 'phpredis', ['default' => ['host' => $host, 'port' => 6379, 'database' => self::DATABASE]]))
            ->connection('default');

        Assert::assertSame(self::DATABASE, $manager->connection('default')->client()->getDbNum());
        Assert::assertSame(self::DATABASE, $raw->client()->getDbNum());

        return new self($manager, $raw);
    }

    public static function host(): string
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        if (! is_string($host) || $host === '') {
            Assert::fail('The redis group needs a real Redis: set REDIS_INTEGRATION_HOST, e.g. '
                .'docker compose exec -T -e REDIS_INTEGRATION_HOST=redis app php artisan test --group=redis');
        }

        return $host;
    }

    /** The manager the limiter runs on: database 15 with the OPT_PREFIX, like production. */
    public static function manager(Container $app, ?string $host = null): RedisManager
    {
        return new RedisManager($app, 'phpredis', [
            'options' => ['prefix' => self::DB_PREFIX],
            'default' => ['host' => $host ?? self::host(), 'port' => 6379, 'database' => self::DATABASE],
        ]);
    }

    public static function repository(RedisManager $manager, string $storePrefix): Repository
    {
        return new Repository(new RedisStore($manager, $storePrefix, 'default'));
    }

    /** Makes the Redis-backed repository the app's cache. */
    public function useAsCache(): Repository
    {
        $repository = self::repository($this->manager, $this->storePrefix);
        Cache::swap($repository);

        return $repository;
    }

    /** A second repository on the same Redis, as another worker would have. */
    public function newRepository(): Repository
    {
        return self::repository($this->manager, $this->storePrefix);
    }

    /** A random project id; its counter and warning keys are registered for cleanup. */
    public function project(int $ts = self::TS): int
    {
        $id = random_int(1_000_000_000, 2_000_000_000);
        $this->track($this->counterKey($id, $ts), $this->warnedKey($id, $ts));

        return $id;
    }

    /** The real name of a project's counter: OPT_PREFIX + cache prefix + key. */
    public function counterKey(int $id, int $ts = self::TS): string
    {
        return self::DB_PREFIX.$this->storePrefix."capture-rate:{$id}:".intdiv($ts, 60);
    }

    public function warnedKey(int $id, int $ts = self::TS): string
    {
        return self::DB_PREFIX.$this->storePrefix."capture-rate-warned:{$id}:".intdiv($ts, 60);
    }

    public function counter(int $id, int $ts = self::TS): int
    {
        return (int) $this->raw->get($this->counterKey($id, $ts));
    }

    /** Unprefixed connection to database 15: sees and changes the real key names. */
    public function raw(): Connection
    {
        return $this->raw;
    }

    public function track(string ...$realKeys): void
    {
        array_push($this->keys, ...$realKeys);
    }

    public function cleanup(): void
    {
        if ($this->keys !== []) {
            $this->raw->del(...$this->keys);
        }
        $this->keys = [];
        $this->raw->disconnect();
        $this->manager->connection('default')->disconnect();
    }
}
