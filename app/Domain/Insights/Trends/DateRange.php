<?php

namespace App\Domain\Insights\Trends;

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
            throw new InvalidTrendsQuery('The end of the date range must be after its start');
        }
        if ($this->to->getTimestamp() - $this->from->getTimestamp() > self::MAX_DAYS * 86400) {
            throw new InvalidTrendsQuery('The date range can be at most '.self::MAX_DAYS.' days');
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
            throw new InvalidTrendsQuery('Days must be valid dates in the form YYYY-MM-DD');
        }

        return new self($first, $last->modify('+1 day'));
    }

    /** @return array{from: string, to: string} */
    public function canonical(): array
    {
        return ['from' => $this->from->format('Y-m-d\TH:i:s.v\Z'), 'to' => $this->to->format('Y-m-d\TH:i:s.v\Z')];
    }
}
