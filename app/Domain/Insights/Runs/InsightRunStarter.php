<?php

namespace App\Domain\Insights\Runs;

use App\Domain\Insights\InsightQuery;
use App\Domain\Insights\InsightRunner;
use App\Jobs\RunInsight;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The whole flow of ASKING for a run, the same for every caller and every insight type: the controller of the insight
 * API, the one of saved insights and the scheduled dashboard refresh all go through here, so there is exactly one place
 * where caps, idempotency and queuing are decided.
 *
 *  1. a fresh cached result answers at once ({@see RunStart::CACHED});
 *  2. a live run of the same user for the same definition is answered again (idempotent);
 *  3. the inflight key, the user slot and the project slot are taken (`InsightGate`), each refusal is a `busy`;
 *  4. the run record is created and the job queued; anything that breaks while doing so releases everything taken
 *     and is `unavailable` (fail closed: a run that cannot be delivered is not promised).
 *
 * `$userId` is the owner of the run (its record, its inflight key, its user slot). {@see self::SYSTEM_USER_ID} is the
 * scheduler: it holds no user slot, so a refresh never eats the interactive allowance of a person, but it does hold a
 * PROJECT slot, so it can never exceed what the project may have in flight.
 */
final class InsightRunStarter
{
    /** The owner of runs nobody asked for (the scheduled refresh). Never a real user id (those start at 1). */
    public const int SYSTEM_USER_ID = 0;

    public function __construct(
        private readonly InsightRunner $runner,
        private readonly InsightRunStore $runs,
        private readonly InsightGate $gate,
    ) {}

    public function start(int $projectId, int $userId, InsightQuery $query, StartOptions $options = new StartOptions): RunStart
    {
        $hash = $query->hash();

        // Identical definitions of the same local day share this entry (see TrendsInsightRequest on quantisation).
        $cached = $this->runner->cached($projectId, $query);
        if ($cached !== null) {
            return RunStart::cached($cached);
        }

        $runId = InsightRunStore::newId();
        $projectSlot = null;
        $userSlot = null;
        $claimed = false;
        $created = false;

        try {
            // Idempotent: a live run of this user for this definition is answered again, nothing new is queued.
            $existing = $this->liveRun($projectId, $userId, $hash);
            if ($existing !== null) {
                return RunStart::queued($existing);
            }

            $claimed = $this->gate->claimInflight($projectId, $userId, $hash, $runId);
            if (! $claimed) {
                // Lost a race with an identical request that is just being queued.
                $winner = $this->gate->inflightRunId($projectId, $userId, $hash);

                return $winner !== null ? RunStart::queued($winner) : RunStart::busy('Too many insights of this project are running. Try again in a few seconds.');
            }

            if ($userId !== self::SYSTEM_USER_ID) {
                $userSlot = $this->gate->acquireUserSlot($userId, $runId);
                if ($userSlot === null) {
                    $this->discard($projectId, $userId, $runId, $hash, $projectSlot, $userSlot, created: false);

                    return RunStart::busy('You have too many insights running. Wait for one to finish, or cancel one, then try again.');
                }
            }

            $projectSlot = $this->gate->acquireProjectSlot($projectId, $runId);
            if ($projectSlot === null) {
                $this->discard($projectId, $userId, $runId, $hash, $projectSlot, $userSlot, created: false);

                return RunStart::busy('Too many insights of this project are running. Try again in a few seconds.');
            }

            $this->runs->create($runId, $query->type(), $projectId, $userId, $hash, $projectSlot, $userSlot);
            $created = true;
            RunInsight::dispatchFor($query, $projectId, $runId, $userId, $projectSlot, $userSlot, $options);
        } catch (Throwable $e) {
            // Ids and the exception class only: the message of a Redis or queue failure is not for the log of every request.
            Log::warning('insight run could not be queued', ['project_id' => $projectId, 'exception' => $e::class]);
            $this->discard($projectId, $userId, $runId, $hash, $projectSlot, $userSlot, $created, $claimed);

            return RunStart::unavailable();
        }

        return RunStart::queued($runId);
    }

    /** The id of a queued or running run this user already has for the definition, else null. @throws Throwable when the store is down */
    private function liveRun(int $projectId, int $userId, string $hash): ?string
    {
        $existing = $this->gate->inflightRunId($projectId, $userId, $hash);
        if ($existing === null) {
            return null;
        }

        $record = $this->runs->find($existing);
        if ($record !== null && $record['project_id'] === $projectId && $record['user_id'] === $userId
            && in_array($record['status'], [InsightRunStore::QUEUED, InsightRunStore::RUNNING], true)) {
            return $existing;
        }

        // A key that outlived its run (evicted record, finished without releasing): free it and carry on.
        $this->gate->releaseInflight($projectId, $userId, $hash, $existing);

        return null;
    }

    /**
     * Best effort: everything a half-queued run holds is released now, the TTLs clean up anything that fails.
     * Nothing is touched that was never taken.
     */
    private function discard(int $projectId, int $userId, string $runId, string $hash, ?int $projectSlot, ?int $userSlot, bool $created, bool $claimed = true): void
    {
        if ($created) {
            try {
                $this->runs->forget($runId);
            } catch (Throwable) {
                // expires by itself
            }
        }
        $this->gate->releaseSlots($projectId, $userId, $runId, $projectSlot, $userSlot);
        if ($claimed) {
            $this->gate->releaseInflight($projectId, $userId, $hash, $runId);
        }
    }
}
