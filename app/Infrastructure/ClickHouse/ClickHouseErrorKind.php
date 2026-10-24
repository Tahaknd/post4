<?php

namespace App\Infrastructure\ClickHouse;

/**
 * Coarse, UI-safe classification of a ClickHouse failure (see {@see ClickHouseException}). The message of
 * a kind never contains server text, SQL or parameter values: ClickHouse error bodies echo fragments of the
 * query and the rejected values, so the raw text belongs in logs only.
 */
enum ClickHouseErrorKind: string
{
    /** 159 TIMEOUT_EXCEEDED: max_execution_time of the profile was hit. */
    case Timeout = 'timeout';

    /** 241 MEMORY_LIMIT_EXCEEDED. */
    case MemoryLimit = 'memory_limit';

    /**
     * A row or byte limit with overflow mode `throw`: 396 TOO_MANY_ROWS_OR_BYTES (max_result_rows /
     * max_result_bytes), 158 TOO_MANY_ROWS (max_rows_to_read) and 307 TOO_MANY_BYTES (max_bytes_to_read).
     * The numbers were checked against 25.8 with errorCodeToName() and real queries.
     */
    case TooManyRows = 'too_many_rows';

    /** 202 TOO_MANY_SIMULTANEOUS_QUERIES: max_concurrent_queries_for_user reached. */
    case Busy = 'busy';

    /** 452 SETTING_CONSTRAINT_VIOLATION: a setting the profile bounds or pins (a bug in our own query settings). */
    case ConstraintViolation = 'constraint_violation';

    /** 516 AUTHENTICATION_FAILED: wrong or missing credentials (a deployment problem, not the user's). */
    case AuthenticationFailed = 'authentication_failed';

    /** No HTTP answer (connection refused, DNS, reset) after the client gave up retrying. */
    case Unavailable = 'unavailable';

    /** Any other ClickHouse code, an unparseable answer, or a failure without a ClickHouse code. */
    case Unknown = 'unknown';

    /** Error codes this classification knows. */
    private const CODES = [
        159 => self::Timeout,
        241 => self::MemoryLimit,
        396 => self::TooManyRows,
        158 => self::TooManyRows,
        307 => self::TooManyRows,
        202 => self::Busy,
        452 => self::ConstraintViolation,
        516 => self::AuthenticationFailed,
    ];

    public static function fromCode(?int $code): self
    {
        return $code === null ? self::Unknown : (self::CODES[$code] ?? self::Unknown);
    }

    /** Safe to show to a user of the application. */
    public function userMessage(): string
    {
        return match ($this) {
            self::Timeout => 'The query took too long. Try a shorter date range or fewer filters.',
            self::MemoryLimit, self::TooManyRows => 'The query is too large. Try a shorter date range, fewer series or a simpler breakdown.',
            self::Busy => 'The analytics database is busy right now. Try again in a few seconds.',
            self::Unavailable => 'The analytics database is not reachable right now. Try again in a moment.',
            self::ConstraintViolation, self::AuthenticationFailed, self::Unknown => 'The query could not be completed. Try again, and contact an administrator if it keeps failing.',
        };
    }

    /** True when trying the same request again later can reasonably succeed (as opposed to changing it). */
    public function isRetryable(): bool
    {
        return $this === self::Busy || $this === self::Unavailable;
    }

    /** Seconds a client should wait before trying again (Retry-After), null when waiting does not help. */
    public function retryAfterSeconds(): ?int
    {
        return $this === self::Busy ? 3 : null;
    }

    /** True for the concurrency limit (202): an HTTP layer should answer 429/503 with Retry-After. */
    public function isBusy(): bool
    {
        return $this === self::Busy;
    }
}
