<?php

namespace App\Domain\Insights\Funnel;

use App\Domain\Insights\Query\Canonical;

/**
 * How long a person has, counted from their first step, to complete the other steps: a duration of whole minutes,
 * hours, days or weeks, between {@see self::MIN_SECONDS} and {@see self::MAX_SECONDS}. The limit bounds how far past
 * the end of the date range the events are read (a conversion that started on the last day may finish inside the
 * window).
 *
 * Stored in seconds and written in the largest unit that divides it exactly, so equal durations are one definition
 * (14 days and 2 weeks have one canonical form, one hash, one cache entry).
 */
final readonly class ConversionWindow
{
    public const MIN_SECONDS = 60;

    /** 90 days. */
    public const MAX_SECONDS = 7776000;

    public const DEFAULT_VALUE = 14;

    public const DEFAULT_UNIT = WindowUnit::Day;

    /** Largest number a client may send (a sanity bound on the input, the real limit is MAX_SECONDS). */
    public const MAX_VALUE = 100000;

    public int $seconds;

    public function __construct(int $value, WindowUnit $unit)
    {
        if ($value < 1 || $value > self::MAX_VALUE) {
            throw new InvalidFunnelQuery('The conversion window must be a whole number of at least 1');
        }

        $this->seconds = $value * $unit->seconds();
        if ($this->seconds < self::MIN_SECONDS || $this->seconds > self::MAX_SECONDS) {
            throw new InvalidFunnelQuery('The conversion window must be between 1 minute and 90 days');
        }
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_VALUE, self::DEFAULT_UNIT);
    }

    /** `{value, unit}` in the largest unit that divides the duration exactly. @return array{value: int, unit: string} */
    public function canonical(): array
    {
        foreach ([WindowUnit::Week, WindowUnit::Day, WindowUnit::Hour, WindowUnit::Minute] as $unit) {
            if ($this->seconds % $unit->seconds() === 0) {
                return ['value' => intdiv($this->seconds, $unit->seconds()), 'unit' => $unit->value];
            }
        }

        throw new \LogicException('A window is a whole number of minutes');
    }

    /** The inverse of {@see self::canonical()}, strict: unknown keys, wrong types or an unknown unit are an InvalidFunnelQuery. */
    public static function fromCanonical(mixed $window): self
    {
        if (! is_array($window)) {
            throw new InvalidFunnelQuery('Malformed conversion window');
        }
        Canonical::expectKeys($window, ['value', 'unit'], 'conversion window');
        if (! is_int($window['value']) || ! is_string($window['unit'])) {
            throw new InvalidFunnelQuery('Malformed conversion window');
        }

        return new self($window['value'], WindowUnit::tryFrom($window['unit']) ?? throw new InvalidFunnelQuery('Unknown conversion window unit'));
    }
}
