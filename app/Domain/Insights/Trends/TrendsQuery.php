<?php

namespace App\Domain\Insights\Trends;

use DateTimeImmutable;
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

    /**
     * The inverse of {@see self::canonical()}: rebuilds a definition from its canonical array (what a queued job
     * or a stored insight carries instead of a serialized object). Strict: unknown or missing keys, wrong types
     * or an unknown version throw {@see InvalidTrendsQuery} (never a TypeError), and every value goes through the
     * constructor rules like any other definition, so hostile input cannot build an invalid query.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromCanonical(array $data): self
    {
        self::expectKeys($data, ['v', 'events', 'metric', 'range', 'interval', 'timezone', 'breakdown', 'filters'], 'definition');
        if ($data['v'] !== self::VERSION) {
            throw new InvalidTrendsQuery('Unsupported definition version');
        }
        if (! is_array($data['events']) || ! is_string($data['timezone']) || ! is_string($data['metric']) || ! is_string($data['interval'])) {
            throw new InvalidTrendsQuery('Malformed definition');
        }

        $metric = Metric::tryFrom($data['metric']) ?? throw new InvalidTrendsQuery('Unknown metric');
        $interval = Interval::tryFrom($data['interval']) ?? throw new InvalidTrendsQuery('Unknown interval');

        return new self(
            $data['events'],
            $metric,
            self::rangeFrom($data['range']),
            $interval,
            $data['timezone'],
            self::breakdownFrom($data['breakdown']),
            self::filtersFrom($data['filters']),
        );
    }

    /** @param  array<array-key, mixed>  $data */
    private static function expectKeys(array $data, array $keys, string $what): void
    {
        $actual = array_keys($data);
        sort($actual);
        $expected = $keys;
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidTrendsQuery("Malformed {$what}: unexpected or missing keys");
        }
    }

    private static function rangeFrom(mixed $range): DateRange
    {
        if (! is_array($range)) {
            throw new InvalidTrendsQuery('Malformed date range');
        }
        self::expectKeys($range, ['from', 'to'], 'date range');

        $parse = function (mixed $value): DateTimeImmutable {
            $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value, new DateTimeZone('UTC')) : false;
            if ($date === false || $date->format('Y-m-d\TH:i:s.v\Z') !== $value) {
                throw new InvalidTrendsQuery('Dates must look like 2026-03-01T00:00:00.000Z');
            }

            return $date;
        };

        return new DateRange($parse($range['from']), $parse($range['to']));
    }

    private static function breakdownFrom(mixed $breakdown): ?Breakdown
    {
        if ($breakdown === null) {
            return null;
        }
        if (! is_array($breakdown)) {
            throw new InvalidTrendsQuery('Malformed breakdown');
        }
        self::expectKeys($breakdown, ['column', 'property', 'top_n'], 'breakdown');
        if (! is_int($breakdown['top_n']) || (($breakdown['column'] === null) === ($breakdown['property'] === null))) {
            throw new InvalidTrendsQuery('A breakdown has a top N and exactly one of column and property');
        }

        if ($breakdown['column'] !== null) {
            $column = is_string($breakdown['column']) ? BreakdownColumn::tryFrom($breakdown['column']) : null;

            return Breakdown::byColumn($column ?? throw new InvalidTrendsQuery('Unknown breakdown column'), $breakdown['top_n']);
        }
        if (! is_string($breakdown['property'])) {
            throw new InvalidTrendsQuery('Malformed breakdown property');
        }

        return Breakdown::byProperty($breakdown['property'], $breakdown['top_n']);
    }

    /** @return list<PropertyFilter> */
    private static function filtersFrom(mixed $filters): array
    {
        if (! is_array($filters) || ! array_is_list($filters)) {
            throw new InvalidTrendsQuery('Malformed filters');
        }

        return array_map(function (mixed $filter): PropertyFilter {
            if (! is_array($filter)) {
                throw new InvalidTrendsQuery('Malformed filter');
            }
            self::expectKeys($filter, ['key', 'operator', 'value'], 'filter');
            if (! is_string($filter['key']) || ! is_string($filter['operator']) || ($filter['value'] !== null && ! is_string($filter['value']))) {
                throw new InvalidTrendsQuery('Malformed filter');
            }

            return new PropertyFilter(
                $filter['key'],
                FilterOperator::tryFrom($filter['operator']) ?? throw new InvalidTrendsQuery('Unknown filter operator'),
                $filter['value'],
            );
        }, $filters);
    }

    /** sha256 of the canonical JSON (the cache key component). */
    public function hash(): string
    {
        return hash('sha256', json_encode($this->canonical(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
