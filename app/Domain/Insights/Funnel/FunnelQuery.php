<?php

namespace App\Domain\Insights\Funnel;

use App\Domain\Insights\InsightQuery;
use App\Domain\Insights\InsightTypes;
use App\Domain\Insights\Query\Canonical;
use App\Domain\Insights\Query\DateRange;
use App\Domain\Insights\Query\InvalidDateSelection;
use App\Domain\Insights\Query\InvalidInsightQuery;
use App\Domain\Insights\Query\PropertyFilter;
use App\Domain\Insights\Sql\PersonSource;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * A typed, immutable definition of a funnel insight: an ordered list of steps that a PERSON completes within a
 * conversion window, counted for the people who START in a date range. It holds NO SQL; {@see FunnelSqlBuilder} turns it
 * into SQL made only of constants and typed `{name:Type}` placeholders (event names, filter keys and values, the instants
 * and the window are all bound parameters).
 *
 * SEMANTICS
 *  - Who is counted: persons, the same way unique users of trends are (a mapped distinct id is its person, an unmapped
 *    one is itself; see {@see PersonSource}).
 *  - Cohort: the people with at least one step 1 event inside the date range (`from` inclusive, `to` exclusive). A step 1
 *    event before the range does not start a conversion, one at or after `to` does not either.
 *  - Conversion: a person reaches step k when, after some step 1 event of the range, steps 2..k follow in order, each
 *    strictly later than the previous one, and step k happens within the window counted from THAT step 1 event. The window
 *    runs past the end of the range: events are read up to `to` + window, so a person who started on the last day still
 *    has the whole window. (Events that have not happened yet cannot count; a funnel whose window reaches into the
 *    future understates the conversion of its latest days until they complete.)
 *  - One event serves one step in one chain: it cannot be step 2 and step 3 of the same conversion, so a funnel may use
 *    the same event name in several steps (page view, page view), and two different events at the very same millisecond
 *    never form a chain (their order is unknown).
 *  - The best chain wins: a person's level is the longest chain over all their step 1 events in the range.
 *  - Order {@see FunnelOrder}: `ordered` (other events may come in between) or `strict_order` (nothing in between).
 *  - The date range, a preset or whole local days of a zone, is resolved by the server (like trends), so the same local
 *    day gives one definition, one hash and one cache entry. The zone is not part of the definition: only the resulting
 *    instants are.
 *
 * Rules (violations throw {@see InvalidFunnelQuery}, or {@see InvalidInsightQuery} for the shared ones: event names,
 * filters, the date range): {@see self::MIN_STEPS}..{@see self::MAX_STEPS} steps, at most {@see self::MAX_FILTERS} filters
 * over all steps, a {@see ConversionWindow}, a range of at most {@see DateRange::MAX_DAYS} days.
 *
 * NOT IN THIS VERSION: breakdowns (which step 1 event a conversion belongs to needs a per-person first-event value and a
 * top-N bucketing pass), time to convert, unordered funnels.
 */
final readonly class FunnelQuery implements InsightQuery
{
    public const MIN_STEPS = 2;

    public const MAX_STEPS = 8;

    /** Over all steps: bounds the size of the SQL and the number of bound parameters. */
    public const MAX_FILTERS = 20;

    /**
     * Bump when the definition or the result format changes OR the meaning of a result does: it is part of the cache key
     * (hash) and of the canonical array that queued runs carry.
     */
    public const VERSION = 1;

    /** @var list<FunnelStep> */
    public array $steps;

    /** @param  list<FunnelStep>  $steps */
    public function __construct(
        array $steps,
        public ConversionWindow $window,
        public DateRange $range,
        public FunnelOrder $order = FunnelOrder::Ordered,
    ) {
        if (! array_is_list($steps) || count($steps) < self::MIN_STEPS || count($steps) > self::MAX_STEPS) {
            throw new InvalidFunnelQuery('A funnel needs between '.self::MIN_STEPS.' and '.self::MAX_STEPS.' steps');
        }
        $filters = 0;
        foreach ($steps as $step) {
            if (! $step instanceof FunnelStep) {
                throw new InvalidFunnelQuery('Steps must be FunnelStep objects');
            }
            $filters += count($step->filters);
        }
        if ($filters > self::MAX_FILTERS) {
            throw new InvalidFunnelQuery('A funnel can have at most '.self::MAX_FILTERS.' property filters over all steps');
        }
        $this->steps = $steps;
    }

    public function type(): string
    {
        return InsightTypes::FUNNEL;
    }

    /** The first instant that is NOT read: the end of the range plus the conversion window. */
    public function readsUntil(): DateTimeImmutable
    {
        return $this->range->to->modify('+'.$this->window->seconds.' seconds');
    }

    /** The distinct event names of the steps, in order of first use. @return list<string> */
    public function eventNames(): array
    {
        return array_values(array_unique(array_map(fn (FunnelStep $s) => $s->event, $this->steps)));
    }

    /** @return array<string, mixed> */
    public function canonical(): array
    {
        return [
            'v' => self::VERSION,
            'steps' => array_map(fn (FunnelStep $s) => $s->canonical(), $this->steps),
            'window' => $this->window->canonical(),
            'range' => $this->range->canonical(),
            'order' => $this->order->value,
        ];
    }

    /**
     * The inverse of {@see self::canonical()}: strict (unknown or missing keys, wrong types, an unknown version or order
     * throw {@see InvalidInsightQuery}, never a TypeError) and every value goes through the constructor rules.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromCanonical(array $data): self
    {
        Canonical::expectKeys($data, ['v', 'steps', 'window', 'range', 'order'], 'definition');
        if ($data['v'] !== self::VERSION) {
            throw new InvalidFunnelQuery('Unsupported definition version');
        }
        if (! is_array($data['steps']) || ! array_is_list($data['steps']) || ! is_string($data['order'])) {
            throw new InvalidFunnelQuery('Malformed definition');
        }

        return new self(
            array_map(FunnelStep::fromCanonical(...), $data['steps']),
            ConversionWindow::fromCanonical($data['window']),
            DateRange::fromCanonical($data['range']),
            FunnelOrder::tryFrom($data['order']) ?? throw new InvalidFunnelQuery('Unknown funnel order'),
        );
    }

    /**
     * Assembles a definition from the INPUT of a client (the shape of the funnel API body). Defaults: `window` 14 days,
     * `order` ordered, `timezone` = `$defaultZone` (the project's), a step without `filters` has none. The range is
     * whole LOCAL days of the zone ({@see DateRange::fromInput()}). The caller validates the SHAPE first; this method
     * still never trusts it.
     *
     * @param  array<array-key, mixed>  $input  keys: steps (list of {event, filters}), window ({value, unit}), order, timezone, date_preset, date_from, date_to
     *
     * @throws InvalidDateSelection the date part is unusable
     * @throws InvalidInsightQuery any other rule is violated
     */
    public static function fromInput(array $input, DateTimeZone $defaultZone, DateTimeInterface $now): self
    {
        $timezone = $input['timezone'] ?? $defaultZone->getName();
        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidFunnelQuery('Unknown time zone');
        }
        $range = DateRange::fromInput($input, new DateTimeZone($timezone), $now);

        $steps = $input['steps'] ?? null;
        if (! is_array($steps) || ! array_is_list($steps)) {
            throw new InvalidFunnelQuery('Malformed steps');
        }

        return self::fromCanonical([
            'v' => self::VERSION,
            'steps' => array_map(
                fn (mixed $s) => is_array($s) ? ['event' => $s['event'] ?? null, 'filters' => PropertyFilter::canonicalListFromInput($s['filters'] ?? null)] : $s,
                $steps,
            ),
            'window' => array_key_exists('window', $input) ? self::windowInput($input['window']) : ConversionWindow::default()->canonical(),
            'range' => $range->canonical(),
            'order' => $input['order'] ?? FunnelOrder::Ordered->value,
        ]);
    }

    private static function windowInput(mixed $window): mixed
    {
        if (! is_array($window)) {
            throw new InvalidFunnelQuery('Malformed conversion window');
        }

        return ['value' => $window['value'] ?? null, 'unit' => $window['unit'] ?? null];
    }

    /** sha256 of the canonical JSON (the cache key component). */
    public function hash(): string
    {
        return hash('sha256', json_encode($this->canonical(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
