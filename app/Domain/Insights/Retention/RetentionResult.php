<?php

namespace App\Domain\Insights\Retention;

use App\Domain\Insights\InsightFailed;
use App\Domain\Insights\InsightResult;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;

/**
 * The answer of a retention query: one row per cohort period (also the ones without people) with the people who did the
 * target event then (`size`) and, for every period k = 0..periods after it, how many of them did the return event
 * (`values`, with `values[0]` = `size`) and the same as ratios (`rates`, 0..1 rounded to 4 decimals).
 *
 * NULL is not 0. A period that has not STARTED yet when the result is computed (its start is after the current time) is
 * null in `values` and `rates`, so a chart can grey it instead of showing a fall to zero. A cohort of size 0 has null `rates`
 * (there is no rate of nobody) and zeros in `values` for the periods that have started.
 *
 * `averages` is one row over all cohorts: `rates[k]` = people retained in period k over people in the cohorts, summed over the
 * cohorts that have people and whose period k has started (a pooled average: a big cohort weighs more than a small one);
 * `cohorts[k]` is how many cohorts that is. `rates[k]` is null when none qualifies. `start` is the UTC epoch seconds of the
 * period start, `label` the same instant as ISO 8601 with the offset of the zone (unambiguous across daylight saving time).
 */
final readonly class RetentionResult implements InsightResult
{
    /**
     * @param  list<array{start: int, label: string, size: int, values: list<?int>, rates: list<?float>}>  $cohorts
     * @param  array{rates: list<?float>, cohorts: list<int>}  $averages
     */
    public function __construct(
        public string $period,
        public int $periods,
        public string $retentionType,
        public string $timezone,
        public array $cohorts,
        public array $averages,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows  `cohort`, `size`, `retained` as {@see RetentionSqlBuilder} returns them
     * @param  ?DateTimeInterface  $now  the clock of "has this period started"; null is the current time
     *
     * @throws InsightFailed a row that cannot belong to this query (the SQL keeps cohorts 0..n-1 and `periods` counts)
     */
    public static function fromRows(RetentionQuery $query, array $rows, ?DateTimeInterface $now = null): self
    {
        $nowTs = ($now ?? new DateTimeImmutable)->getTimestamp();
        $bad = fn (string $why) => new InsightFailed(ClickHouseErrorKind::Unknown, new RuntimeException('Retention result is malformed: '.$why));

        $found = [];
        foreach ($rows as $row) {
            $cohort = $row['cohort'] ?? null;
            $size = $row['size'] ?? null;
            $retained = $row['retained'] ?? null;
            if (! is_int($cohort) || $cohort < 0 || $cohort >= $query->cohorts || isset($found[$cohort])) {
                throw $bad('cohort outside the table');
            }
            if (! is_int($size) || $size < 0 || ! is_array($retained) || ! array_is_list($retained) || count($retained) !== $query->periods) {
                throw $bad('size or retained counts');
            }
            foreach ($retained as $count) {
                if (! is_int($count) || $count < 0 || $count > $size) {
                    throw $bad('a retained count is outside 0..size');
                }
            }
            $found[$cohort] = ['size' => $size, 'retained' => $retained];
        }

        $zone = $query->zone();
        $cohorts = [];
        for ($i = 0; $i < $query->cohorts; $i++) {
            $size = $found[$i]['size'] ?? 0;
            $values = [];
            $rates = [];
            for ($k = 0; $k <= $query->periods; $k++) {
                // The period k after the cohort period has not started yet: unknown, not zero.
                $future = $query->starts[$i + $k] > $nowTs;
                $value = $future ? null : ($k === 0 ? $size : ($found[$i]['retained'][$k - 1] ?? 0));
                $values[] = $value;
                $rates[] = $value === null || $size === 0 ? null : self::ratio($value, $size);
            }
            $start = $query->starts[$i];
            $cohorts[] = [
                'start' => $start,
                'label' => (new DateTimeImmutable('@'.$start))->setTimezone($zone)->format('c'),
                'size' => $size,
                'values' => $values,
                'rates' => $rates,
            ];
        }

        return new self($query->period->value, $query->periods, $query->type->value, $query->timezone, $cohorts, self::averages($cohorts, $query->periods));
    }

    /**
     * @param  list<array{size: int, values: list<?int>}>  $cohorts
     * @return array{rates: list<?float>, cohorts: list<int>}
     */
    private static function averages(array $cohorts, int $periods): array
    {
        $rates = [];
        $counts = [];
        for ($k = 0; $k <= $periods; $k++) {
            $retained = 0;
            $base = 0;
            $n = 0;
            foreach ($cohorts as $cohort) {
                if ($cohort['size'] > 0 && $cohort['values'][$k] !== null) {
                    $retained += $cohort['values'][$k];
                    $base += $cohort['size'];
                    $n++;
                }
            }
            $rates[] = $base === 0 ? null : self::ratio($retained, $base);
            $counts[] = $n;
        }

        return ['rates' => $rates, 'cohorts' => $counts];
    }

    private static function ratio(int $part, int $whole): float
    {
        return round($part / $whole, 4);
    }

    /** @return array{period: string, periods: int, retention_type: string, timezone: string, cohorts: list<array<string, mixed>>, averages: array{rates: list<?float>, cohorts: list<int>}} */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'periods' => $this->periods,
            'retention_type' => $this->retentionType,
            'timezone' => $this->timezone,
            'cohorts' => $this->cohorts,
            'averages' => $this->averages,
        ];
    }

    /** Rebuilds a cached result; null when the array is not the expected shape (treat as a cache miss). */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || RetentionPeriod::tryFrom((string) ($data['period'] ?? '')) === null
            || ! is_int($data['periods'] ?? null) || $data['periods'] < RetentionQuery::MIN_PERIODS || $data['periods'] > RetentionQuery::MAX_PERIODS
            || RetentionType::tryFrom((string) ($data['retention_type'] ?? '')) === null
            || ! is_string($data['timezone'] ?? null) || ! in_array($data['timezone'], DateTimeZone::listIdentifiers(), true)
            || ! is_array($data['cohorts'] ?? null) || ! array_is_list($data['cohorts']) || $data['cohorts'] === []
            || ! is_array($data['averages'] ?? null)) {
            return null;
        }
        $width = $data['periods'] + 1;
        $cohorts = [];
        foreach ($data['cohorts'] as $cohort) {
            if (! is_array($cohort) || ! is_int($cohort['start'] ?? null) || ! is_string($cohort['label'] ?? null) || ! is_int($cohort['size'] ?? null)
                || ! self::isList($cohort['values'] ?? null, $width, fn ($v) => $v === null || is_int($v))
                || ! self::isList($cohort['rates'] ?? null, $width, fn ($v) => $v === null || is_int($v) || is_float($v))) {
                return null;
            }
            $cohorts[] = [
                'start' => $cohort['start'],
                'label' => $cohort['label'],
                'size' => $cohort['size'],
                'values' => $cohort['values'],
                // A rate that went through JSON may come back as an int (1.0 is written as 1 by some encoders): always a float.
                'rates' => array_map(fn ($r) => $r === null ? null : (float) $r, $cohort['rates']),
            ];
        }
        $averages = $data['averages'];
        if (! self::isList($averages['rates'] ?? null, $width, fn ($v) => $v === null || is_int($v) || is_float($v))
            || ! self::isList($averages['cohorts'] ?? null, $width, fn ($v) => is_int($v))) {
            return null;
        }

        return new self(
            $data['period'],
            $data['periods'],
            $data['retention_type'],
            $data['timezone'],
            $cohorts,
            ['rates' => array_map(fn ($r) => $r === null ? null : (float) $r, $averages['rates']), 'cohorts' => $averages['cohorts']],
        );
    }

    private static function isList(mixed $value, int $width, callable $item): bool
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== $width) {
            return false;
        }
        foreach ($value as $v) {
            if (! $item($v)) {
                return false;
            }
        }

        return true;
    }
}
