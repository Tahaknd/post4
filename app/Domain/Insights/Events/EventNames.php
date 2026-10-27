<?php

namespace App\Domain\Insights\Events;

use App\Domain\Insights\InsightFailed;
use App\Domain\Insights\Runs\InsightGate;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use App\Infrastructure\ClickHouse\RoSettings;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * The event names of one project for the picker of the trends UI: the most frequent names of the last 30 UTC days
 * with their counts (at most {@see EventNamesQuery::MAX_NAMES}, `truncated` says there are more), optionally narrowed
 * by a case-insensitive substring.
 *
 * It is interactive (someone is typing), so unlike a trend it never waits in a queue:
 *  - a per-project result cache on the insight store (`insights.events_cache_ttl_seconds`, always with a TTL, key
 *    = project + sha256 of the normalised search text, only SUCCESSFUL answers are stored). Any cache failure fails
 *    open: the query runs live and a throttled warning is logged (LimiterOutage);
 *  - ONE global slot ({@see InsightGate::acquireEventNames()}): the insight workers use 2 of the read-only user's 3
 *    ClickHouse slots, this is the third. Over the cap {@see EventNamesBusy} is thrown (the controller answers 429).
 *    The slot is fail-open;
 *  - a short ClickHouse time limit (`insights.events_max_execution_time`, a LOWER value than the profile's 20 s, which
 *    the profile constraint (min 1, max 22) allows) and a matching HTTP timeout.
 * Reads go through {@see ProjectScopedReader} (the read-only user, the scoped view). A ClickHouse failure becomes
 * {@see InsightFailed} (safe message, raw text only in the log).
 */
final class EventNames
{
    private const KEY_PREFIX = 'insights:events';

    public function __construct(
        private readonly ProjectScopedReader $reader,
        private readonly EventNamesQuery $query,
        private readonly InsightGate $gate,
        /** The insight store: the container gives this class `config('insights.cache_store')` (AppServiceProvider). */
        private readonly Cache $cache,
    ) {}

    /** Trim and lower-case: what the cache key and the query see. Null when nothing is left. */
    public static function normalise(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }
        $search = mb_strtolower(trim($search, " \t\n\r\0\x0B\u{00A0}"));

        return $search === '' ? null : $search;
    }

    public static function cacheKey(int $projectId, ?string $normalisedSearch): string
    {
        return self::KEY_PREFIX.':'.$projectId.':'.hash('sha256', $normalisedSearch ?? '');
    }

    /**
     * @return array{events: list<array{name: string, count: int}>, truncated: bool}
     *
     * @throws EventNamesBusy the slot is taken
     * @throws InsightFailed ClickHouse could not answer
     */
    public function list(int $projectId, ?string $search): array
    {
        $search = self::normalise($search);
        $ttl = (int) config('insights.events_cache_ttl_seconds');
        $key = self::cacheKey($projectId, $search);

        $cacheDown = false;
        if ($ttl > 0) {
            $cached = $this->cacheGet($key, $cacheDown);
            if ($cached !== null) {
                return $cached;
            }
        }

        $permit = $this->gate->acquireEventNames();
        if ($permit === null) {
            throw new EventNamesBusy;
        }

        try {
            $answer = $this->run($projectId, $search);
        } finally {
            $permit->release();
        }

        // One outage costs one timeout: when the read already failed, do not wait for a second failing write.
        if ($ttl > 0 && ! $cacheDown) {
            LimiterOutage::guard(fn () => $this->cache->put($key, $answer, $ttl), limiter: 'insights');
        }

        return $answer;
    }

    /** @return array{events: list<array{name: string, count: int}>, truncated: bool} */
    private function run(int $projectId, ?string $search): array
    {
        $built = $this->query->build(now(), $search);
        $limit = max(1, (int) config('insights.events_max_execution_time'));

        try {
            $rows = $this->reader->select(
                $projectId,
                $built->sql,
                $built->params,
                [
                    RoSettings::MAX_EXECUTION_TIME => $limit,
                    // The answer is at most MAX_NAMES + 1 rows; a smaller cap than the profile's keeps a bug loud.
                    RoSettings::MAX_RESULT_ROWS => EventNamesQuery::MAX_NAMES + 1,
                    RoSettings::LOG_COMMENT => 'project:'.$projectId.' insight:event-names',
                ],
                $limit + 3,
            );
        } catch (ClickHouseException $e) {
            $this->logFailure($projectId, $e->kind, $e);

            throw new InsightFailed($e->kind, $e);
        } catch (ConnectionException $e) {
            $this->logFailure($projectId, ClickHouseErrorKind::Unavailable, $e);

            throw new InsightFailed(ClickHouseErrorKind::Unavailable, $e);
        }

        $events = array_map(fn (array $row) => ['name' => (string) $row['name'], 'count' => (int) $row['total']], $rows);

        return [
            'events' => array_slice($events, 0, EventNamesQuery::MAX_NAMES),
            'truncated' => count($events) > EventNamesQuery::MAX_NAMES,
        ];
    }

    /** @return array{events: list<array{name: string, count: int}>, truncated: bool}|null */
    private function cacheGet(string $key, ?bool &$down): ?array
    {
        $unavailable = new \stdClass;
        $value = LimiterOutage::guard(fn () => $this->cache->get($key), $unavailable, 'insights');
        $down = $value === $unavailable;

        return self::isAnswer($value) ? $value : null;
    }

    private static function isAnswer(mixed $value): bool
    {
        if (! is_array($value) || ! is_bool($value['truncated'] ?? null) || ! is_array($value['events'] ?? null) || ! array_is_list($value['events'])) {
            return false;
        }

        foreach ($value['events'] as $event) {
            if (! is_array($event) || ! is_string($event['name'] ?? null) || ! is_int($event['count'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function logFailure(int $projectId, ClickHouseErrorKind $kind, \Throwable $e): void
    {
        $level = match ($kind) {
            ClickHouseErrorKind::Busy => 'info',
            ClickHouseErrorKind::ConstraintViolation, ClickHouseErrorKind::AuthenticationFailed => 'error',
            default => 'warning',
        };
        Log::log($level, 'event name query failed', [
            'project_id' => $projectId,
            'kind' => $kind->value,
            'code' => $e instanceof ClickHouseException ? $e->errorCode : null,
            'message' => mb_substr($e->getMessage(), 0, 300),
        ]);
    }
}
