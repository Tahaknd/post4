<?php

namespace App\Domain\Insights\Query;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The queried window as two UTC instants, `from` inclusive and `to` EXCLUSIVE, always required (a query
 * without a range would scan the whole project). Longest window: {@see self::MAX_DAYS} days.
 */
final readonly class DateRange
{
    /** Longest allowed window; also keeps hourly buckets (366 x 24 = 8784) under the result row cap. */
    public const MAX_DAYS = 366;

    public DateTimeImmutable $from;

    public DateTimeImmutable $to;

    public function __construct(DateTimeInterface $from, DateTimeInterface $to)
    {
        $utc = new DateTimeZone('UTC');
        $this->from = DateTimeImmutable::createFromInterface($from)->setTimezone($utc);
        $this->to = DateTimeImmutable::createFromInterface($to)->setTimezone($utc);

        if ($this->to <= $this->from) {
            throw new InvalidInsightQuery('The end of the date range must be after its start');
        }
        if ($this->to->getTimestamp() - $this->from->getTimestamp() > self::MAX_DAYS * 86400) {
            throw new InvalidInsightQuery('The date range can be at most '.self::MAX_DAYS.' days');
        }
    }

    /**
     * Whole local days: from the start of `$firstDay` up to and INCLUDING `$lastDay`, in the given zone
     * (the window ends at the start of the day after `$lastDay`). Days are `Y-m-d`.
     */
    public static function localDays(string $firstDay, string $lastDay, DateTimeZone $zone): self
    {
        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $firstDay, $zone);
        $last = DateTimeImmutable::createFromFormat('!Y-m-d', $lastDay, $zone);
        if ($first === false || $last === false || $first->format('Y-m-d') !== $firstDay || $last->format('Y-m-d') !== $lastDay) {
            throw new InvalidInsightQuery('Days must be valid dates in the form YYYY-MM-DD');
        }

        return new self($first, $last->modify('+1 day'));
    }

    /**
     * The date part of a client INPUT: either a {@see DatePreset} resolved against `$now` (`date_preset`) or both days
     * `date_from` + `date_to` (`YYYY-MM-DD`, inclusive), always whole LOCAL days of `$zone`. The same input within one
     * local day gives the same range, hence the same canonical definition, hash and cache key.
     *
     * @param  array<array-key, mixed>  $input
     *
     * @throws InvalidDateSelection the date part is unusable
     */
    public static function fromInput(array $input, DateTimeZone $zone, DateTimeInterface $now): self
    {
        if (isset($input['date_preset'])) {
            $preset = is_string($input['date_preset']) ? DatePreset::tryFrom($input['date_preset']) : null;

            return ($preset ?? throw new InvalidDateSelection('Unknown date preset'))->resolve($now, $zone);
        }
        if (is_string($input['date_from'] ?? null) && is_string($input['date_to'] ?? null)) {
            try {
                return self::localDays($input['date_from'], $input['date_to'], $zone);
            } catch (InvalidInsightQuery $e) {
                throw new InvalidDateSelection($e->getMessage(), previous: $e);
            }
        }

        throw new InvalidDateSelection('A date range needs a preset, or both a first and a last day');
    }

    /**
     * The inverse of {@see self::canonical()} (strict: wrong shape or format is an InvalidInsightQuery, never a TypeError).
     */
    public static function fromCanonical(mixed $range): self
    {
        if (! is_array($range)) {
            throw new InvalidInsightQuery('Malformed date range');
        }
        Canonical::expectKeys($range, ['from', 'to'], 'date range');

        $parse = function (mixed $value): DateTimeImmutable {
            $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value, new DateTimeZone('UTC')) : false;
            if ($date === false || $date->format('Y-m-d\TH:i:s.v\Z') !== $value) {
                throw new InvalidInsightQuery('Dates must look like 2026-03-01T00:00:00.000Z');
            }

            return $date;
        };

        return new self($parse($range['from']), $parse($range['to']));
    }

    /** @return array{from: string, to: string} */
    public function canonical(): array
    {
        return ['from' => $this->from->format('Y-m-d\TH:i:s.v\Z'), 'to' => $this->to->format('Y-m-d\TH:i:s.v\Z')];
    }
}
