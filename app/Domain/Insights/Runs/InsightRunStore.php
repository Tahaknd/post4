<?php

namespace App\Domain\Insights\Runs;

use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * The state of one asynchronous insight run, one cache entry per run id.
 *
 * Store: the insight cache store (`config('insights.cache_store')`, Redis; the container gives this class that
 * store, see AppServiceProvider), the same store and bounded-timeout connection the results of InsightRunner live
 * in. EVERY write carries a TTL (there is no TTL-less key): `run_ttl_seconds` while the run is queued or running,
 * `finished_ttl_seconds` once it is done, failed or cancelled (the poller has that long to collect the answer).
 * Nothing is ever flushed; a run that nobody collects expires. An evicted or expired record is a 404 on the poll,
 * which clients read as "expired, submit again".
 *
 * The id is 128 random bits (hex): the only handle a client has, never derived from the project, the user or the
 * definition. The record names the type of insight (`trends`), the project and the user that started the run, so a
 * poll can be authorised against both without trusting the id alone.
 *
 * State machine. queued -> running -> done | failed, queued/running -> cancelled, running -> queued (the job
 * released itself). done, failed and cancelled are TERMINAL: every transition method is a no-op returning false on a
 * terminal or missing record, so a late job can never overwrite a cancellation and a cancellation can never
 * overwrite an answer. A transition is a compare-and-set: read, check the status, write, all under a short
 * per-run lock (`insights:runlock:{id}`, TTL 5 s, atomic `SET NX PX` on Redis, owner-checked release) that works on
 * every store; contention is one transition of one run for a few milliseconds. (The capture limiter uses EVAL on
 * Redis because it is a hot counter; a run record is a handful of writes per run and is serialised by PHP, which a
 * Lua script could not read.)
 *
 * A record holds ids, the definition hash, a status, the dispatch slot indexes (informational: the job carries the
 * authoritative ones in its payload), and either the result (size capped by `max_result_bytes`) or a
 * ClickHouseErrorKind VALUE. The user-facing message is derived from the kind when the record is read: raw
 * ClickHouse text, SQL and exception messages never reach this store.
 */
final class InsightRunStore
{
    public const string QUEUED = 'queued';

    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    public const string CANCELLED = 'cancelled';

    /** Insight types; part of the record and of the flight keys (the next insight type gets its own value). */
    public const string TYPE_TRENDS = 'trends';

    private const string KEY_PREFIX = 'insights:run:';

    private const string LOCK_PREFIX = 'insights:runlock:';

    private const int LOCK_SECONDS = 5;

    private const int LOCK_WAIT_SECONDS = 2;

    private const string ID_PATTERN = '/\A[0-9a-f]{32}\z/';

    /** Regex fragment for route constraints (the same shape isValidId() accepts). */
    public const string ID_ROUTE_PATTERN = '[0-9a-f]{32}';

    public function __construct(private readonly Cache $cache) {}

    /** 128 random bits as 32 lowercase hex characters. */
    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [self::DONE, self::FAILED, self::CANCELLED], true);
    }

    /**
     * Stores a queued run under an id from {@see self::newId()} (the id comes first because it owns the dispatch
     * slots). @throws Throwable when the store is unreachable (the caller answers 503)
     */
    public function create(string $id, string $type, int $projectId, int $userId, string $definitionHash, ?int $projectSlot, ?int $userSlot): void
    {
        $this->cache->put(self::KEY_PREFIX.$id, [
            'type' => $type,
            'project_id' => $projectId,
            'user_id' => $userId,
            'hash' => $definitionHash,
            'status' => self::QUEUED,
            'project_slot' => $projectSlot,
            'user_slot' => $userSlot,
            'busy_retries' => 0,
            'created_at' => time(),
            'result' => null,
            'error' => null,
        ], max(1, (int) config('insights.run_ttl_seconds')));
    }

    /**
     * Null when the run is unknown, expired, or the entry is not of the expected shape.
     *
     * @return array{type: string, project_id: int, user_id: int, hash: string, status: string, project_slot: ?int, user_slot: ?int, busy_retries: int, created_at: int, result: ?array<string, mixed>, error: ?string}|null
     *
     * @throws Throwable when the store is unreachable
     */
    public function find(string $id): ?array
    {
        if (! self::isValidId($id)) {
            return null;
        }

        $record = $this->cache->get(self::KEY_PREFIX.$id);

        if (! is_array($record)
            || ! is_string($record['type'] ?? null)
            || ! is_int($record['project_id'] ?? null) || ! is_int($record['user_id'] ?? null)
            || ! is_string($record['hash'] ?? null)
            || ! in_array($record['status'] ?? null, [self::QUEUED, self::RUNNING, self::DONE, self::FAILED, self::CANCELLED], true)
            || ! is_int($record['created_at'] ?? null)) {
            return null;
        }

        return [
            'type' => $record['type'],
            'project_id' => $record['project_id'],
            'user_id' => $record['user_id'],
            'hash' => $record['hash'],
            'status' => $record['status'],
            'project_slot' => is_int($record['project_slot'] ?? null) ? $record['project_slot'] : null,
            'user_slot' => is_int($record['user_slot'] ?? null) ? $record['user_slot'] : null,
            'busy_retries' => is_int($record['busy_retries'] ?? null) ? $record['busy_retries'] : 0,
            'created_at' => $record['created_at'],
            'result' => is_array($record['result'] ?? null) ? $record['result'] : null,
            'error' => is_string($record['error'] ?? null) && ClickHouseErrorKind::tryFrom($record['error']) !== null ? $record['error'] : null,
        ];
    }

    /**
     * queued or running -> running (running again is a takeover after a dead attempt; it refreshes the TTL).
     * False when the run is gone, finished or cancelled: the job must not query.
     */
    public function markRunning(string $id): bool
    {
        return $this->transition($id, [self::QUEUED, self::RUNNING], ['status' => self::RUNNING], (int) config('insights.run_ttl_seconds'));
    }

    /** Back to queued (the job released itself); `$busyRetries` counts the releases caused by a busy ClickHouse. */
    public function markQueued(string $id, ?int $busyRetries = null): bool
    {
        $changes = ['status' => self::QUEUED];
        if ($busyRetries !== null) {
            $changes['busy_retries'] = $busyRetries;
        }

        return $this->transition($id, [self::QUEUED, self::RUNNING], $changes, (int) config('insights.run_ttl_seconds'));
    }

    /**
     * Stores the answer (a TrendsResult::toArray() style plain array). A result above `max_result_bytes` is stored
     * as a TooManyRows failure instead. One retry on a store failure before it propagates. False when the run is
     * gone, finished or cancelled.
     *
     * @param  array<string, mixed>  $result
     */
    public function markDone(string $id, array $result): bool
    {
        if (strlen((string) json_encode($result)) > (int) config('insights.max_result_bytes')) {
            return $this->markFailed($id, ClickHouseErrorKind::TooManyRows);
        }

        $write = fn (): bool => $this->transition($id, [self::QUEUED, self::RUNNING], ['status' => self::DONE, 'result' => $result, 'error' => null], (int) config('insights.finished_ttl_seconds'));

        try {
            return $write();
        } catch (Throwable) {
            return $write();
        }
    }

    public function markFailed(string $id, ClickHouseErrorKind $kind): bool
    {
        return $this->transition($id, [self::QUEUED, self::RUNNING], ['status' => self::FAILED, 'result' => null, 'error' => $kind->value], (int) config('insights.finished_ttl_seconds'));
    }

    /** queued or running -> cancelled. False when there was nothing left to cancel. */
    public function cancel(string $id): bool
    {
        return $this->transition($id, [self::QUEUED, self::RUNNING], ['status' => self::CANCELLED, 'result' => null, 'error' => null], (int) config('insights.finished_ttl_seconds'));
    }

    public function forget(string $id): void
    {
        $this->cache->forget(self::KEY_PREFIX.$id);
    }

    /** Real cache key of a run (tests assert its TTL against a real Redis). */
    public static function key(string $id): string
    {
        return self::KEY_PREFIX.$id;
    }

    /**
     * Compare-and-set under the per-run lock: applies `$changes` only when the stored status is one of
     * `$allowedFrom`. A missing or expired record is never recreated.
     *
     * @param  list<string>  $allowedFrom
     * @param  array<string, mixed>  $changes
     */
    private function transition(string $id, array $allowedFrom, array $changes, int $ttl): bool
    {
        return $this->cache->lock(self::LOCK_PREFIX.$id, self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, function () use ($id, $allowedFrom, $changes, $ttl): bool {
            $record = $this->cache->get(self::KEY_PREFIX.$id);
            if (! is_array($record) || ! in_array($record['status'] ?? null, $allowedFrom, true)) {
                return false;
            }

            $this->cache->put(self::KEY_PREFIX.$id, [...$record, ...$changes], max(1, $ttl));

            return true;
        });
    }
}
