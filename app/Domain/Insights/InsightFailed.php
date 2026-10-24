<?php

namespace App\Domain\Insights;

use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use RuntimeException;
use Throwable;

/**
 * An insight could not be computed. `getMessage()` IS the safe user-facing message (never server text, SQL
 * or parameter values), so even a careless `$e->getMessage()` in a controller cannot leak. The original
 * exception (raw ClickHouse text, truncated) is the `previous` exception and is for logs only.
 * `isBusy()` is the concurrency limit (an HTTP layer answers 429/503 + Retry-After), `isRetryable()` says
 * the same request may succeed later.
 */
final class InsightFailed extends RuntimeException
{
    public function __construct(public readonly ClickHouseErrorKind $kind, ?Throwable $previous = null)
    {
        parent::__construct($kind->userMessage(), 0, $previous);
    }

    public function isRetryable(): bool
    {
        return $this->kind->isRetryable();
    }

    public function isBusy(): bool
    {
        return $this->kind->isBusy();
    }
}
