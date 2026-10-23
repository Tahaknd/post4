<?php

namespace App\Console\Commands;

use App\Domain\Capture\IngestHealth;
use App\Domain\Capture\KafkaLagProbe;
use App\Http\Controllers\CaptureController;
use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

class CaptureStatus extends Command
{
    public const OK = 0;

    public const WARN = 1;

    public const STALLED = 2;

    /** Also the exit code when ClickHouse cannot be queried at all: health cannot be asserted. */
    public const ERROR = 2;

    public const SNAPSHOT_KEY = 'capture-status:last';

    /** Query-level ceiling (seconds) so a sick ClickHouse cannot hang the command. */
    private const QUERY_TIME_LIMIT = 20;

    protected $signature = 'capture:status {--json : Print ONE JSON object on stdout and nothing else}';

    protected $description = 'Shows ingest health (events, events_errors, Kafka consumer and lag, disk, parts). Exit codes: 0 OK, 1 WARN, 2 STALLED or ClickHouse unreachable';

    protected $help = <<<'HELP'
Reports the health of HTTP -> Kafka -> ClickHouse ingestion with the ADMIN ClickHouse client (the
read-only user cannot see system.* or events_errors).

Sections: events, events_errors (last hour), the Kafka consumer (system.kafka_consumers), Kafka lag of
group clickhouse_events (best effort), TOO_MANY_PARTS, disk, active parts, pending mutations, events
dropped by the normaliser per project (last hour), and a comparison with the previous run (the
snapshot is kept in the cache under capture-status:last).

Exit codes (a stable contract for scripts):
  0  OK
  1  WARN (something deserves a look; ingestion still moves)
  2  STALLED, or ClickHouse could not be queried (health cannot be asserted)

STALLED prints one line `INGEST STALLED: <reasons>` to stderr. Whenever the Kafka consumer keeps raising
the same error and stops committing, see the header of docker/clickhouse/users.d/10-ingest-settings.xml.

--json prints exactly one JSON object on stdout (status, exit_code, reasons and one key per section);
warnings, notes and errors go to stderr.
HELP;

    public function handle(ClickHouseClient $clickhouse, KafkaLagProbe $probe): int
    {
        try {
            $data = $this->queryClickHouse($clickhouse);
        } catch (\Throwable $e) {
            return $this->clickHouseFailed($e);
        }

        $lag = $this->kafkaLag($probe);
        $dropped = $this->droppedEvents();
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $previous = $this->readSnapshot();

        $snapshot = $this->healthSnapshot($data, $lag, $previous, $now) + ['dropped_by_project' => $dropped['by_project']];
        $health = IngestHealth::classify($snapshot, [
            'min_free_percent' => (float) config('capture.status.min_free_percent'),
            'min_free_bytes' => (int) config('capture.status.min_free_bytes'),
        ]);
        $this->writeSnapshot($data['offset_sum'], $lag['total'], $previous, $now);

        $exit = match ($health['status']) {
            IngestHealth::STATUS_STALLED => self::STALLED,
            IngestHealth::STATUS_WARN => self::WARN,
            default => self::OK,
        };

        $report = $this->report($data, $lag, $dropped, $previous, $now, $health, $exit);

        if ($this->option('json')) {
            $this->writeRaw(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        } else {
            $this->renderHuman($report);
        }

        $err = $this->getOutput()->getErrorStyle();
        if ($health['status'] === IngestHealth::STATUS_STALLED) {
            $err->writeln('<fg=red;options=bold>INGEST STALLED: '.OutputFormatter::escape(implode('; ', $health['reasons'])).'</>');
        } else {
            foreach ($health['reasons'] as $reason) {
                $err->writeln('WARN: '.OutputFormatter::escape($reason));
            }
        }

        return $exit;
    }

    /**
     * Everything ClickHouse knows. Any failure here aborts the command (see clickHouseFailed()).
     *
     * @return array<string, mixed>
     */
    private function queryClickHouse(ClickHouseClient $ch): array
    {
        $q = fn (string $tag, string $sql): array => $ch->select("/* capture:status {$tag} */ {$sql}", settings: ['max_execution_time' => self::QUERY_TIME_LIMIT]);

        $events = $q('events', 'SELECT toUnixTimestamp(now()) AS now_ts, count() AS total, countIf(created_at > now() - INTERVAL 1 HOUR) AS last_hour, toUnixTimestamp(max(created_at)) AS newest_ts FROM posthog.events')[0] ?? [];
        $errorsCount = $q('errors_count', 'SELECT count() AS c FROM posthog.events_errors WHERE received_at > now() - INTERVAL 1 HOUR')[0] ?? [];
        $errorsTop = $q('errors_top', 'SELECT left(error, 80) AS err, count() AS c, toUnixTimestamp(max(received_at)) AS newest_ts FROM posthog.events_errors WHERE received_at > now() - INTERVAL 1 HOUR GROUP BY err ORDER BY c DESC, newest_ts DESC LIMIT 5');
        $consumers = $q('consumers', "SELECT toUnixTimestamp(last_commit_time) AS last_commit_ts, toUnixTimestamp(last_poll_time) AS last_poll_ts, arrayMap(t -> toUnixTimestamp(t), `exceptions.time`) AS exception_times, `exceptions.text` AS exception_texts, `assignments.current_offset` AS offsets FROM system.kafka_consumers WHERE database = 'posthog' AND `table` = 'kafka_events'");
        $tooManyParts = $q('too_many_parts', "SELECT value, toUnixTimestamp(last_error_time) AS last_error_ts, last_error_message FROM system.errors WHERE name = 'TOO_MANY_PARTS'")[0] ?? null;
        $disks = $q('disks', 'SELECT name, free_space, total_space FROM system.disks WHERE total_space > 0');
        $parts = $q('parts', "SELECT (SELECT count() FROM system.parts WHERE database = 'posthog' AND `table` = 'events' AND active) AS active, (SELECT toUInt64OrZero(value) FROM system.merge_tree_settings WHERE name = 'max_parts_in_total') AS max_parts")[0] ?? [];
        $mutations = $q('mutations', "SELECT count() AS c FROM system.mutations WHERE database = 'posthog' AND NOT is_done")[0] ?? [];

        $nowTs = (int) ($events['now_ts'] ?? 0);

        return [
            'now_ts' => $nowTs,
            'events_total' => (int) ($events['total'] ?? 0),
            'events_last_hour' => (int) ($events['last_hour'] ?? 0),
            'events_newest_ts' => $this->ts($events['newest_ts'] ?? null),
            'errors_last_hour' => (int) ($errorsCount['c'] ?? 0),
            'errors_top' => array_map(fn (array $r): array => [
                'error' => $this->oneLine((string) $r['err']),
                'count' => (int) $r['c'],
                'newest_ts' => $this->ts($r['newest_ts'] ?? null),
            ], $errorsTop),
            'too_many_parts' => $tooManyParts === null ? null : [
                'count' => (int) $tooManyParts['value'],
                'last_error_ts' => $this->ts($tooManyParts['last_error_ts'] ?? null),
                'last_error_message' => Str::limit($this->oneLine((string) ($tooManyParts['last_error_message'] ?? '')), 200),
            ],
            'disk' => $this->tightestDisk($disks),
            'parts_active' => isset($parts['active']) ? (int) $parts['active'] : null,
            'parts_max' => isset($parts['max_parts']) ? (int) $parts['max_parts'] : null,
            'pending_mutations' => (int) ($mutations['c'] ?? 0),
        ] + $this->consumerFacts($consumers, $nowTs);
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
            $commit = $this->ts($row['last_commit_ts'] ?? null);
            $poll = $this->ts($row['last_poll_ts'] ?? null);
            if ($commit !== null) {
                $commitTs[] = $commit;
            }
            if ($poll !== null) {
                $pollTs[] = $poll;
            }
            foreach (array_values($row['exception_times'] ?? []) as $i => $time) {
                $time = (int) $time;
                if ($newest === null || $time > $newest['ts']) {
                    $newest = ['ts' => $time, 'message' => Str::limit($this->oneLine((string) ($row['exception_texts'][$i] ?? '')), 200)];
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

    /**
     * Best effort: a failing probe is a stderr note and an unknown lag, never an error.
     *
     * @return array{known: bool, total: ?int, partitions: array<int, array{committed: ?int, high: int, lag: int}>}
     */
    private function kafkaLag(KafkaLagProbe $probe): array
    {
        try {
            $partitions = $probe->lag();

            return ['known' => true, 'total' => array_sum(array_column($partitions, 'lag')), 'partitions' => $partitions];
        } catch (\Throwable $e) {
            $this->getOutput()->getErrorStyle()->writeln('NOTE: Kafka lag is unknown: '.OutputFormatter::escape(Str::limit($this->oneLine($e->getMessage()), 200)));

            return ['known' => false, 'total' => null, 'partitions' => []];
        }
    }

    /**
     * Events the normaliser rejected, from the per-project hourly counters the capture endpoint keeps (current
     * plus previous hour bucket). Single-tenant scale: one cache read per project. Informational, so a cache or
     * database failure is a stderr note and "unknown", never an error.
     *
     * @return array{known: bool, total: ?int, by_project: array<int, int>}
     */
    private function droppedEvents(): array
    {
        try {
            $now = CarbonImmutable::now('UTC');
            $buckets = [$now->format('YmdH'), $now->subHour()->format('YmdH')];
            $byProject = [];
            foreach (Project::pluck('id') as $id) {
                $sum = 0;
                foreach ($buckets as $bucket) {
                    $sum += (int) Cache::get(CaptureController::DROPPED_KEY_PREFIX.$id.':'.$bucket, 0);
                }
                if ($sum > 0) {
                    $byProject[(int) $id] = $sum;
                }
            }

            return ['known' => true, 'total' => array_sum($byProject), 'by_project' => $byProject];
        } catch (\Throwable $e) {
            $this->getOutput()->getErrorStyle()->writeln('NOTE: dropped-event counters are unknown: '.OutputFormatter::escape(Str::limit($this->oneLine($e->getMessage()), 200)));

            return ['known' => false, 'total' => null, 'by_project' => []];
        }
    }

    /** @return ?array{offset_sum: int, at: int, lag: ?int} */
    private function readSnapshot(): ?array
    {
        try {
            $value = Cache::get(self::SNAPSHOT_KEY);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($value) || ! isset($value['offset_sum'], $value['at'])) {
            return null;
        }

        return ['offset_sum' => (int) $value['offset_sum'], 'at' => (int) $value['at'], 'lag' => isset($value['lag']) ? (int) $value['lag'] : null];
    }

    /**
     * The stored snapshot is the BASELINE of "offsets frozen while lag > 0": it is kept (and only its TTL
     * extended) while that situation persists, otherwise replaced by the current observation. Fail-open:
     * a cache outage only means there is no comparison.
     *
     * @param  ?array{offset_sum: int, at: int, lag: ?int}  $previous
     */
    private function writeSnapshot(int $offsetSum, ?int $lag, ?array $previous, int $now): void
    {
        $keep = $previous !== null && $lag !== null && $lag > 0
            && ($previous['lag'] ?? 0) > 0 && $previous['offset_sum'] === $offsetSum;

        try {
            Cache::put(
                self::SNAPSHOT_KEY,
                $keep ? $previous : ['offset_sum' => $offsetSum, 'at' => $now, 'lag' => $lag],
                (int) config('capture.status.snapshot_ttl_seconds'),
            );
        } catch (\Throwable) {
            // no comparison next time
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{known: bool, total: ?int, partitions: array}  $lag
     * @param  ?array{offset_sum: int, at: int, lag: ?int}  $previous
     * @return array<string, mixed>
     */
    private function healthSnapshot(array $data, array $lag, ?array $previous, int $now): array
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
    private function report(array $d, array $lag, array $dropped, ?array $previous, int $now, array $health, int $exit): array
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
                'newest_created_at' => $this->iso($d['events_newest_ts']),
                'newest_created_age_seconds' => $d['events_newest_ts'] === null ? null : max(0, $clock - $d['events_newest_ts']),
            ],
            'events_errors' => [
                'last_hour' => $d['errors_last_hour'],
                'top' => array_map(fn (array $r): array => [
                    'error' => $r['error'],
                    'count' => $r['count'],
                    'newest_received_at' => $this->iso($r['newest_ts']),
                ], $d['errors_top']),
            ],
            'kafka_consumer' => [
                'consumers' => $d['consumers'],
                'newest_exception' => $exception === null ? null : [
                    'message' => $exception['message'],
                    'time' => $this->iso($exception['ts']),
                    'age_seconds' => max(0, $clock - $exception['ts']),
                ],
                'last_commit_time' => $this->iso($d['last_commit_ts']),
                'last_commit_age_seconds' => $d['last_commit_ts'] === null ? null : max(0, $clock - $d['last_commit_ts']),
                'last_poll_time' => $this->iso($d['last_poll_ts']),
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
                    'last_error_time' => $this->iso($d['too_many_parts']['last_error_ts']),
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

    /** @param  array<string, mixed>  $r */
    private function renderHuman(array $r): void
    {
        $e = $r['events'];
        $this->line("Ingest status: {$r['status']}");
        foreach ($r['reasons'] as $reason) {
            $this->line("  - {$reason}");
        }

        $this->newLine();
        $this->line('Events (posthog.events)');
        $this->line('  total rows:          '.number_format($e['total']));
        $this->line('  created last hour:   '.number_format($e['last_hour']));
        $this->line('  newest created_at:   '.$this->when($e['newest_created_at'], $e['newest_created_age_seconds']));

        $this->newLine();
        $this->line('Pipeline errors (posthog.events_errors, last hour)');
        $this->line('  rows:                '.number_format($r['events_errors']['last_hour']));
        foreach ($r['events_errors']['top'] as $top) {
            $this->line(sprintf('  %5d x  %s  (newest %s)', $top['count'], $top['error'], $top['newest_received_at']));
        }

        $d = $r['dropped'];
        $this->newLine();
        $this->line('Dropped events (rejected by the normaliser, current + previous hour)');
        $this->line('  total:              '.($d['known'] ? number_format($d['last_hour_total']) : 'unknown'));
        foreach ((array) $d['by_project'] as $projectId => $count) {
            $this->line(sprintf('  project #%d:%s%s', $projectId, str_repeat(' ', max(1, 11 - strlen((string) $projectId))), number_format($count)));
        }

        $c = $r['kafka_consumer'];
        $this->newLine();
        $this->line('Kafka consumer (system.kafka_consumers, posthog.kafka_events)');
        $this->line('  consumers:           '.$c['consumers']);
        $this->line('  newest exception:    '.($c['newest_exception'] === null
            ? 'none'
            : sprintf('%s (%d s ago): %s', $c['newest_exception']['time'], $c['newest_exception']['age_seconds'], $c['newest_exception']['message'])));
        $this->line('  last commit:         '.$this->when($c['last_commit_time'], $c['last_commit_age_seconds'], 'never'));
        $this->line('  last poll:           '.$this->when($c['last_poll_time'], $c['last_poll_age_seconds'], 'never'));
        $this->line('  offset sum:          '.number_format($c['offset_sum']));
        $this->line('  vs previous run:     '.$r['previous_run']['summary']);

        $k = $r['kafka_lag'];
        $this->newLine();
        $this->line('Kafka lag (group clickhouse_events, topic events)');
        if (! $k['known']) {
            $this->line('  total lag:           unknown');
        } else {
            $this->line('  total lag:           '.number_format((int) $k['total']));
            foreach ((array) $k['partitions'] as $partition => $p) {
                $this->line(sprintf('  partition %d:         committed %s, high watermark %d, lag %d', $partition, $p['committed'] ?? 'none', $p['high'], $p['lag']));
            }
        }

        $ch = $r['clickhouse'];
        $this->newLine();
        $this->line('ClickHouse');
        $tmp = $ch['too_many_parts'];
        $this->line('  TOO_MANY_PARTS:      '.($tmp === null
            ? 'never raised since the server started'
            : sprintf('%d time(s), last %s: %s', $tmp['count'], $tmp['last_error_time'], $tmp['last_error_message'])));
        $this->line('  disk:                '.($ch['disk'] === null
            ? 'unknown'
            : sprintf('%.1f GiB free of %.1f GiB (%.1f%%)', $ch['disk']['free_bytes'] / 1024 ** 3, $ch['disk']['total_bytes'] / 1024 ** 3, $ch['disk']['free_percent'])));
        $this->line(sprintf('  active parts:        %s (max_parts_in_total %s)', $ch['active_parts'] ?? 'unknown', $ch['max_parts_in_total'] ?? 'unknown'));
        $this->line('  pending mutations:   '.$ch['pending_mutations']);
    }

    private function clickHouseFailed(\Throwable $e): int
    {
        $message = 'Cannot query ClickHouse ('.class_basename($e).'): '.Str::limit($this->oneLine($e->getMessage()), 300);
        $this->getOutput()->getErrorStyle()->writeln('<fg=red>ERROR: '.OutputFormatter::escape($message).'</>');

        if ($this->option('json')) {
            $this->writeRaw(json_encode(['status' => 'ERROR', 'exit_code' => self::ERROR, 'reasons' => [$message]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        }

        return self::ERROR;
    }

    /** Raw: the formatter must never interpret anything in the payload. */
    private function writeRaw(string $text): void
    {
        $this->output->writeln($text, OutputInterface::OUTPUT_RAW);
    }

    private function when(?string $iso, ?int $age, string $none = 'none'): string
    {
        return $iso === null ? $none : "{$iso} ({$age} s ago)";
    }

    private function iso(?int $ts): ?string
    {
        return $ts === null ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /** ClickHouse reports "never" as the epoch (0); that is null here. */
    private function ts(mixed $value): ?int
    {
        $ts = (int) $value;

        return $ts > 0 ? $ts : null;
    }

    private function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
