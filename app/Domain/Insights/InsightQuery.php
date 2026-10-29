<?php

namespace App\Domain\Insights;

use App\Domain\Insights\Query\InvalidInsightQuery;
use DateTimeInterface;
use DateTimeZone;

/**
 * A typed, immutable definition of one kind of insight. It holds NO SQL: the matching {@see InsightSqlBuilder} turns it
 * into constants-only SQL with typed parameters. Every type is registered in {@see InsightTypes}, which is what the run
 * job, the runner and the HTTP layer go through to stay generic.
 *
 * The three ways in: a client INPUT (`fromInput`, with defaults, date presets and the project's zone), a CANONICAL array
 * (`fromCanonical`, strict, what a queued job and the cache carry instead of a serialized object) and the constructor
 * (every rule). All of them end in the constructor rules, so hostile input can never build an invalid query. The
 * canonical array is stable (the same definition always gives the same array) and VERSIONED: the version is part of the
 * hash, hence of the cache key, so a change in the meaning of a result never serves an old answer.
 */
interface InsightQuery
{
    /** The registered type key ({@see InsightTypes}); part of cache keys, run records and log lines. */
    public function type(): string;

    /** @return array<string, mixed> */
    public function canonical(): array;

    /** sha256 of the canonical JSON: the identity of the definition (cache key component, single flight, inflight key). */
    public function hash(): string;

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws InvalidInsightQuery
     */
    public static function fromCanonical(array $data): self;

    /**
     * @param  array<array-key, mixed>  $input  the validated body of the API
     *
     * @throws InvalidInsightQuery
     */
    public static function fromInput(array $input, DateTimeZone $defaultZone, DateTimeInterface $now): self;
}
