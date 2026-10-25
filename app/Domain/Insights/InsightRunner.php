<?php

namespace App\Domain\Insights;

use App\Domain\Insights\Trends\TrendsQuery;
use App\Domain\Insights\Trends\TrendsResult;
use App\Domain\Insights\Trends\TrendsSqlBuilder;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use App\Infrastructure\ClickHouse\RoSettings;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs an insight definition for ONE project and caches the answer.
 *
 * Reads go through {@see ProjectScopedReader} only (the read-only user, the scoped view, the project id as
 * the SQL_project_id setting), never through a ClickHouse client. A ClickHouse failure becomes
 * {@see InsightFailed} with a safe message and a retry/busy flag; the raw server text only goes to the log.
 *
 * Cache: store `config('insights.cache_store')` (default `redis`, independent of CACHE_STORE), key = project id +
 * sha256 of the canonical definition (TrendsQuery::hash(), versioned), TTL `config('insights.cache_ttl_seconds')`
 * (0 = no cache), only SUCCESSFUL results are stored, errors never. The Redis `cache` connection has bounded
 * connect/read timeouts (config/database.php), so a frozen Redis fails quickly; any cache failure FAILS OPEN:
 * the query runs live, the write is skipped (one outage costs one timeout, not two) and a throttled warning is
 * logged (the convention of LimiterOutage). Entries are never flushed (`Cache::flush()` would wipe the shared
 * Redis database); they expire. No stampede protection HERE: concurrent identical misses each run the query, which
 * the profile's max_concurrent_queries_for_user (3) bounds and maps to a "busy" error. The insight API never calls
 * trends() from a request: RunTrendsInsight does, behind a single-flight lock per (project, definition) and a
 * per-project running cap (App\Domain\Insights\Runs\InsightGate), and the API reads the cache through cached().
 */
final class InsightRunner
{
    private const KEY_PREFIX = 'insights:trends';

    /** Unix time of the last "cache unavailable" warning (per process). */
    private static int $lastCacheWarningAt = 0;

    public function __construct(
        private readonly ProjectScopedReader $reader,
        private readonly TrendsSqlBuilder $builder,
        /** Tests inject a store; null resolves config('insights.cache_store') on first use. */
        private readonly ?Cache $cache = null,
    ) {}

    /** @throws InsightFailed */
    public function trends(int $projectId, TrendsQuery $query): TrendsResult
    {
        // INSIGHTS_CACHE_TTL=0 switches the cache off (every call runs live).
        if ((int) config('insights.cache_ttl_seconds') <= 0) {
            return $this->run($projectId, $query);
        }

        $key = self::cacheKey($projectId, $query);

        $cached = $this->cacheGet($key, $cacheDown);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->run($projectId, $query);
        // One outage costs one timeout: when the read already failed, do not wait for a second failing write.
        if (! $cacheDown) {
            $this->cachePut($key, $result);
        }

        return $result;
    }

    /**
     * The cached SUCCESSFUL result of this definition, or null (not cached, cache disabled, or the cache is down:
     * fail-open like trends(), the caller then queues a run). Never queries ClickHouse.
     */
    public function cached(int $projectId, TrendsQuery $query): ?TrendsResult
    {
        if ((int) config('insights.cache_ttl_seconds') <= 0) {
            return null;
        }

        return $this->cacheGet(self::cacheKey($projectId, $query), $down);
    }

    public static function cacheKey(int $projectId, TrendsQuery $query): string
    {
        return self::KEY_PREFIX.':'.$projectId.':'.$query->hash();
    }

    /** @internal Test seam: forgets the last warning so the next failure logs again. */
    public static function resetWarningThrottle(): void
    {
        self::$lastCacheWarningAt = 0;
    }

    private function run(int $projectId, TrendsQuery $query): TrendsResult
    {
        $built = $this->builder->build($query);

        try {
            $rows = $this->reader->select(
                $projectId,
                $built->sql,
                $built->params,
                [RoSettings::LOG_COMMENT => 'project:'.$projectId.' insight:trends:'.substr($query->hash(), 0, 12)],
            );
        } catch (ClickHouseException $e) {
            $this->logFailure($projectId, $query, $e->kind, $e);

            throw new InsightFailed($e->kind, $e);
        } catch (ConnectionException $e) {
            $this->logFailure($projectId, $query, ClickHouseErrorKind::Unavailable, $e);

            throw new InsightFailed(ClickHouseErrorKind::Unavailable, $e);
        }

        return TrendsResult::fromRows($query, $rows);
    }

    private function logFailure(int $projectId, TrendsQuery $query, ClickHouseErrorKind $kind, Throwable $e): void
    {
        // The raw text may echo query fragments and parameter values: logs only, truncated, never the UI.
        // Busy is routine back pressure (info). A constraint violation or a failed authentication is OUR
        // misconfiguration (error): the user cannot fix it and somebody has to look.
        $level = match ($kind) {
            ClickHouseErrorKind::Busy => 'info',
            ClickHouseErrorKind::ConstraintViolation, ClickHouseErrorKind::AuthenticationFailed => 'error',
            default => 'warning',
        };
        Log::log($level, 'insight query failed', [
            'project_id' => $projectId,
            'definition' => substr($query->hash(), 0, 12),
            'kind' => $kind->value,
            'code' => $e instanceof ClickHouseException ? $e->errorCode : null,
            'message' => mb_substr($e->getMessage(), 0, 300),
        ]);
    }

    private function store(): Cache
    {
        return $this->cache ?? CacheFacade::store((string) config('insights.cache_store'));
    }

    private function cacheGet(string $key, ?bool &$down): ?TrendsResult
    {
        $down = false;

        try {
            return TrendsResult::fromArray($this->store()->get($key));
        } catch (Throwable $e) {
            $down = true;
            $this->warnCache($e);

            return null;
        }
    }

    private function cachePut(string $key, TrendsResult $result): void
    {
        try {
            $this->store()->put($key, $result->toArray(), (int) config('insights.cache_ttl_seconds'));
        } catch (Throwable $e) {
            $this->warnCache($e);
        }
    }

    private function warnCache(Throwable $e): void
    {
        $now = time();
        if ($now - self::$lastCacheWarningAt < (int) config('insights.cache_warning_interval_seconds')) {
            return;
        }
        self::$lastCacheWarningAt = $now;

        Log::warning('insight cache unavailable, running the query live', [
            'exception' => $e::class,
            'message' => Str::limit($e->getMessage(), 200),
        ]);
    }
}
