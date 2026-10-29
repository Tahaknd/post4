<?php

namespace App\Domain\Capture;

/** Small value formatters shared by the `capture:status` collaborators (all pure). */
final class IngestStatusFormat
{
    /** ClickHouse reports "never" as the epoch (0); that is null here. */
    public static function ts(mixed $value): ?int
    {
        $ts = (int) $value;

        return $ts > 0 ? $ts : null;
    }

    public static function iso(?int $ts): ?string
    {
        return $ts === null ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    public static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    public static function when(?string $iso, ?int $age, string $none = 'none'): string
    {
        return $iso === null ? $none : "{$iso} ({$age} s ago)";
    }
}
