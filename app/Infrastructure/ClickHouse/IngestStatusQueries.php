<?php

namespace App\Infrastructure\ClickHouse;

use App\Domain\Capture\IngestStatusFormat;
use Illuminate\Support\Str;

/**
 * Everything ClickHouse knows for `capture:status`, read with the ADMIN client (the read-only user cannot see
 * system.* or events_errors). Cheap by construction: no query scans posthog.events.
 */
final class IngestStatusQueries
{
    /** Query-level ceiling (seconds) so a sick ClickHouse cannot hang the command. */
    private const QUERY_TIME_LIMIT = 20;

    public function __construct(private readonly ClickHouseClient $ch) {}

    /**
     * Everything ClickHouse knows. Any failure here aborts the command (see CaptureStatus::clickHouseFailed()).
     *
     * @param  int  $budgetSeconds  Ceiling for ALL queries of this run together (each one is also capped at QUERY_TIME_LIMIT).
     * @return array<string, mixed>
     */
    public function fetch(int $budgetSeconds): array
    {
        $deadline = hrtime(true) / 1e9 + $budgetSeconds;
        $q = function (string $tag, string $sql) use ($deadline, $budgetSeconds): array {
            $left = (int) floor($deadline - hrtime(true) / 1e9);
            if ($left < 1) {
                throw new \RuntimeException("the time budget of {$budgetSeconds} s for all queries is used up (before query {$tag})");
            }

            return $this->ch->select("/* capture:status {$tag} */ {$sql}", settings: ['max_execution_time' => min(self::QUERY_TIME_LIMIT, $left)]);
        };

        // Cheap by construction: never a scan of posthog.events (it grows without bound and the schedule runs
        // this every minute). Row totals come from the parts metadata, the last-hour figure and the newest
        // insert time from system.part_log (NewPart events, 7 days of history, small); all three are
        // approximations (see the command help).
        $events = $q('events', "SELECT toUnixTimestamp(now()) AS now_ts, sum(rows) AS total, toUnixTimestamp(max(modification_time)) AS newest_ts FROM system.parts WHERE database = 'posthog' AND `table` = 'events' AND active")[0] ?? [];
        try {
            $recent = $q('events_recent', "SELECT sumIf(rows, event_time > now() - INTERVAL 1 HOUR) AS last_hour, toUnixTimestamp(max(event_time)) AS newest_ts FROM system.part_log WHERE database = 'posthog' AND `table` = 'events' AND event_type = 'NewPart' AND event_date >= toDate(now() - INTERVAL 7 DAY)")[0] ?? null;
        } catch (\Throwable) {
            // part_log is optional (a log table can be disabled): the last-hour figure is then unknown, the
            // newest timestamp falls back to the parts metadata. A really sick ClickHouse fails the next query.
            $recent = null;
        }
        $errorsCount = $q('errors_count', 'SELECT count() AS c FROM posthog.events_errors WHERE received_at > now() - INTERVAL 1 HOUR')[0] ?? [];
        $errorsTop = $q('errors_top', 'SELECT left(error, 80) AS err, topic, count() AS c, toUnixTimestamp(max(received_at)) AS newest_ts FROM posthog.events_errors WHERE received_at > now() - INTERVAL 1 HOUR GROUP BY err, topic ORDER BY c DESC, newest_ts DESC LIMIT 5');
        $consumers = $q('consumers', $this->consumersSql('kafka_events'));
        // The ClickHouse consumer of the person_distinct_ids topic (migration 004): the same facts, its own table.
        $personConsumers = $q('person_consumers', $this->consumersSql('kafka_person_distinct_ids'));
        $tooManyParts = $q('too_many_parts', "SELECT value, toUnixTimestamp(last_error_time) AS last_error_ts, last_error_message FROM system.errors WHERE name = 'TOO_MANY_PARTS'")[0] ?? null;
        $disks = $q('disks', 'SELECT name, free_space, total_space FROM system.disks WHERE total_space > 0');
        $parts = $q('parts', "SELECT (SELECT count() FROM system.parts WHERE database = 'posthog' AND `table` = 'events' AND active) AS active, (SELECT toUInt64OrZero(value) FROM system.merge_tree_settings WHERE name = 'max_parts_in_total') AS max_parts")[0] ?? [];
        $mutations = $q('mutations', "SELECT count() AS c FROM system.mutations WHERE database = 'posthog' AND NOT is_done")[0] ?? [];

        $nowTs = (int) ($events['now_ts'] ?? 0);

        return [
            'now_ts' => $nowTs,
            'events_total' => (int) ($events['total'] ?? 0),
            'events_last_hour' => $recent === null ? null : (int) ($recent['last_hour'] ?? 0),
            'events_newest_ts' => IngestStatusFormat::ts($recent['newest_ts'] ?? null) ?? IngestStatusFormat::ts($events['newest_ts'] ?? null),
            'errors_last_hour' => (int) ($errorsCount['c'] ?? 0),
            'errors_top' => array_map(fn (array $r): array => [
                'error' => IngestStatusFormat::oneLine((string) $r['err']),
                'topic' => IngestStatusFormat::oneLine((string) ($r['topic'] ?? '')),
                'count' => (int) $r['c'],
                'newest_ts' => IngestStatusFormat::ts($r['newest_ts'] ?? null),
            ], $errorsTop),
            'too_many_parts' => $tooManyParts === null ? null : [
                'count' => (int) $tooManyParts['value'],
                'last_error_ts' => IngestStatusFormat::ts($tooManyParts['last_error_ts'] ?? null),
                'last_error_message' => Str::limit(IngestStatusFormat::oneLine((string) ($tooManyParts['last_error_message'] ?? '')), 200),
            ],
            'disk' => $this->tightestDisk($disks),
            'parts_active' => isset($parts['active']) ? (int) $parts['active'] : null,
            'parts_max' => isset($parts['max_parts']) ? (int) $parts['max_parts'] : null,
            'pending_mutations' => (int) ($mutations['c'] ?? 0),
            'person' => $this->consumerFacts($personConsumers, $nowTs),
        ] + $this->consumerFacts($consumers, $nowTs);
    }

    /** system.kafka_consumers rows of one Kafka engine table of the `posthog` database (the table name is one of ours, never input). */
    private function consumersSql(string $table): string
    {
        return "SELECT toUnixTimestamp(last_commit_time) AS last_commit_ts, toUnixTimestamp(last_poll_time) AS last_poll_ts, arrayMap(t -> toUnixTimestamp(t), `exceptions.time`) AS exception_times, `exceptions.text` AS exception_texts, `assignments.current_offset` AS offsets FROM system.kafka_consumers WHERE database = 'posthog' AND `table` = '{$table}'";
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function consumerFacts(array $rows, int $nowTs): array
    {
        $commitTs = [];
        $pollTs = [];
        $newest = null;
        $offsetSum = 0;
        foreach ($rows as $row) {
            $commit = IngestStatusFormat::ts($row['last_commit_ts'] ?? null);
            $poll = IngestStatusFormat::ts($row['last_poll_ts'] ?? null);
            if ($commit !== null) {
                $commitTs[] = $commit;
            }
            if ($poll !== null) {
                $pollTs[] = $poll;
            }
            foreach (array_values($row['exception_times'] ?? []) as $i => $time) {
                $time = (int) $time;
                if ($newest === null || $time > $newest['ts']) {
                    $newest = ['ts' => $time, 'message' => Str::limit(IngestStatusFormat::oneLine((string) ($row['exception_texts'][$i] ?? '')), 200)];
                }
            }
            foreach ($row['offsets'] ?? [] as $offset) {
                // Negative values are librdkafka logical offsets (-1001 = not read yet in this process).
                $offsetSum += max(0, (int) $offset);
            }
        }

        return [
            'consumers' => count($rows),
            'newest_exception' => $newest,
            'last_commit_ts' => $commitTs === [] ? null : max($commitTs),
            'last_poll_ts' => $pollTs === [] ? null : max($pollTs),
            'offset_sum' => $offsetSum,
        ];
    }

    /** The disk with the smallest free share (the one that fills up first). */
    private function tightestDisk(array $disks): ?array
    {
        $tightest = null;
        foreach ($disks as $disk) {
            $free = (int) $disk['free_space'];
            $total = (int) $disk['total_space'];
            if ($total > 0 && ($tightest === null || $free / $total < $tightest['free'] / $tightest['total'])) {
                $tightest = ['name' => (string) $disk['name'], 'free' => $free, 'total' => $total];
            }
        }

        return $tightest;
    }
}
