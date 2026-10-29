<?php

namespace App\Domain\Insights\Funnel;

use App\Domain\Insights\InsightFailed;
use App\Domain\Insights\InsightResult;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use RuntimeException;

/**
 * The answer of a funnel: how many PERSONS reached each step (at least that step, in order, within the window of a
 * conversion that started inside the date range). Step 1 is the cohort: everyone who started. A person who stopped at
 * step 2 counts in steps 1 and 2 only.
 *
 * Per step: `count`, `dropped_off` (people who reached the previous step and not this one; 0 for the first),
 * `conversion_from_previous` (null for the first step, which has no previous one) and `conversion_from_first` as ratios
 * from 0 to 1 rounded to 4 decimals, null when the denominator is 0 (nobody started: there is no rate, not a rate of 0). Overall: `entrants` (= count of step 1),
 * `converted` (= count of the last step) and `conversion_rate` (= converted / entrants, null without entrants).
 * `window_seconds` and `order` echo the definition so a chart can label itself.
 */
final readonly class FunnelResult implements InsightResult
{
    /**
     * @param  list<array{index: int, event: string, count: int, dropped_off: int, conversion_from_previous: ?float, conversion_from_first: ?float}>  $steps
     */
    public function __construct(
        public array $steps,
        public int $entrants,
        public int $converted,
        public ?float $conversionRate,
        public int $windowSeconds,
        public string $order,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows  `level` (the deepest step a person reached, 1..steps) and `people`
     *                                                  (how many people ended at that level), as {@see FunnelSqlBuilder} returns them
     */
    public static function fromRows(FunnelQuery $query, array $rows): self
    {
        $n = count($query->steps);

        // people per level, then at-least-k counts from the deepest level up
        $atLevel = array_fill(1, $n, 0);
        foreach ($rows as $row) {
            $level = (int) $row['level'];
            if ($level < 1 || $level > $n || ! isset($row['people']) || (int) $row['people'] < 0) {
                // The SQL keeps levels 1..n only: anything else is a bug or a changed contract, never a number to show.
                throw new InsightFailed(ClickHouseErrorKind::Unknown, new RuntimeException('Funnel result has a row outside its levels'));
            }
            $atLevel[$level] += (int) $row['people'];
        }
        $reached = [];
        $running = 0;
        for ($k = $n; $k >= 1; $k--) {
            $running += $atLevel[$k];
            $reached[$k] = $running;
        }

        $steps = [];
        foreach ($query->steps as $i => $step) {
            $k = $i + 1;
            $count = $reached[$k];
            $previous = $k === 1 ? null : $reached[$k - 1];
            $steps[] = [
                'index' => $k,
                'event' => $step->event,
                'count' => $count,
                'dropped_off' => $previous === null ? 0 : $previous - $count,
                'conversion_from_previous' => $previous === null ? null : self::ratio($count, $previous),
                'conversion_from_first' => self::ratio($count, $reached[1]),
            ];
        }

        return new self($steps, $reached[1], $reached[$n], self::ratio($reached[$n], $reached[1]), $query->window->seconds, $query->order->value);
    }

    private static function ratio(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round($part / $whole, 4);
    }

    /** @return array{steps: list<array<string, mixed>>, entrants: int, converted: int, conversion_rate: ?float, window_seconds: int, order: string} */
    public function toArray(): array
    {
        return [
            'steps' => $this->steps,
            'entrants' => $this->entrants,
            'converted' => $this->converted,
            'conversion_rate' => $this->conversionRate,
            'window_seconds' => $this->windowSeconds,
            'order' => $this->order,
        ];
    }

    /** Rebuilds a cached result; null when the array is not the expected shape (treat as a cache miss). */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_array($data['steps'] ?? null) || ! array_is_list($data['steps']) || $data['steps'] === []
            || ! is_int($data['entrants'] ?? null) || ! is_int($data['converted'] ?? null)
            || ! array_key_exists('conversion_rate', $data) || ! self::isRate($data['conversion_rate'])
            || ! is_int($data['window_seconds'] ?? null) || ! is_string($data['order'] ?? null) || FunnelOrder::tryFrom($data['order']) === null) {
            return null;
        }
        foreach ($data['steps'] as $i => $step) {
            if (! is_array($step) || ($step['index'] ?? null) !== $i + 1 || ! is_string($step['event'] ?? null)
                || ! is_int($step['count'] ?? null) || ! is_int($step['dropped_off'] ?? null)
                || ! array_key_exists('conversion_from_previous', $step) || ! array_key_exists('conversion_from_first', $step)
                || ! self::isRate($step['conversion_from_previous']) || ! self::isRate($step['conversion_from_first'])) {
                return null;
            }
        }

        // A rate that went through JSON may come back as an int (1.0 is written as 1 by some encoders): always a float.
        $steps = array_map(fn (array $step) => [
            'index' => $step['index'],
            'event' => $step['event'],
            'count' => $step['count'],
            'dropped_off' => $step['dropped_off'],
            'conversion_from_previous' => $step['conversion_from_previous'] === null ? null : (float) $step['conversion_from_previous'],
            'conversion_from_first' => $step['conversion_from_first'] === null ? null : (float) $step['conversion_from_first'],
        ], $data['steps']);

        return new self(
            $steps,
            $data['entrants'],
            $data['converted'],
            $data['conversion_rate'] === null ? null : (float) $data['conversion_rate'],
            $data['window_seconds'],
            $data['order'],
        );
    }

    private static function isRate(mixed $value): bool
    {
        return $value === null || is_int($value) || is_float($value);
    }
}
