<?php

namespace App\Domain\Insights\Funnel;

use App\Domain\Insights\Query\Canonical;
use App\Domain\Insights\Query\EventName;
use App\Domain\Insights\Query\PropertyFilter;

/**
 * One step of a funnel: an event name and, optionally, property filters that the event must ALSO satisfy (the same
 * filter model as trends: key, operator equals | not_equals | contains | is_set, value; ANDed; their order is irrelevant
 * and is normalised so equal steps hash equally). An event belongs to a step when its name matches and every filter holds.
 */
final readonly class FunnelStep
{
    public const MAX_FILTERS = 5;

    /** @var list<PropertyFilter> */
    public array $filters;

    /** @param  list<PropertyFilter>  $filters */
    public function __construct(public string $event, array $filters = [])
    {
        EventName::assertValid($event);
        if (! array_is_list($filters) || count($filters) > self::MAX_FILTERS) {
            throw new InvalidFunnelQuery('A step can have at most '.self::MAX_FILTERS.' property filters');
        }
        foreach ($filters as $filter) {
            if (! $filter instanceof PropertyFilter) {
                throw new InvalidFunnelQuery('Filters must be PropertyFilter objects');
            }
        }
        usort($filters, fn (PropertyFilter $a, PropertyFilter $b) => json_encode($a->canonical()) <=> json_encode($b->canonical()));
        $this->filters = $filters;
    }

    /** @return array{event: string, filters: list<array{key: string, operator: string, value: ?string}>} */
    public function canonical(): array
    {
        return ['event' => $this->event, 'filters' => array_map(fn (PropertyFilter $f) => $f->canonical(), $this->filters)];
    }

    /** Strict inverse of {@see self::canonical()}. */
    public static function fromCanonical(mixed $step): self
    {
        if (! is_array($step)) {
            throw new InvalidFunnelQuery('Malformed step');
        }
        Canonical::expectKeys($step, ['event', 'filters'], 'step');
        if (! is_string($step['event'])) {
            throw new InvalidFunnelQuery('Malformed step');
        }

        return new self($step['event'], PropertyFilter::listFromCanonical($step['filters']));
    }
}
