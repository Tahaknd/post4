<?php

namespace App\Domain\Capture;

/**
 * Pure classification of the ingest pipeline (HTTP -> Kafka -> ClickHouse) for `capture:status`.
 *
 * STALLED is the wedge described in the header of docker/clickhouse/users.d/10-ingest-settings.xml: the
 * Kafka consumer keeps polling but its materialized view INSERT fails forever, so nothing is committed.
 * Two independent signatures (either one is enough):
 *  - the consumer raised an exception within the last 120 s, has not committed for more than 120 s
 *    (or never) and its last poll is fresher than 60 s;
 *  - Kafka lag is known and > 0 while the consumer offsets did not move since a previous snapshot taken
 *    at least 60 s ago (that snapshot also saw lag > 0, so a burst that just arrived is not a stall).
 * WARN is everything else worth looking at; OK means nothing was found. Missing snapshot keys mean
 * "unknown" and never raise a reason on their own.
 *
 * Snapshot keys (all optional): consumers (int), exception_message (?string), exception_age (?int seconds),
 * commit_age (?int, null = never committed), poll_age (?int), lag (?int, null = unknown), offset_sum (int),
 * previous_offset_sum (?int), previous_age (?int), previous_lag (?int), errors_last_hour (int),
 * disk_free_bytes (?int), disk_total_bytes (?int), parts_active (?int), parts_max (?int),
 * pending_mutations (int), dropped_by_project (array<int, int> drops in the last hour per project id).
 */
final class IngestHealth
{
    public const STATUS_OK = 'OK';

    public const STATUS_WARN = 'WARN';

    public const STATUS_STALLED = 'STALLED';

    public const XML_POINTER = 'see the header of docker/clickhouse/users.d/10-ingest-settings.xml';

    /** @var array<string, int|float> */
    public const DEFAULTS = [
        'stall_exception_window_seconds' => 120,
        'stall_commit_stale_seconds' => 120,
        'stall_poll_fresh_seconds' => 60,
        'stall_min_baseline_age_seconds' => 60,
        'warn_exception_window_seconds' => 3600,
        'min_free_percent' => 10,
        'min_free_bytes' => 2 * 1024 ** 3,
        'parts_warn_ratio' => 0.8,
        'drops_warn_per_project_per_hour' => 1000,
    ];

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, int|float>  $thresholds  Overrides for {@see self::DEFAULTS}.
     * @return array{status: string, reasons: list<string>}
     */
    public static function classify(array $snapshot, array $thresholds = []): array
    {
        $t = $thresholds + self::DEFAULTS;
        $stalled = self::stallReasons($snapshot, $t);
        $warnings = self::warnReasons($snapshot, $t);

        $status = $stalled !== [] ? self::STATUS_STALLED : ($warnings !== [] ? self::STATUS_WARN : self::STATUS_OK);

        return ['status' => $status, 'reasons' => [...$stalled, ...$warnings]];
    }

    /**
     * @param  array<string, mixed>  $s
     * @param  array<string, int|float>  $t
     * @return list<string>
     */
    private static function stallReasons(array $s, array $t): array
    {
        $reasons = [];

        $exceptionAge = self::int($s, 'exception_age');
        $commitAge = self::int($s, 'commit_age');
        $pollAge = self::int($s, 'poll_age');
        if ($exceptionAge !== null && $exceptionAge <= $t['stall_exception_window_seconds']
            && ($commitAge === null || $commitAge > $t['stall_commit_stale_seconds'])
            && $pollAge !== null && $pollAge < $t['stall_poll_fresh_seconds']) {
            $reasons[] = sprintf(
                'the Kafka consumer is stalled: it raised "%s" %d s ago, last committed %s but polled %d s ago, so it keeps retrying a block that never succeeds; %s',
                self::oneLine((string) ($s['exception_message'] ?? 'unknown error')),
                $exceptionAge,
                $commitAge === null ? 'never' : $commitAge.' s ago',
                $pollAge,
                self::XML_POINTER,
            );
        }

        $lag = self::int($s, 'lag');
        $baselineAge = self::int($s, 'previous_age');
        $previousSum = self::int($s, 'previous_offset_sum');
        $previousLag = self::int($s, 'previous_lag');
        if ($lag !== null && $lag > 0
            && $baselineAge !== null && $baselineAge >= $t['stall_min_baseline_age_seconds']
            && $previousSum !== null && $previousSum === self::int($s, 'offset_sum')
            && ($previousLag === null || $previousLag > 0)) {
            $reasons[] = sprintf(
                'Kafka is stalled: lag is %d and the consumer offsets have not moved for %d s (offset sum %d); %s',
                $lag,
                $baselineAge,
                $previousSum,
                self::XML_POINTER,
            );
        }

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $s
     * @param  array<string, int|float>  $t
     * @return list<string>
     */
    private static function warnReasons(array $s, array $t): array
    {
        $reasons = [];

        $errors = self::int($s, 'errors_last_hour') ?? 0;
        if ($errors > 0) {
            $reasons[] = "{$errors} message(s) landed in posthog.events_errors in the last hour";
        }

        $free = self::int($s, 'disk_free_bytes');
        $total = self::int($s, 'disk_total_bytes');
        if ($free !== null && $total !== null && $total > 0) {
            $percent = $free / $total * 100;
            if ($percent < $t['min_free_percent'] || $free < $t['min_free_bytes']) {
                $reasons[] = sprintf('ClickHouse disk is nearly full: %.1f GiB free of %.1f GiB (%.1f%%)', $free / 1024 ** 3, $total / 1024 ** 3, $percent);
            }
        }

        $active = self::int($s, 'parts_active');
        $max = self::int($s, 'parts_max');
        if ($active !== null && $max !== null && $max > 0 && $active > $max * $t['parts_warn_ratio']) {
            $reasons[] = sprintf('posthog.events has %d active parts, over %d%% of max_parts_in_total (%d)', $active, (int) round($t['parts_warn_ratio'] * 100), $max);
        }

        $mutations = self::int($s, 'pending_mutations') ?? 0;
        if ($mutations > 0) {
            $reasons[] = "{$mutations} ClickHouse mutation(s) in posthog are still pending";
        }

        if (self::int($s, 'consumers') === 0) {
            $reasons[] = 'no Kafka consumer is registered for posthog.kafka_events in system.kafka_consumers';
        }

        $exceptionAge = self::int($s, 'exception_age');
        if (self::int($s, 'lag') === null && $exceptionAge !== null && $exceptionAge <= $t['warn_exception_window_seconds']) {
            $reasons[] = sprintf(
                'the Kafka consumer raised "%s" %d s ago and the Kafka lag could not be computed; %s',
                self::oneLine((string) ($s['exception_message'] ?? 'unknown error')),
                $exceptionAge,
                self::XML_POINTER,
            );
        }

        return $reasons;
    }

    /** @param  array<string, mixed>  $s */
    private static function int(array $s, string $key): ?int
    {
        return isset($s[$key]) && is_numeric($s[$key]) ? (int) $s[$key] : null;
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
