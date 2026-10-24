<?php

namespace App\Domain\Insights\Trends;

use App\Domain\Insights\InsightFailed;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Dense result of a trends query: one shared bucket axis and, per series, one value per bucket (empty
 * buckets are 0). `buckets` are UTC epoch seconds of the bucket starts; `labels` are the same instants as
 * ISO 8601 with the project zone offset (unambiguous across DST). Series are in the order of the query's
 * events; within an event, breakdown series are ordered by their summed values (descending, a display aid:
 * for the unique metric the sum over buckets is not a distinct count), then by value, with the "other"
 * series last. The bucket axis is the computed one unless ClickHouse returned a bucket that is not on it, in which
 * case the returned buckets are used as they are (see fromRows). `is_other` / `is_none` mark the Breakdown sentinels so the UI can render words.
 */
final readonly class TrendsResult
{
    /**
     * @param  list<int>  $buckets
     * @param  list<string>  $labels
     * @param  list<array{event: string, breakdown: ?string, is_other: bool, is_none: bool, data: list<int>}>  $series
     */
    public function __construct(
        public string $timezone,
        public array $buckets,
        public array $labels,
        public array $series,
    ) {}

    /** @param  array<int, array<string, mixed>>  $rows  rows of the SQL of {@see TrendsSqlBuilder} */
    public static function fromRows(TrendsQuery $query, array $rows): self
    {
        if (count($rows) > TrendsQuery::MAX_ROWS) {
            // The SQL asks for MAX_ROWS + 1 rows and validation keeps the expected rows at or below MAX_ROWS,
            // so an extra row means the answer was cut off: a bug or a changed cap, never a partial chart.
            throw new InsightFailed(
                ClickHouseErrorKind::TooManyRows,
                new RuntimeException('Trends result has more than '.TrendsQuery::MAX_ROWS.' rows and would be truncated'),
            );
        }

        $cells = [];
        $observed = [];
        foreach ($rows as $row) {
            $bucket = (int) $row['bucket'];
            $key = (string) $row['series']."\0".(array_key_exists('breakdown', $row) ? (string) $row['breakdown'] : '');
            $cells[$key][$bucket] = (int) $row['value'];
            $observed[$bucket] = true;
        }

        // The PHP axis fills empty buckets with 0, but ClickHouse has the last word on where buckets are (the two
        // ship different tzdata, and Australia/Lord_Howe's 30 minute DST shift keeps ClickHouse on the half hour
        // grid after the change). When every returned bucket is on the axis, use the full axis. When any is not,
        // the axis is NOT trusted for this answer: the buckets ClickHouse returned are used as they are (sparse,
        // sorted), so no bucket is invented and none is dropped.
        $axis = $query->buckets();
        $offAxis = array_values(array_diff(array_keys($observed), $axis));
        if ($offAxis === []) {
            $buckets = $axis;
        } else {
            $buckets = array_map('intval', array_keys($observed));
            sort($buckets);
            Log::warning('trends buckets from ClickHouse differ from the computed axis, using the returned buckets', [
                'timezone' => $query->timezone(),
                'interval' => $query->interval->value,
                'off_axis' => count($offAxis),
            ]);
        }

        $series = [];
        foreach ($query->events as $event) {
            if ($query->breakdown === null) {
                $series[] = self::entry($event, null, $cells[$event."\0"] ?? [], $buckets);

                continue;
            }

            $groups = [];
            foreach ($cells as $key => $values) {
                [$cellEvent, $label] = explode("\0", $key, 2);
                if ($cellEvent === $event) {
                    $groups[$label] = $values;
                }
            }
            uksort($groups, fn (string $a, string $b) => [$a === Breakdown::OTHER_LABEL, -array_sum($groups[$a]), $a] <=> [$b === Breakdown::OTHER_LABEL, -array_sum($groups[$b]), $b]);
            foreach ($groups as $label => $values) {
                $series[] = self::entry($event, (string) $label, $values, $buckets);
            }
        }

        $zone = $query->zone;

        return new self(
            $query->timezone(),
            $buckets,
            array_map(fn (int $ts) => (new DateTimeImmutable('@'.$ts))->setTimezone($zone)->format('c'), $buckets),
            $series,
        );
    }

    /**
     * @param  array<int, int>  $values  bucket => value
     * @param  list<int>  $buckets
     * @return array{event: string, breakdown: ?string, is_other: bool, is_none: bool, data: list<int>}
     */
    private static function entry(string $event, ?string $breakdown, array $values, array $buckets): array
    {
        return [
            'event' => $event,
            'breakdown' => $breakdown,
            'is_other' => $breakdown === Breakdown::OTHER_LABEL,
            'is_none' => $breakdown === Breakdown::NONE_LABEL,
            'data' => array_map(fn (int $bucket) => $values[$bucket] ?? 0, $buckets),
        ];
    }

    /** @return array{timezone: string, buckets: list<int>, labels: list<string>, series: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['timezone' => $this->timezone, 'buckets' => $this->buckets, 'labels' => $this->labels, 'series' => $this->series];
    }

    /** Rebuilds a cached result; null when the array is not the expected shape (treat as a cache miss). */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_string($data['timezone'] ?? null) || ! is_array($data['buckets'] ?? null)
            || ! is_array($data['labels'] ?? null) || ! is_array($data['series'] ?? null)
            || ! in_array($data['timezone'], DateTimeZone::listIdentifiers(), true)) {
            return null;
        }
        $width = count($data['buckets']);
        foreach ($data['series'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['event'] ?? null) || ! array_key_exists('breakdown', $entry)
                || ! is_bool($entry['is_other'] ?? null) || ! is_bool($entry['is_none'] ?? null)
                || ! is_array($entry['data'] ?? null) || count($entry['data']) !== $width) {
                return null;
            }
        }

        return new self($data['timezone'], array_values($data['buckets']), array_values($data['labels']), array_values($data['series']));
    }
}
