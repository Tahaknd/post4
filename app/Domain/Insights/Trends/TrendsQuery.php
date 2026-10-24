<?php

namespace App\Domain\Insights\Trends;

use DateTimeZone;

/**
 * A typed, immutable definition of a trends insight: one or more event series counted per time bucket.
 * It holds NO SQL. {@see TrendsSqlBuilder} turns it into SQL made only of class constants, enum fragments
 * and typed `{name:Type}` placeholders; every value here (event names, property keys, filter values, the
 * zone, the dates) is bound as a parameter, so a hostile value cannot change the shape of the query.
 *
 * Rules (violations throw {@see InvalidTrendsQuery}):
 *  - 1..{@see self::MAX_SERIES} distinct, non-empty event names of at most {@see self::MAX_EVENT_LENGTH} characters;
 *  - the date range is required and at most {@see DateRange::MAX_DAYS} days;
 *  - the time zone is a PHP identifier (DateTimeZone::listIdentifiers(), `UTC` included);
 *  - at most {@see self::MAX_FILTERS} property filters (they are ANDed; their order is irrelevant and is
 *    normalised, so equal definitions hash equally);
 *  - rows = buckets x series x (breakdown top N + 1 for the "other" series) must be at most
 *    {@see self::MAX_ROWS}, which is half of the profile's max_result_rows (20000): the result can never
 *    be cut off by `LIMIT` or by the overflow mode (hourly over a year with a breakdown is refused).
 */
final readonly class TrendsQuery
{
    public const MAX_SERIES = 10;

    public const MAX_FILTERS = 10;

    public const MAX_EVENT_LENGTH = 200;

    public const MAX_ROWS = 10000;

    /** Bump when the definition or the result format changes: it is part of the cache key. */
    public const VERSION = 1;

    /** @var list<string> */
    public array $events;

    /** @var list<PropertyFilter> */
    public array $filters;

    public DateTimeZone $zone;

    /**
     * @param  list<string>  $events
     * @param  list<PropertyFilter>  $filters
     */
    public function __construct(
        array $events,
        public Metric $metric,
        public DateRange $range,
        public Interval $interval,
        string $timezone = 'UTC',
        public ?Breakdown $breakdown = null,
        array $filters = [],
    ) {
        if (! array_is_list($events) || $events === [] || count($events) > self::MAX_SERIES) {
            throw new InvalidTrendsQuery('A trends query needs between 1 and '.self::MAX_SERIES.' events');
        }
        foreach ($events as $event) {
            if (! is_string($event) || $event === '' || mb_strlen($event) > self::MAX_EVENT_LENGTH || ! mb_check_encoding($event, 'UTF-8')) {
                throw new InvalidTrendsQuery('Event names must be 1 to '.self::MAX_EVENT_LENGTH.' characters of valid UTF-8');
            }
        }
        if (count(array_unique($events)) !== count($events)) {
            throw new InvalidTrendsQuery('Event names must be distinct');
        }
        $this->events = $events;

        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidTrendsQuery('Unknown time zone');
        }
        $this->zone = new DateTimeZone($timezone);

        if (! array_is_list($filters) || count($filters) > self::MAX_FILTERS) {
            throw new InvalidTrendsQuery('At most '.self::MAX_FILTERS.' property filters are allowed');
        }
        foreach ($filters as $filter) {
            if (! $filter instanceof PropertyFilter) {
                throw new InvalidTrendsQuery('Filters must be PropertyFilter objects');
            }
        }
        usort($filters, fn (PropertyFilter $a, PropertyFilter $b) => json_encode($a->canonical()) <=> json_encode($b->canonical()));
        $this->filters = $filters;

        $rows = count($this->buckets()) * count($events) * ($breakdown === null ? 1 : $breakdown->topN + 1);
        if ($rows > self::MAX_ROWS) {
            throw new InvalidTrendsQuery('The query would return up to '.$rows.' rows (limit '.self::MAX_ROWS.'): use a shorter range, a larger interval, fewer series or a smaller breakdown');
        }
    }

    public function timezone(): string
    {
        return $this->zone->getName();
    }

    /** @return list<int> bucket starts, UTC epoch seconds */
    public function buckets(): array
    {
        return BucketAxis::starts($this->range, $this->interval, $this->zone);
    }

    /**
     * Stable, versioned array form: the same definition always gives the same array (filters sorted, dates
     * in UTC), different definitions never do.
     *
     * @return array<string, mixed>
     */
    public function canonical(): array
    {
        return [
            'v' => self::VERSION,
            'events' => $this->events,
            'metric' => $this->metric->value,
            'range' => $this->range->canonical(),
            'interval' => $this->interval->value,
            'timezone' => $this->timezone(),
            'breakdown' => $this->breakdown?->canonical(),
            'filters' => array_map(fn (PropertyFilter $f) => $f->canonical(), $this->filters),
        ];
    }

    /** sha256 of the canonical JSON (the cache key component). */
    public function hash(): string
    {
        return hash('sha256', json_encode($this->canonical(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
