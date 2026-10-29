<?php

namespace App\Jobs;

use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Removes every row of a deleted project from ONE ClickHouse table (see the two concrete jobs: PurgeProjectEvents for
 * posthog.events, PurgeProjectPersonDistinctIds for posthog.person_distinct_ids) with an admin lightweight delete
 * (`DELETE FROM`: the read-only user cannot write; it needs no part rewrite to take effect). Everything below is
 * written for events and holds for every table with a `project_id UInt32` column: the two jobs differ only in the
 * table name, so they share one implementation, one set of test seams and one queue, and fail, retry and finish
 * independently of each other.
 *
 * How it waits. The DELETE is issued asynchronously (lightweight_deletes_sync=0) and the job then looks at
 * count(): a project's rows are gone when the count is 0. The job never stacks identical mutations: it only
 * issues a DELETE when system.mutations shows no unfinished mutation of the table that names this project
 * id, so a retry, the second pass, or a slow mutation cannot queue the same work twice. While rows remain it
 * polls for a short while, then release()s itself back to the `maintenance` queue; each release is an attempt
 * (hence the high $tries) while real failures are bounded by $maxExceptions. Non-transient ClickHouse kinds
 * (bad credentials, a constraint violation) fail the job at once instead of retrying.
 *
 * Disk. MEASURED on ClickHouse 25.8 (throwaway table with events' engine and partitioning, 20 000 rows in one
 * old monthly part): a lightweight delete only masks rows. `system.parts.rows` and `bytes_on_disk` do NOT drop
 * (3 771 439 B before, 3 771 681 B after); the part is rewritten with a mask column. Space comes back only when
 * the part is merged: OPTIMIZE ... FINAL turned it into a 0-row, 438-byte part. A partition that holds a SINGLE
 * part (typical for old months) is never merged by the background merger on its own, not even with
 * min_age_to_force_merge_seconds (no merge in 80 s); once a second part lands in the partition a merge ran within
 * 20 s and dropped the masked rows. So after a purge the data is invisible but may stay on disk until an
 * explicit OPTIMIZE (not done here: it is heavy).
 *
 * Scope. events and person_distinct_ids are the two tables keyed by project (one job class each).
 * events_errors (rejected Kafka messages of both topics) has no project column, only the raw message; it
 * expires after 14 days (its TTL), so a rejected message of a deleted project (it can contain the project's
 * API key) stays readable by an administrator for that long. kafka_events and kafka_person_distinct_ids store
 * nothing. The person_distinct_ids rows of a deleted project can arrive after the first pass for the same
 * reason as events (the identity service still holds a message), hence the same delayed second pass and the
 * daily `events:sweep-orphans`.
 *
 * Safety.
 *  - It refuses a project id that is not a positive int and a project that STILL EXISTS (a purge for a live
 *    project is a bug, never a request), and only ever deletes `project_id = N`.
 *  - Idempotent: a run over an empty set deletes nothing and succeeds.
 *  - ShouldBeUnique per (project, pass): two dispatches of the same pass cannot run side by side, but the
 *    delayed second pass is a different unique key and is not swallowed by the first.
 *
 * Timing. The Redis queue's retry_after is 90 s. One run stays within a 50 s budget (10 s per read, 15 s for the
 * DELETE) under the job's 60 s timeout, so it is never released to a second worker while still running.
 *
 * Failure. failed_jobs gets the project id and a ClickHouse error kind and code, built here: never the server
 * text (which echoes query fragments), a URL or a password.
 */
abstract class PurgeProjectRows implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'maintenance';

    /** Releases while a mutation runs count as attempts: about 15 minutes of waiting at RELEASE_DELAY_SECONDS. */
    public int $tries = 30;

    /** Real failures (exceptions) that end the job; releases do not count. */
    public int $maxExceptions = 5;

    public int $timeout = 60;

    /** Seconds the unique lock outlives a job that died without releasing it (the delayed pass waits 10 minutes in the queue). */
    public int $uniqueFor = 3600;

    public const int RELEASE_DELAY_SECONDS = 30;

    private const int READ_TIMEOUT_SECONDS = 10;

    private const int DELETE_TIMEOUT_SECONDS = 15;

    /** @internal Test seams: the in-process wait for the mutation stops this many seconds after the job started, polling this many microseconds apart. */
    public static int $budgetSeconds = 50;

    public static int $pollMicroseconds = 1_000_000;

    /**
     * @param  int  $pass  1 is the purge queued with the deletion, 2 the delayed sweep for events that were still in flight
     */
    public function __construct(public readonly int $projectId, public readonly int $pass = 1)
    {
        if ($projectId < 1) {
            throw new InvalidArgumentException('A project purge needs a positive project id.');
        }

        $this->onQueue(self::QUEUE);
    }

    /** The table in `posthog` whose rows of the project this job deletes (a constant of the subclass, never user input). */
    abstract protected function table(): string;

    public function uniqueId(): string
    {
        return 'purge-project-'.str_replace('_', '-', $this->table()).":{$this->projectId}:{$this->pass}";
    }

    /** @return list<int> seconds to wait after an exception */
    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function handle(ClickHouseClient $clickHouse): void
    {
        if (Project::query()->whereKey($this->projectId)->exists()) {
            Log::warning('project purge skipped: the project still exists', ['project_id' => $this->projectId, 'pass' => $this->pass]);

            return;
        }

        $started = microtime(true);

        try {
            if ($this->remaining($clickHouse) === 0) {
                $this->done();

                return;
            }

            $pending = $this->pendingMutations($clickHouse);

            if ($pending['pending'] === 0) {
                // Exactly one mutation per run that finds rows and no unfinished mutation of ours.
                $clickHouse->execute(
                    'DELETE FROM posthog.'.$this->table().' WHERE project_id = {project_id:UInt32}',
                    ['project_id' => $this->projectId],
                    self::DELETE_TIMEOUT_SECONDS,
                    ['lightweight_deletes_sync' => 0],
                );
            } elseif ($pending['failing'] > 0) {
                Log::warning('project purge: a pending mutation keeps failing in ClickHouse', ['project_id' => $this->projectId, 'pass' => $this->pass]);
            }

            while (self::$budgetSeconds - (microtime(true) - $started) > self::READ_TIMEOUT_SECONDS + 2) {
                usleep(self::$pollMicroseconds);

                if ($this->remaining($clickHouse) === 0) {
                    $this->done();

                    return;
                }
            }
        } catch (Throwable $e) {
            if ($e instanceof ClickHouseException && in_array($e->kind, [ClickHouseErrorKind::AuthenticationFailed, ClickHouseErrorKind::ConstraintViolation], true)) {
                // Retrying cannot help: a deployment or code problem.
                $this->fail($this->sanitised($e));

                return;
            }

            throw $this->sanitised($e);
        }

        // Rows remain and the mutation is still running: look again later without using up a failure.
        $this->release(self::RELEASE_DELAY_SECONDS);
    }

    public function failed(Throwable $e): void
    {
        Log::error('project purge failed for good; its rows may remain in ClickHouse', [
            'table' => $this->table(),
            'project_id' => $this->projectId,
            'pass' => $this->pass,
            'exception' => $e::class,
        ]);
    }

    private function done(): void
    {
        Log::info('project.'.$this->table().'_purged', ['project_id' => $this->projectId, 'pass' => $this->pass]);
    }

    private function remaining(ClickHouseClient $clickHouse): int
    {
        return (int) ($clickHouse->select(
            'SELECT count() AS n FROM posthog.'.$this->table().' WHERE project_id = {project_id:UInt32}',
            ['project_id' => $this->projectId],
            timeout: self::READ_TIMEOUT_SECONDS,
        )[0]['n'] ?? -1);
    }

    /**
     * Unfinished mutations of the table whose command names this project id as a whole number (the command
     * text is `(UPDATE _row_exists = 0 WHERE project_id = _CAST(N, 'UInt32'))`), and how many of them have failed.
     *
     * @return array{pending: int, failing: int}
     */
    private function pendingMutations(ClickHouseClient $clickHouse): array
    {
        $row = $clickHouse->select(
            "SELECT count() AS pending, countIf(latest_fail_reason != '') AS failing FROM system.mutations "
            ."WHERE database = 'posthog' AND table = '{$this->table()}' AND is_done = 0 AND match(command, {pattern:String})",
            ['pattern' => '(^|[^0-9])'.$this->projectId.'([^0-9]|$)'],
            timeout: self::READ_TIMEOUT_SECONDS,
        )[0] ?? [];

        return ['pending' => (int) ($row['pending'] ?? 0), 'failing' => (int) ($row['failing'] ?? 0)];
    }

    private function sanitised(Throwable $e): \RuntimeException
    {
        $detail = $e instanceof ClickHouseException
            ? "ClickHouse {$e->kind->value} (code ".($e->errorCode ?? 'none').')'
            : $e::class;

        return new \RuntimeException("Purge of project {$this->projectId} ({$this->table()}) failed: {$detail}.");
    }
}
