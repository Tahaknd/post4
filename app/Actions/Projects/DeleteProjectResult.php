<?php

namespace App\Actions\Projects;

/**
 * What DeleteProject did, so callers tell the truth.
 *
 * `purgeQueued` is set once the purge jobs were really pushed, which happens after the deletion commits: when the
 * action runs inside a caller's own transaction it stays false until that commit (callers on the web and the CLI
 * never nest).
 */
final class DeleteProjectResult
{
    public function __construct(
        public readonly bool $deleted,
        public readonly bool $purgeRequested,
        public bool $purgeQueued = false,
    ) {}

    /** The project is gone but its events were asked to go too and the queueing failed. */
    public function purgeFailedToQueue(): bool
    {
        return $this->deleted && $this->purgeRequested && ! $this->purgeQueued;
    }
}
