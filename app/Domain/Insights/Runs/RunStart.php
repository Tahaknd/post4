<?php

namespace App\Domain\Insights\Runs;

use App\Domain\Insights\InsightResult;

/**
 * How asking for an insight run ended ({@see InsightRunStarter}): a fresh cached result, a queued run (new, or the
 * same live one again), or one of the two refusals that happen BEFORE a run exists. The caller turns it into HTTP (or
 * into a log line, for the scheduler).
 */
final readonly class RunStart
{
    public const string CACHED = 'cached';

    public const string QUEUED = 'queued';

    /** A cap of the gate is reached: try again in a few seconds (429). `message` says whose cap. */
    public const string BUSY = 'busy';

    /** The run store or the queue is down (503). */
    public const string UNAVAILABLE = 'unavailable';

    private function __construct(
        public string $outcome,
        public ?InsightResult $result = null,
        public ?string $runId = null,
        public ?string $message = null,
    ) {}

    public static function cached(InsightResult $result): self
    {
        return new self(self::CACHED, result: $result);
    }

    public static function queued(string $runId): self
    {
        return new self(self::QUEUED, runId: $runId);
    }

    public static function busy(string $message): self
    {
        return new self(self::BUSY, message: $message);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }
}
