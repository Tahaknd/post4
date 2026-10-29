<?php

namespace App\Domain\Insights\Query;

/**
 * An event name plus optional property filters that the event must ALSO satisfy: the "this kind of event" of an insight
 * that names events in roles (retention's cohort and return event). The same filter model as trends and funnels: key,
 * operator equals | not_equals | contains | is_set, value; ANDed; their order is irrelevant and is normalised so equal
 * conditions hash equally. An event matches when its name is equal and every filter holds.
 */
final readonly class EventCondition
{
    public const MAX_FILTERS = 5;

    /** @var list<PropertyFilter> */
    public array $filters;

    /** @param  list<PropertyFilter>  $filters */
    public function __construct(public string $event, array $filters = [])
    {
        EventName::assertValid($event);
        if (! array_is_list($filters) || count($filters) > self::MAX_FILTERS) {
            throw new InvalidInsightQuery('An event can have at most '.self::MAX_FILTERS.' property filters');
        }
        foreach ($filters as $filter) {
            if (! $filter instanceof PropertyFilter) {
                throw new InvalidInsightQuery('Filters must be PropertyFilter objects');
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
    public static function fromCanonical(mixed $condition): self
    {
        if (! is_array($condition)) {
            throw new InvalidInsightQuery('Malformed event');
        }
        Canonical::expectKeys($condition, ['event', 'filters'], 'event');
        if (! is_string($condition['event'])) {
            throw new InvalidInsightQuery('Malformed event');
        }

        return new self($condition['event'], PropertyFilter::listFromCanonical($condition['filters']));
    }

    /**
     * The condition of a client INPUT `{event, filters?}` as the array {@see self::fromCanonical()} reads (a missing
     * `filters` is an empty list; anything that is not an object is passed on and rejected there).
     */
    public static function canonicalFromInput(mixed $input): mixed
    {
        return is_array($input)
            ? ['event' => $input['event'] ?? null, 'filters' => PropertyFilter::canonicalListFromInput($input['filters'] ?? null)]
            : $input;
    }
}
