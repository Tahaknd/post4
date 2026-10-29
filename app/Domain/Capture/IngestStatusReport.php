<?php

namespace App\Domain\Capture;

/**
 * Pure assembly of the `capture:status` data: the snapshot {@see IngestHealth::classify()} judges and the
 * report structure behind both outputs (human and JSON). Nothing here touches ClickHouse, Kafka or the cache.
 */
final class IngestStatusReport
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array{known: bool, total: ?int, partitions: array}  $lag
     * @param  ?array{offset_sum: int, at: int, lag: ?int}  $previous
     * @return array<string, mixed>
     */
    public static function healthSnapshot(array $data, array $lag, ?array $previous, int $now): array
    {
        $exception = $data['newest_exception'];

        return [
            'consumers' => $data['consumers'],
            'exception_message' => $exception['message'] ?? null,
            'exception_age' => $exception === null ? null : max(0, $data['now_ts'] - $exception['ts']),
            'commit_age' => $data['last_commit_ts'] === null ? null : max(0, $data['now_ts'] - $data['last_commit_ts']),
            'poll_age' => $data['last_poll_ts'] === null ? null : max(0, $data['now_ts'] - $data['last_poll_ts']),
            'lag' => $lag['total'],
            'offset_sum' => $data['offset_sum'],
            'previous_offset_sum' => $previous['offset_sum'] ?? null,
            'previous_age' => $previous === null ? null : max(0, $now - $previous['at']),
            'previous_lag' => $previous['lag'] ?? null,
            'errors_last_hour' => $data['errors_last_hour'],
            'disk_free_bytes' => $data['disk']['free'] ?? null,
            'disk_total_bytes' => $data['disk']['total'] ?? null,
            'parts_active' => $data['parts_active'],
            'parts_max' => $data['parts_max'],
            'pending_mutations' => $data['pending_mutations'],
        ];
    }

    /**
     * The one structure behind both outputs (human and JSON).
     *
     * @param  array<string, mixed>  $d
     * @param  array{known: bool, total: ?int, partitions: array}  $lag
     * @param  ?array{offset_sum: int, at: int, lag: ?int}  $previous
     * @param  array{status: string, reasons: list<string>}  $health
     * @return array<string, mixed>
     */
    public static function build(array $d, array $lag, array $dropped, ?array $previous, int $now, array $health, int $exit): array
    {
        $clock = $d['now_ts'];
        $exception = $d['newest_exception'];

        if ($previous === null) {
            $offsets = 'no previous run recorded';
            $trend = 'none';
        } elseif ($d['offset_sum'] > $previous['offset_sum']) {
            $offsets = sprintf('advanced by %d since the previous run %d s ago', $d['offset_sum'] - $previous['offset_sum'], $now - $previous['at']);
            $trend = 'advanced';
        } elseif ($d['offset_sum'] === $previous['offset_sum']) {
            $offsets = sprintf('unchanged for %d s (since the earlier run)', $now - $previous['at']);
            $trend = 'unchanged';
        } else {
            $offsets = sprintf('went DOWN by %d since the previous run (consumer restarted or offsets reset)', $previous['offset_sum'] - $d['offset_sum']);
            $trend = 'reset';
        }

        return [
            'status' => $health['status'],
            'exit_code' => $exit,
            'reasons' => $health['reasons'],
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'events' => [
                'total' => $d['events_total'],
                'last_hour' => $d['events_last_hour'],
                'newest_created_at' => IngestStatusFormat::iso($d['events_newest_ts']),
                'newest_created_age_seconds' => $d['events_newest_ts'] === null ? null : max(0, $clock - $d['events_newest_ts']),
            ],
            'events_errors' => [
                'last_hour' => $d['errors_last_hour'],
                'top' => array_map(fn (array $r): array => [
                    'error' => $r['error'],
                    'topic' => $r['topic'] ?? '',
                    'count' => $r['count'],
                    'newest_received_at' => IngestStatusFormat::iso($r['newest_ts']),
                ], $d['errors_top']),
            ],
            'kafka_consumer' => [
                'consumers' => $d['consumers'],
                'newest_exception' => $exception === null ? null : [
                    'message' => $exception['message'],
                    'time' => IngestStatusFormat::iso($exception['ts']),
                    'age_seconds' => max(0, $clock - $exception['ts']),
                ],
                'last_commit_time' => IngestStatusFormat::iso($d['last_commit_ts']),
                'last_commit_age_seconds' => $d['last_commit_ts'] === null ? null : max(0, $clock - $d['last_commit_ts']),
                'last_poll_time' => IngestStatusFormat::iso($d['last_poll_ts']),
                'last_poll_age_seconds' => $d['last_poll_ts'] === null ? null : max(0, $clock - $d['last_poll_ts']),
                'offset_sum' => $d['offset_sum'],
            ],
            'kafka_lag' => [
                'known' => $lag['known'],
                'total' => $lag['total'],
                'partitions' => (object) $lag['partitions'],
            ],
            'clickhouse' => [
                'too_many_parts' => $d['too_many_parts'] === null ? null : [
                    'count' => $d['too_many_parts']['count'],
                    'last_error_time' => IngestStatusFormat::iso($d['too_many_parts']['last_error_ts']),
                    'last_error_message' => $d['too_many_parts']['last_error_message'],
                ],
                'disk' => $d['disk'] === null ? null : [
                    'name' => $d['disk']['name'],
                    'free_bytes' => $d['disk']['free'],
                    'total_bytes' => $d['disk']['total'],
                    'free_percent' => round($d['disk']['free'] / $d['disk']['total'] * 100, 1),
                ],
                'active_parts' => $d['parts_active'],
                'max_parts_in_total' => $d['parts_max'],
                'pending_mutations' => $d['pending_mutations'],
            ],
            'dropped' => [
                'known' => $dropped['known'],
                'last_hour_total' => $dropped['total'],
                'by_project' => (object) $dropped['by_project'],
            ],
            'previous_run' => [
                'found' => $previous !== null,
                'offsets' => $trend,
                'age_seconds' => $previous === null ? null : $now - $previous['at'],
                'summary' => $offsets,
            ],
        ];
    }

    /**
     * The identity part of the health snapshot (see {@see IngestHealth}): lag and committed-offset sum of group
     * `identity`, the depth of the dead letter topic, and what the previous run saw of both.
     *
     * @param  array{known: bool, total: ?int, partitions: array<int, array{committed: ?int, high: int, lag: int}>}  $lag
     * @param  array{known: bool, total: ?int, partitions: array}  $dlq
     * @param  ?array{offset_sum: int, at: int, lag: ?int}  $previous  the stall baseline of the identity consumer
     * @param  ?int  $previousDlqDepth  the depth of the dead letter topic at the previous run
     * @return array<string, int|null>
     */
    public static function identitySnapshot(array $lag, array $dlq, ?array $previous, ?int $previousDlqDepth, int $now): array
    {
        return [
            'identity_lag' => $lag['total'],
            'identity_offset_sum' => self::offsetSum($lag['partitions']),
            'identity_previous_offset_sum' => $previous['offset_sum'] ?? null,
            'identity_previous_age' => $previous === null ? null : max(0, $now - $previous['at']),
            'identity_previous_lag' => $previous['lag'] ?? null,
            'dlq_depth' => $dlq['total'],
            'dlq_previous_depth' => $previousDlqDepth,
        ];
    }

    /**
     * The `identity_consumer` section of the report: the lag of the group on the events topic and the dead letter topic.
     *
     * @param  array{known: bool, total: ?int, partitions: array<int, array{committed: ?int, high: int, lag: int}>}  $lag
     * @param  array{known: bool, total: ?int, partitions: array<int, array{low: int, high: int, depth: int}>}  $dlq
     * @return array<string, mixed>
     */
    public static function identitySection(array $lag, array $dlq, string $group, string $eventsTopic, string $dlqTopic): array
    {
        return [
            'group' => $group,
            'topic' => $eventsTopic,
            'lag' => [
                'known' => $lag['known'],
                'total' => $lag['total'],
                'partitions' => (object) $lag['partitions'],
            ],
            'dlq' => [
                'topic' => $dlqTopic,
                'known' => $dlq['known'],
                'depth' => $dlq['total'],
                'partitions' => (object) $dlq['partitions'],
            ],
        ];
    }

    /**
     * The person_distinct_ids consumer part of the health snapshot (see {@see IngestHealth}): the same facts as
     * {@see self::healthSnapshot()} gives for the events consumer, under a `person_` prefix, for ClickHouse's own consumer
     * of the person_distinct_ids topic.
     *
     * @param  array<string, mixed>  $person  `$data['person']` of IngestStatusQueries::fetch()
     * @param  array{known: bool, total: ?int, partitions: array}  $lag  lag of group clickhouse_person_distinct_ids
     * @param  ?array{offset_sum: int, at: int, lag: ?int}  $previous  its stall baseline
     * @return array<string, mixed>
     */
    public static function personSnapshot(array $person, int $clock, array $lag, ?array $previous, int $now): array
    {
        $exception = $person['newest_exception'];

        return [
            'person_consumers' => $person['consumers'],
            'person_exception_message' => $exception['message'] ?? null,
            'person_exception_age' => $exception === null ? null : max(0, $clock - $exception['ts']),
            'person_commit_age' => $person['last_commit_ts'] === null ? null : max(0, $clock - $person['last_commit_ts']),
            'person_poll_age' => $person['last_poll_ts'] === null ? null : max(0, $clock - $person['last_poll_ts']),
            'person_lag' => $lag['total'],
            'person_offset_sum' => $person['offset_sum'],
            'person_previous_offset_sum' => $previous['offset_sum'] ?? null,
            'person_previous_age' => $previous === null ? null : max(0, $now - $previous['at']),
            'person_previous_lag' => $previous['lag'] ?? null,
        ];
    }

    /**
     * The `person_consumer` section of the report: ClickHouse's consumer of the person_distinct_ids topic
     * (system.kafka_consumers facts and the lag of its group).
     *
     * @param  array<string, mixed>  $person  `$data['person']` of IngestStatusQueries::fetch()
     * @param  array{known: bool, total: ?int, partitions: array<int, array{committed: ?int, high: int, lag: int}>}  $lag
     * @return array<string, mixed>
     */
    public static function personSection(array $person, int $clock, array $lag, string $table, string $group, string $topic): array
    {
        $exception = $person['newest_exception'];

        return [
            'table' => $table,
            'group' => $group,
            'topic' => $topic,
            'consumers' => $person['consumers'],
            'newest_exception' => $exception === null ? null : [
                'message' => $exception['message'],
                'time' => IngestStatusFormat::iso($exception['ts']),
                'age_seconds' => max(0, $clock - $exception['ts']),
            ],
            'last_commit_time' => IngestStatusFormat::iso($person['last_commit_ts']),
            'last_commit_age_seconds' => $person['last_commit_ts'] === null ? null : max(0, $clock - $person['last_commit_ts']),
            'last_poll_time' => IngestStatusFormat::iso($person['last_poll_ts']),
            'last_poll_age_seconds' => $person['last_poll_ts'] === null ? null : max(0, $clock - $person['last_poll_ts']),
            'offset_sum' => $person['offset_sum'],
            'lag' => [
                'known' => $lag['known'],
                'total' => $lag['total'],
                'partitions' => (object) $lag['partitions'],
            ],
        ];
    }

    /** @param array<int, array{committed: ?int}> $partitions */
    public static function offsetSum(array $partitions): int
    {
        return array_sum(array_map(fn (array $p): int => $p['committed'] ?? 0, $partitions));
    }
}
