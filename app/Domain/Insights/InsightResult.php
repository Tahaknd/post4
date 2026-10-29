<?php

namespace App\Domain\Insights;

/**
 * The answer of an insight query: a plain, JSON-safe array form that the cache, the run record and the API carry
 * (no object is ever stored), and the way back from it.
 */
interface InsightResult
{
    /** @return array<string, mixed> */
    public function toArray(): array;

    /** Rebuilds a stored result; null when the array is not the expected shape (a cache miss, never an error). */
    public static function fromArray(mixed $data): ?self;
}
