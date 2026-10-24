<?php

namespace App\Domain\Insights\Trends;

/**
 * Optional single breakdown: by an allow listed column OR by a property key. Only the {@see self::$topN}
 * most frequent values keep their own series; every other value is merged into one {@see self::OTHER_LABEL}
 * series IN SQL (so the result stays small and `result_overflow_mode = throw` cannot surprise anybody). An
 * empty or missing value is reported as {@see self::NONE_LABEL}.
 */
final readonly class Breakdown
{
    public const DEFAULT_TOP_N = 10;

    public const MAX_TOP_N = 25;

    public const MAX_KEY_LENGTH = 200;

    /** Sentinels passed to ClickHouse as parameters; the UI maps them to words. Unlikely to be real values. */
    public const OTHER_LABEL = '$$_post4_breakdown_other_$$';

    public const NONE_LABEL = '$$_post4_breakdown_none_$$';

    private function __construct(
        public ?BreakdownColumn $column,
        public ?string $propertyKey,
        public int $topN,
    ) {
        if ($topN < 1 || $topN > self::MAX_TOP_N) {
            throw new InvalidTrendsQuery('Breakdown top N must be between 1 and '.self::MAX_TOP_N);
        }
        if ($propertyKey !== null && ($propertyKey === '' || mb_strlen($propertyKey) > self::MAX_KEY_LENGTH || ! mb_check_encoding($propertyKey, 'UTF-8'))) {
            throw new InvalidTrendsQuery('Breakdown property key must be 1 to '.self::MAX_KEY_LENGTH.' characters of valid UTF-8');
        }
    }

    public static function byColumn(BreakdownColumn $column, int $topN = self::DEFAULT_TOP_N): self
    {
        return new self($column, null, $topN);
    }

    public static function byProperty(string $key, int $topN = self::DEFAULT_TOP_N): self
    {
        return new self(null, $key, $topN);
    }

    /** @return array{column: ?string, property: ?string, top_n: int} */
    public function canonical(): array
    {
        return ['column' => $this->column?->value, 'property' => $this->propertyKey, 'top_n' => $this->topN];
    }
}
