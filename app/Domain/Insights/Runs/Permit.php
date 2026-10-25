<?php

namespace App\Domain\Insights\Runs;

use Illuminate\Contracts\Cache\Lock;
use Throwable;

/** A held {@see InsightGate} lock (or the "open" permit handed out when the store is down). Release is idempotent and never throws. */
final class Permit
{
    private bool $released = false;

    public function __construct(private readonly ?Lock $lock) {}

    /** Fail-open stand-in: nothing is held, nothing to release. */
    public static function open(): self
    {
        return new self(null);
    }

    public function release(): void
    {
        if ($this->released || $this->lock === null) {
            return;
        }
        $this->released = true;

        try {
            $this->lock->release();
        } catch (Throwable) {
            // The TTL frees the lock; a release failure must not turn a finished run into an error.
        }
    }
}
