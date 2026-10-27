<?php

namespace App\Domain\Capture\Enrichment;

use Closure;

/**
 * Lets a log line through at most once per interval, so a failure that repeats on every event of a busy
 * project (a corrupt GeoIP file, a parser bug) writes one line a minute instead of one per event.
 */
final class LogThrottle
{
    private ?int $lastAt = null;

    /** @param  Closure(): int|null  $clock  unix time, injected by tests */
    public function __construct(
        private readonly int $intervalSeconds = 60,
        private readonly ?Closure $clock = null,
    ) {}

    public function allow(): bool
    {
        $now = $this->clock !== null ? ($this->clock)() : time();

        if ($this->lastAt !== null && $now - $this->lastAt < $this->intervalSeconds) {
            return false;
        }

        $this->lastAt = $now;

        return true;
    }
}
