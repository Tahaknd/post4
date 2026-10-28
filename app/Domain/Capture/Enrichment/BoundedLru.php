<?php

namespace App\Domain\Capture\Enrichment;

/**
 * A small in-process LRU cache bounded by BOTH the number of entries and an estimated byte size, because an
 * Octane worker lives for hundreds of requests and an unbounded memo keyed by attacker controlled text (a
 * User-Agent) would be a slow memory leak.
 *
 * A PHP array keeps insertion order: a hit is moved to the end (unset + set, O(1)) and the oldest entry is
 * `array_key_first()`. Sizes are estimates the caller passes in; the cache never measures memory itself.
 */
final class BoundedLru
{
    /** Bookkeeping cost of one entry (hash bucket, zval, the size record), added to the caller's estimate. */
    private const ENTRY_OVERHEAD_BYTES = 250;

    /** @var array<string, array{0: mixed, 1: int}> */
    private array $entries = [];

    private int $bytes = 0;

    public function __construct(
        private readonly int $maxEntries,
        private readonly int $maxBytes,
    ) {}

    /** @internal tests only */
    public function has(string $key): bool
    {
        return isset($this->entries[$key]);
    }

    /** The cached value (marking it most recently used), or null on a miss. Values must never be null. */
    public function get(string $key): mixed
    {
        if (! isset($this->entries[$key])) {
            return null;
        }

        $entry = $this->entries[$key];
        unset($this->entries[$key]);
        $this->entries[$key] = $entry;

        return $entry[0];
    }

    /** Stores a value of roughly $valueBytes bytes; an entry that alone exceeds the byte budget is not stored. */
    public function put(string $key, mixed $value, int $valueBytes): void
    {
        $size = strlen($key) + $valueBytes + self::ENTRY_OVERHEAD_BYTES;

        if (isset($this->entries[$key])) {
            $this->bytes -= $this->entries[$key][1];
            unset($this->entries[$key]);
        }

        if ($this->maxEntries < 1 || $size > $this->maxBytes) {
            return;
        }

        $this->entries[$key] = [$value, $size];
        $this->bytes += $size;

        while ($this->entries !== [] && (count($this->entries) > $this->maxEntries || $this->bytes > $this->maxBytes)) {
            $oldest = array_key_first($this->entries);
            $this->bytes -= $this->entries[$oldest][1];
            unset($this->entries[$oldest]);
        }
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    /**
     * @internal tests and diagnostics only
     *
     * @return list<string> keys from the least to the most recently used
     */
    public function keys(): array
    {
        return array_keys($this->entries);
    }
}
