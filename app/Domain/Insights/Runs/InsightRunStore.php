<?php

namespace App\Domain\Insights\Runs;

use App\Domain\Insights\Trends\TrendsResult;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Cache as CacheFacade;

/**
 * The state of one asynchronous insight run, one cache entry per run id.
 *
 * Store: `config('insights.cache_store')` (Redis), the same store and bounded-timeout connection the results of
 * InsightRunner live in. EVERY write carries a TTL (there is no TTL-less key): `run_ttl_seconds` while the run is
 * queued or running, `finished_ttl_seconds` once it is done or failed (the poller has that long to collect the
 * answer). Nothing is ever flushed; a run that nobody collects simply expires.
 *
 * The id is 128 random bits (hex): the only handle a client has, never derived from the project, the user or the
 * definition. The record names the project and the user that started the run, so a poll can be authorised against
 * both without trusting the id alone.
 *
 * A record holds ids, the definition hash, a status, and either the result (TrendsResult::toArray, size capped by
 * `max_result_bytes`) or a ClickHouseErrorKind VALUE. The user-facing message is derived from the kind when the
 * record is read: raw ClickHouse text, SQL and exception messages never reach this store.
 */
final class InsightRunStore
{
    public const string QUEUED = 'queued';

    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    private const string KEY_PREFIX = 'insights:run:';

    private const string ID_PATTERN = '/\A[0-9a-f]{32}\z/';

    /** Regex fragment for route constraints (the same shape isValidId() accepts). */
    public const string ID_ROUTE_PATTERN = '[0-9a-f]{32}';

    public function __construct(
        /** Tests inject a store; null resolves config('insights.cache_store') on every use. */
        private readonly ?Cache $cache = null,
    ) {}

    /** 128 random bits as 32 lowercase hex characters. */
    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * Stores a queued run under an id from {@see self::newId()} (the id comes first because it owns the dispatch
     * slot). @throws \Throwable when the store is unreachable (the caller answers 503)
     */
    public function create(string $id, int $projectId, int $userId, string $definitionHash, ?int $slot): void
    {
        $this->write($id, [
            'project_id' => $projectId,
            'user_id' => $userId,
            'hash' => $definitionHash,
            'status' => self::QUEUED,
            'slot' => $slot,
            'busy_retries' => 0,
            'created_at' => time(),
            'result' => null,
            'error' => null,
        ], (int) config('insights.run_ttl_seconds'));
    }

    /**
     * Null when the run is unknown, expired, or the entry is not of the expected shape.
     *
     * @return array{project_id: int, user_id: int, hash: string, status: string, slot: ?int, busy_retries: int, created_at: int, result: ?array<string, mixed>, error: ?string}|null
     *
     * @throws \Throwable when the store is unreachable
     */
    public function find(string $id): ?array
    {
        if (! self::isValidId($id)) {
            return null;
        }

        $record = $this->store()->get(self::KEY_PREFIX.$id);

        if (! is_array($record)
            || ! is_int($record['project_id'] ?? null) || ! is_int($record['user_id'] ?? null)
            || ! is_string($record['hash'] ?? null)
            || ! in_array($record['status'] ?? null, [self::QUEUED, self::RUNNING, self::DONE, self::FAILED], true)
            || ! is_int($record['created_at'] ?? null)) {
            return null;
        }

        return [
            'project_id' => $record['project_id'],
            'user_id' => $record['user_id'],
            'hash' => $record['hash'],
            'status' => $record['status'],
            'slot' => is_int($record['slot'] ?? null) ? $record['slot'] : null,
            'busy_retries' => is_int($record['busy_retries'] ?? null) ? $record['busy_retries'] : 0,
            'created_at' => $record['created_at'],
            'result' => is_array($record['result'] ?? null) ? $record['result'] : null,
            'error' => is_string($record['error'] ?? null) && ClickHouseErrorKind::tryFrom($record['error']) !== null ? $record['error'] : null,
        ];
    }

    public function markRunning(string $id): void
    {
        $this->update($id, ['status' => self::RUNNING], (int) config('insights.run_ttl_seconds'));
    }

    /** Back to queued (the job released itself); `$busyRetries` counts the releases caused by a busy ClickHouse. */
    public function markQueued(string $id, ?int $busyRetries = null): void
    {
        $changes = ['status' => self::QUEUED];
        if ($busyRetries !== null) {
            $changes['busy_retries'] = $busyRetries;
        }
        $this->update($id, $changes, (int) config('insights.run_ttl_seconds'));
    }

    /** Stores the answer. A result above `max_result_bytes` is stored as a TooManyRows failure instead. */
    public function markDone(string $id, TrendsResult $result): void
    {
        $array = $result->toArray();

        if (strlen((string) json_encode($array)) > (int) config('insights.max_result_bytes')) {
            $this->markFailed($id, ClickHouseErrorKind::TooManyRows);

            return;
        }

        $this->update($id, ['status' => self::DONE, 'result' => $array, 'error' => null], (int) config('insights.finished_ttl_seconds'));
    }

    public function markFailed(string $id, ClickHouseErrorKind $kind): void
    {
        $this->update($id, ['status' => self::FAILED, 'result' => null, 'error' => $kind->value], (int) config('insights.finished_ttl_seconds'));
    }

    public function forget(string $id): void
    {
        $this->store()->forget(self::KEY_PREFIX.$id);
    }

    /** Real cache key of a run (tests assert its TTL against a real Redis). */
    public static function key(string $id): string
    {
        return self::KEY_PREFIX.$id;
    }

    /** @param  array<string, mixed>  $changes */
    private function update(string $id, array $changes, int $ttl): void
    {
        $record = $this->store()->get(self::KEY_PREFIX.$id);
        if (! is_array($record)) {
            // Expired while the job ran: nobody can poll it any more, do not resurrect it.
            return;
        }

        $this->write($id, [...$record, ...$changes], $ttl);
    }

    /** @param  array<string, mixed>  $record */
    private function write(string $id, array $record, int $ttl): void
    {
        $this->store()->put(self::KEY_PREFIX.$id, $record, max(1, $ttl));
    }

    private function store(): Cache
    {
        return $this->cache ?? CacheFacade::store((string) config('insights.cache_store'));
    }
}
