<?php

namespace App\Domain\Insights;

/** A result read from the dashboard cache with the Unix time it was computed at (see InsightRunner::cachedForDashboard()). */
final readonly class DashboardResult
{
    public function __construct(public InsightResult $result, public int $computedAt) {}

    public function ageSeconds(?int $now = null): int
    {
        return max(0, ($now ?? now()->getTimestamp()) - $this->computedAt);
    }
}
