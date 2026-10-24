<?php

namespace App\Jobs;

use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Infrastructure\ClickHouse\ClickHouseException;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Removes every event of a deleted project from posthog.events with an admin lightweight delete
 * (`DELETE FROM`: the read-only user cannot write, and a lightweight delete hides the rows at once and leaves the
 * physical removal to background merges, where `ALTER TABLE ... DELETE` would rewrite whole parts).
 *
 * Scope. events is the only table keyed by project. events_errors (rejected Kafka messages) has no project
 * column, only the raw message; it expires by itself after 14 days (its TTL), so a rejected message of a deleted
 * project can stay readable by an administrator for that long. kafka_events stores nothing.
 *
 * Safety.
 *  - It refuses a project id that is not a positive int and a project that STILL EXISTS (a purge for a live
 *    project is a bug, never a request), and only ever deletes `project_id = N`.
 *  - Idempotent: a second run over an empty set deletes nothing and succeeds, so a retry after a timeout, or the
 *    delayed second pass, is harmless. The DELETE waits for its mutation (lightweight_deletes_sync=2), then the
 *    job counts what is left and fails (and is retried) while any row remains.
 *  - ShouldBeUnique per (project, pass): two dispatches of the same pass cannot run side by side, but the
 *    delayed second pass is a different unique key and is not swallowed by the first.
 *  - A client-side timeout does not cancel the mutation in ClickHouse; the retry just queues another cheap one.
 *
 * Timing. The Redis queue's retry_after is 90 s. The two ClickHouse calls may use 30 s + 10 s, so the job's own
 * 60 s timeout leaves headroom and stays under retry_after: it is never released to a second worker while it
 * is still running. The job runs on the default queue, the one Horizon's supervisor watches.
 *
 * Failure. Horizon's failed_jobs gets the project id and a ClickHouse error kind and code, built here: never the
 * server text (which echoes query fragments), a URL with credentials or a password.
 */
class PurgeProjectEvents implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    /** Seconds the unique lock outlives a job that died without releasing it (the delayed pass waits 10 minutes in the queue). */
    public int $uniqueFor = 3600;

    private const int DELETE_TIMEOUT_SECONDS = 30;

    private const int VERIFY_TIMEOUT_SECONDS = 10;

    /**
     * @param  int  $pass  1 is the purge queued with the deletion, 2 the delayed sweep for events that were still in flight
     */
    public function __construct(public readonly int $projectId, public readonly int $pass = 1)
    {
        if ($projectId < 1) {
            throw new InvalidArgumentException('A project purge needs a positive project id.');
        }
    }

    public function uniqueId(): string
    {
        return "purge-project-events:{$this->projectId}:{$this->pass}";
    }

    /** @return list<int> */
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

        try {
            // sync=2: returns once the mutation has been applied, so the count below sees the result.
            $clickHouse->execute(
                'DELETE FROM posthog.events WHERE project_id = {project_id:UInt32}',
                ['project_id' => $this->projectId],
                self::DELETE_TIMEOUT_SECONDS,
                ['lightweight_deletes_sync' => 2],
            );

            $remaining = (int) ($clickHouse->select(
                'SELECT count() AS n FROM posthog.events WHERE project_id = {project_id:UInt32}',
                ['project_id' => $this->projectId],
                timeout: self::VERIFY_TIMEOUT_SECONDS,
            )[0]['n'] ?? -1);
        } catch (Throwable $e) {
            throw $this->sanitised($e);
        }

        if ($remaining !== 0) {
            throw new RuntimeException("Purge of project {$this->projectId} left {$remaining} event rows in ClickHouse; retrying.");
        }

        Log::info('project.events_purged', ['project_id' => $this->projectId, 'pass' => $this->pass]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('project purge failed for good; its events may remain in ClickHouse', [
            'project_id' => $this->projectId,
            'pass' => $this->pass,
            'exception' => $e::class,
        ]);
    }

    private function sanitised(Throwable $e): RuntimeException
    {
        $detail = $e instanceof ClickHouseException
            ? "ClickHouse {$e->kind->value} (code ".($e->errorCode ?? 'none').')'
            : $e::class;

        return new RuntimeException("Purge of project {$this->projectId} failed: {$detail}.");
    }
}
