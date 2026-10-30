<?php

namespace App\Domain\Dashboards\Refresh;

/**
 * What one tick of the scheduled refresh did, as counters (the one log line and the command output are made of it).
 * `skipped` is set when the whole tick was skipped (disabled, ClickHouse unhealthy, queue backed up, queue unavailable).
 */
final class RefreshSummary
{
    public function __construct(
        public ?string $skipped = null,
        /** Dashboards viewed within the window that were looked at. */
        public int $dashboards = 0,
        /** Distinct saved insights on them. */
        public int $insights = 0,
        /** Not run: the state is needs_review. */
        public int $invalid = 0,
        /** Not run: the same canonical query of the same project was already handled in this tick. */
        public int $deduped = 0,
        /** Not run: the dashboard cache already holds a result younger than stale_after_seconds. */
        public int $fresh = 0,
        /** Runs queued (or the same live run found again). */
        public int $queued = 0,
        /** Refreshed from the interactive cache, without a query. */
        public int $fromCache = 0,
        /** Left for the next tick: over the budget of the tick or of the project. */
        public int $capped = 0,
        /** The gate said busy (a project cap): left for the next tick. */
        public int $busy = 0,
        /** The tick stopped early because the run store or queue is down. */
        public bool $aborted = false,
    ) {}

    /** @return array<string, scalar|null> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function line(): string
    {
        if ($this->skipped !== null) {
            return "dashboards:refresh skipped ({$this->skipped})";
        }

        return sprintf(
            'dashboards:refresh dashboards=%d insights=%d queued=%d from_cache=%d fresh=%d deduped=%d invalid=%d capped=%d busy=%d%s',
            $this->dashboards, $this->insights, $this->queued, $this->fromCache, $this->fresh, $this->deduped, $this->invalid, $this->capped, $this->busy,
            $this->aborted ? ' ABORTED (queue or store unavailable)' : '',
        );
    }
}
