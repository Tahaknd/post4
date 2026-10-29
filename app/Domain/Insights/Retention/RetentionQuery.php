<?php

namespace App\Domain\Insights\Retention;

use App\Domain\Insights\InsightQuery;
use App\Domain\Insights\InsightTypes;
use App\Domain\Insights\Query\Canonical;
use App\Domain\Insights\Query\DateRange;
use App\Domain\Insights\Query\EventCondition;
use App\Domain\Insights\Query\InvalidDateSelection;
use App\Domain\Insights\Query\InvalidInsightQuery;
use App\Domain\Insights\Sql\PersonSource;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * A typed, immutable definition of a retention insight: a cohort table. Each row is a COHORT (the people who did the
 * target event in a period) and each column says how many of them did the return event k periods later. It holds NO SQL;
 * {@see RetentionSqlBuilder} turns it into SQL made only of constants and typed `{name:Type}` placeholders.
 *
 * SEMANTICS
 *  - Who is counted: persons, the same way unique users of trends and funnels are (a mapped distinct id is its person, an
 *    unmapped one is itself; see {@see PersonSource}).
 *  - Periods ({@see RetentionPeriod}): calendar days, ISO weeks (Monday first, like trends) or calendar months in the
 *    `timezone` of the definition (the project's zone by default). A period is always whole: events are never cut at the
 *    date range.
 *  - Cohorts: the periods that OVERLAP the date range (`from` inclusive, `to` exclusive): the period that contains `from`
 *    is the first cohort, even when `from` is in the middle of it, and the last cohort is the one that contains the last
 *    instant before `to`. So "the last 30 days" with weekly periods has 5 or 6 cohorts, the first and last of them partly
 *    outside the range, and everything that happened in those periods counts. (A date range is resolved on the server to
 *    whole local days, so equal selections give equal definitions and one cache entry.)
 *  - Cohort of a person ({@see RetentionType}): `first_time`: the period of their first target event inside the queried
 *    window (the cohort periods plus the follow-up periods; nothing before the first cohort period is read), at most one
 *    cohort per person; `recurring`: every cohort period in which they did the target event.
 *  - Retained in period k (1..periods): the person did the RETURN event in the period k periods after their cohort period.
 *    The periods between do not matter (a person active in periods 0 and 3 is retained in period 3 and not in 1 and 2).
 *    Period 0 of a row is the cohort size itself, whatever the return event is. Target and return event may be the same
 *    event (the usual "came back" table) or two different ones (signed up, then purchased).
 *  - Follow-up: events are read up to `periods` periods past the last cohort. Periods that have not STARTED yet (relative to
 *    the time the query runs) are null in the result, not 0 ({@see RetentionResult}); the current period counts what has
 *    happened so far.
 *  - Event conditions ({@see EventCondition}): an event name plus up to {@see EventCondition::MAX_FILTERS} property
 *    filters for each of the two roles.
 *
 * Rules (violations throw {@see InvalidRetentionQuery}, or {@see InvalidInsightQuery} for the shared ones: event names,
 * filters, the date range): {@see self::MIN_PERIODS}..{@see self::MAX_PERIODS} follow-up periods, a known period, type and zone,
 * a range of at most {@see DateRange::MAX_DAYS} days (so at most 367 daily cohorts).
 */
final readonly class RetentionQuery implements InsightQuery
{
    public const MIN_PERIODS = 1;

    public const MAX_PERIODS = 30;

    public const DEFAULT_PERIODS = 8;

    /**
     * Bump when the definition or the result format changes OR the meaning of a result does: it is part of the cache key
     * (hash) and of the canonical array that queued runs carry.
     */
    public const VERSION = 1;

    /** Most cohorts a valid range can have: 366 days, plus one for the period that contains `from`. */
    private const MAX_COHORTS = ['day' => 367, 'week' => 54, 'month' => 13];

    /** The first instants (UTC epoch seconds) of the cohort periods and then of the follow-up periods, one more than needed. */
    public array $starts;

    /** Number of cohorts (rows of the table). */
    public int $cohorts;

    public function __construct(
        public EventCondition $target,
        public EventCondition $returning,
        public RetentionPeriod $period,
        public int $periods,
        public DateRange $range,
        public string $timezone,
        public RetentionType $type = RetentionType::FirstTime,
    ) {
        if ($periods < self::MIN_PERIODS || $periods > self::MAX_PERIODS) {
            throw new InvalidRetentionQuery('The number of follow-up periods must be between '.self::MIN_PERIODS.' and '.self::MAX_PERIODS);
        }
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidRetentionQuery('Unknown time zone');
        }

        $zone = new DateTimeZone($timezone);
        $all = $period->starts($range->from, self::MAX_COHORTS[$period->value] + $periods + 1, $zone);
        $cohorts = count(array_filter($all, fn (int $start) => $start < $range->to->getTimestamp()));
        if ($cohorts < 1 || $cohorts > self::MAX_COHORTS[$period->value]) {
            // Unreachable for a valid DateRange; kept so a changed limit can never produce a table of the wrong size.
            throw new InvalidRetentionQuery('The date range does not fit the retention periods');
        }
        $this->cohorts = $cohorts;
        $this->starts = array_slice($all, 0, $cohorts + $periods + 1);
    }

    public function type(): string
    {
        return InsightTypes::RETENTION;
    }

    /** The zone of the periods. */
    public function zone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone);
    }

    /** The first instant that is read: the start of the first cohort period (before `range->from` when it is mid period). */
    public function readsFrom(): DateTimeImmutable
    {
        return new DateTimeImmutable('@'.$this->starts[0]);
    }

    /** The first instant that is NOT read: the start of the period after the follow-up of the last cohort. */
    public function readsUntil(): DateTimeImmutable
    {
        return new DateTimeImmutable('@'.$this->starts[$this->cohorts + $this->periods]);
    }

    /** The distinct event names of the two roles, in order of first use. @return list<string> */
    public function eventNames(): array
    {
        return array_values(array_unique([$this->target->event, $this->returning->event]));
    }

    /** @return array<string, mixed> */
    public function canonical(): array
    {
        return [
            'v' => self::VERSION,
            'target' => $this->target->canonical(),
            'returning' => $this->returning->canonical(),
            'period' => $this->period->value,
            'periods' => $this->periods,
            'type' => $this->type->value,
            'timezone' => $this->timezone,
            'range' => $this->range->canonical(),
        ];
    }

    /**
     * The inverse of {@see self::canonical()}: strict (unknown or missing keys, wrong types, an unknown version, period or
     * type throw {@see InvalidInsightQuery}, never a TypeError) and every value goes through the constructor rules.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromCanonical(array $data): self
    {
        Canonical::expectKeys($data, ['v', 'target', 'returning', 'period', 'periods', 'type', 'timezone', 'range'], 'definition');
        if ($data['v'] !== self::VERSION) {
            throw new InvalidRetentionQuery('Unsupported definition version');
        }
        if (! is_string($data['period']) || ! is_int($data['periods']) || ! is_string($data['type']) || ! is_string($data['timezone'])) {
            throw new InvalidRetentionQuery('Malformed definition');
        }

        return new self(
            EventCondition::fromCanonical($data['target']),
            EventCondition::fromCanonical($data['returning']),
            RetentionPeriod::tryFrom($data['period']) ?? throw new InvalidRetentionQuery('Unknown retention period'),
            $data['periods'],
            DateRange::fromCanonical($data['range']),
            $data['timezone'],
            RetentionType::tryFrom($data['type']) ?? throw new InvalidRetentionQuery('Unknown retention type'),
        );
    }

    /**
     * Assembles a definition from the INPUT of a client (the shape of the retention API body). Defaults: `period` day,
     * `periods` {@see self::DEFAULT_PERIODS}, `retention_type` first_time, `timezone` = `$defaultZone` (the project's), an
     * event without `filters` has none. The range is whole LOCAL days of the zone ({@see DateRange::fromInput()}); the
     * zone is part of the definition because it decides where the periods begin. The caller validates the SHAPE first; this
     * method still never trusts it.
     *
     * @param  array<array-key, mixed>  $input  keys: target and returning ({event, filters}), period, periods, retention_type, timezone, date_preset, date_from, date_to
     *
     * @throws InvalidDateSelection the date part is unusable
     * @throws InvalidInsightQuery any other rule is violated
     */
    public static function fromInput(array $input, DateTimeZone $defaultZone, DateTimeInterface $now): self
    {
        $timezone = $input['timezone'] ?? $defaultZone->getName();
        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidRetentionQuery('Unknown time zone');
        }
        $range = DateRange::fromInput($input, new DateTimeZone($timezone), $now);

        return self::fromCanonical([
            'v' => self::VERSION,
            'target' => EventCondition::canonicalFromInput($input['target'] ?? null),
            'returning' => EventCondition::canonicalFromInput($input['returning'] ?? null),
            'period' => $input['period'] ?? RetentionPeriod::Day->value,
            'periods' => $input['periods'] ?? self::DEFAULT_PERIODS,
            'type' => $input['retention_type'] ?? RetentionType::FirstTime->value,
            'timezone' => $timezone,
            'range' => $range->canonical(),
        ]);
    }

    /** sha256 of the canonical JSON (the cache key component). */
    public function hash(): string
    {
        return hash('sha256', json_encode($this->canonical(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
