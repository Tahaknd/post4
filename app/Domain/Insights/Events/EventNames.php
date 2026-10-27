<?php

namespace App\Domain\Insights\Events;

use App\Domain\Insights\InsightFailed;
use App\Domain\Insights\Runs\InsightGate;
use App\Domain\Insights\Runs\Permit;
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
 * with their counts (at most {@see EventNamesSqlBuilder::MAX_NAMES}, `truncated` says there are more), optionally
 * narrowed by a case-insensitive substring.
 *
 * It is interactive (someone is typing), so unlike a trend it never waits in a queue, and because ONE ClickHouse slot of
 * the read-only user is shared by every tenant it is built so that no tenant can hold it:
 *  - a per-project result cache on the insight store (always with a TTL, key = project + sha256 of the normalised
 *    search text, only SUCCESSFUL answers are stored; the list without a search text lives longer). Any cache failure
 *    fails open: the query runs live WITHOUT the gates below (they live in the same store) and a throttled warning is
 *    logged (LimiterOutage);
 *  - single flight per (project, search text): of identical concurrent misses one queries, the others wait (at most
 *    `insights.events_wait_ms`) for its answer in the cache;
 *  - at most ONE event query of a project in flight, and at most `insights.events_project_budget_ms` of ClickHouse
 *    time per project and minute (measured, charged after each query): a tenant that hammers distinct search texts runs
 *    out of its budget and is answered 429 until the minute rolls over, instead of occupying the slot;
 *  - the ONE global slot ({@see InsightGate::acquireEventNames()}), acquired by polling for at most the same wait
 *    budget, then {@see EventNamesBusy} (the controller answers 429). All locks are owner-checked, with TTLs and
 *    fail-open;
 *  - a short ClickHouse time limit (`insights.events_max_execution_seconds`, a LOWER value than the profile's 20 s, which
 *    the profile constraint (min 1, max 22) allows) and a matching HTTP timeout.
 * Reads go through {@see ProjectScopedReader} (the read-only user, the scoped view). A ClickHouse failure becomes
 * {@see InsightFailed} (safe message, raw text only in the log).
 */
final class EventNames
{
    private const KEY_PREFIX = 'insights:events';

    private const POLL_MICROSECONDS = 50_000;

    public function __construct(
        private readonly ProjectScopedReader $reader,
        private readonly EventNamesSqlBuilder $query,
        private readonly InsightGate $gate,
        /** The insight store: the container gives this class `config('insights.cache_store')` (AppServiceProvider). */
        private readonly Cache $cache,
    ) {}

    /**
     * Unicode-aware trim and lower case: what the cache key and the query see. Null when nothing is left.
     * (The byte-wise trim() would cut the lead and trail bytes of characters such as à, Š or ©: their UTF-8 encodings
     * end or begin with the bytes of a no-break space.)
     */
    public static function normalise(?string $search): ?string
    {
        if ($search === null || ! mb_check_encoding($search, 'UTF-8')) {
            return null;
        }

        $trimmed = preg_replace('/\A[\s\p{Z}\x{FEFF}\x{200B}]+|[\s\p{Z}\x{FEFF}\x{200B}]+\z/u', '', $search);
        if ($trimmed === null || $trimmed === '') {
            return null;
        }

        return mb_strtolower($trimmed);
    }

    public static function cacheKey(int $projectId, ?string $normalisedSearch): string
    {
        return self::KEY_PREFIX.':'.$projectId.':'.hash('sha256', $normalisedSearch ?? '');
    }

    /**
     * @return array{events: list<array{name: string, count: int}>, truncated: bool}
     *
     * @throws EventNamesBusy it could not get its turn within the wait budget
     * @throws InsightFailed ClickHouse could not answer
     */
    public function list(int $projectId, ?string $search): array
    {
        $search = self::normalise($search);
        $ttl = (int) config($search === null ? 'insights.events_empty_search_cache_ttl_seconds' : 'insights.events_cache_ttl_seconds');
        $key = self::cacheKey($projectId, $search);
        $deadline = hrtime(true) + max(0, (int) config('insights.events_wait_ms')) * 1_000_000;

        $cacheDown = false;
        if ($ttl > 0) {
            $cached = $this->cacheGet($key, $cacheDown);
            if ($cached !== null) {
                return $cached;
            }
        }

        // The gates live in the store that just failed: without it there is nothing to coordinate with. The profile's
        // own concurrency limit (a ClickHouse `busy`) is the backstop, and nothing is cached.
        if ($cacheDown) {
            return $this->run($projectId, $search);
        }

        $permits = [];

        try {
            if ($ttl > 0) {
                $flight = $this->gate->acquireEventNamesFlight($projectId, hash('sha256', $search ?? ''));
                if ($flight === null) {
                    // An identical request is already asking ClickHouse: wait for its answer instead of a slot.
                    return $this->waitForAnswer($key, $deadline) ?? throw new EventNamesBusy;
                }
                $permits[] = $flight;

                // It may have finished while this request was getting here.
                $cached = $this->cacheGet($key, $down);
                if ($cached !== null) {
                    return $cached;
                }
            }

            $permits[] = $this->waitFor(fn () => $this->gate->acquireEventNamesProject($projectId), $deadline) ?? throw new EventNamesBusy;

            if ($this->gate->eventNamesUsedMs($projectId) >= (int) config('insights.events_project_budget_ms')) {
                throw new EventNamesBusy(min(10, $this->gate->secondsToNextMinute()));
            }

            $permits[] = $this->waitFor(fn () => $this->gate->acquireEventNames(), $deadline) ?? throw new EventNamesBusy;

            $started = hrtime(true);
            try {
                $answer = $this->run($projectId, $search);
            } finally {
                $this->gate->chargeEventNames($projectId, (int) ceil((hrtime(true) - $started) / 1_000_000));
            }

            if ($ttl > 0) {
                LimiterOutage::guard(fn () => $this->cache->put($key, $answer, $ttl), limiter: 'insights');
            }

            return $answer;
        } finally {
            foreach (array_reverse($permits) as $permit) {
                $permit->release();
            }
        }
    }

    /**
     * Polls `$acquire` (it returns null while the lock is taken) until it gets a permit or the deadline passes.
     *
     * @param  callable(): ?Permit  $acquire
     */
    private function waitFor(callable $acquire, int $deadline): ?Permit
    {
        for (; ;) {
            $permit = $acquire();
            if ($permit !== null || hrtime(true) >= $deadline) {
                return $permit;
            }

            usleep(min(self::POLL_MICROSECONDS, max(1, intdiv($deadline - hrtime(true), 1000))));
        }
    }

    /** @return array{events: list<array{name: string, count: int}>, truncated: bool}|null */
    private function waitForAnswer(string $key, int $deadline): ?array
    {
        for (; ;) {
            $cached = $this->cacheGet($key, $down);
            if ($cached !== null || $down || hrtime(true) >= $deadline) {
                return $cached;
            }

            usleep(min(self::POLL_MICROSECONDS, max(1, intdiv($deadline - hrtime(true), 1000))));
        }
    }

    /** @return array{events: list<array{name: string, count: int}>, truncated: bool} */
    private function run(int $projectId, ?string $search): array
    {
        $built = $this->query->build(now(), $search);
        $limit = max(1, (int) config('insights.events_max_execution_seconds'));

        try {
            $rows = $this->reader->select(
                $projectId,
                $built->sql,
                $built->params,
                [
                    RoSettings::MAX_EXECUTION_TIME => $limit,
                    // The answer is at most MAX_NAMES + 1 rows; a smaller cap than the profile's keeps a bug loud.
                    RoSettings::MAX_RESULT_ROWS => EventNamesSqlBuilder::MAX_NAMES + 1,
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
            'events' => array_slice($events, 0, EventNamesSqlBuilder::MAX_NAMES),
            'truncated' => count($events) > EventNamesSqlBuilder::MAX_NAMES,
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
