<?php

namespace App\Console\Commands;

use App\Domain\Capture\DlqDepthProbe;
use App\Domain\Capture\IdentityLagProbe;
use App\Domain\Capture\IngestHealth;
use App\Domain\Capture\IngestStatusFormat;
use App\Domain\Capture\IngestStatusRenderer;
use App\Domain\Capture\IngestStatusReport;
use App\Domain\Capture\KafkaLagProbe;
use App\Http\Controllers\CaptureController;
use App\Infrastructure\ClickHouse\IngestStatusQueries;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

    /** Baseline of the identity consumer's frozen-offsets check (same mechanics as SNAPSHOT_KEY). */
    public const IDENTITY_SNAPSHOT_KEY = 'capture-status:identity';

    /** The depth of the dead letter topic at the previous run: a larger one is a WARN. */
    public const DLQ_SNAPSHOT_KEY = 'capture-status:dlq';

    protected $signature = 'capture:status
        {--json : Print ONE JSON object on stdout and nothing else}
        {--log : Also write ONE summary line (status, max partition lag, disk free percent) to the application log; the schedule uses this}';

    protected $description = 'Shows ingest health (events, events_errors, Kafka consumer and lag, disk, parts). Exit codes: 0 OK, 1 WARN, 2 STALLED or ClickHouse unreachable';

    protected $help = <<<'HELP'
Reports the health of HTTP -> Kafka -> ClickHouse ingestion with the ADMIN ClickHouse client (the
read-only user cannot see system.* or events_errors).

Sections: events (approximate: row counts come from system.parts, so they include duplicates and rows
removed by a lightweight DELETE until the next merge; the last-hour figure and the newest timestamp from
system.part_log; none of them scans posthog.events), events_errors (last hour), the Kafka consumer (system.kafka_consumers), Kafka lag of
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
warnings, notes and errors go to stderr. When ClickHouse cannot be queried the JSON object has exactly the
three keys status ("ERROR"), exit_code (2) and reasons (one message).

--log additionally writes one line to the application log (message "capture:status", fields status,
lag_max_partition, disk_free_percent): info when OK, warning on WARN, error on STALLED or ERROR. The
scheduler runs `capture:status --log` every minute, so `docker compose logs scheduler worker app` shows a
trend and an ingest stall without anybody running the command.
HELP;

    public function handle(IngestStatusQueries $queries, KafkaLagProbe $probe, IdentityLagProbe $identityProbe, DlqDepthProbe $dlqProbe): int
    {
        try {
            $data = $queries->fetch($this->budgetSeconds());
        } catch (\Throwable $e) {
            return $this->clickHouseFailed($e);
        }

        $lag = $this->kafkaLag($probe);
        $identityLag = $this->kafkaLag($identityProbe, 'identity consumer lag');
        $dlq = $this->dlqDepth($dlqProbe);
        $dropped = $this->droppedEvents();
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $previous = $this->readSnapshot();
        $identityPrevious = $this->readSnapshot(self::IDENTITY_SNAPSHOT_KEY);
        $dlqPrevious = $this->readDlqSnapshot();

        $snapshot = IngestStatusReport::healthSnapshot($data, $lag, $previous, $now)
            + ['dropped_by_project' => $dropped['by_project']]
            + IngestStatusReport::identitySnapshot($identityLag, $dlq, $identityPrevious, $dlqPrevious, $now);
        $health = IngestHealth::classify($snapshot, [
            'min_free_percent' => (float) config('capture.status.min_free_percent'),
            'min_free_bytes' => (int) config('capture.status.min_free_bytes'),
        ]);
        $this->writeSnapshot($data['offset_sum'], $lag['total'], $previous, $now);
        $this->writeSnapshot(IngestStatusReport::offsetSum($identityLag['partitions']), $identityLag['total'], $identityPrevious, $now, self::IDENTITY_SNAPSHOT_KEY);
        $this->writeDlqSnapshot($dlq['total']);

        $exit = match ($health['status']) {
            IngestHealth::STATUS_STALLED => self::STALLED,
            IngestHealth::STATUS_WARN => self::WARN,
            default => self::OK,
        };

        $report = IngestStatusReport::build($data, $lag, $dropped, $previous, $now, $health, $exit);
        $report['identity_consumer'] = IngestStatusReport::identitySection(
            $identityLag,
            $dlq,
            (string) config('identity.group'),
            (string) config('capture.events_topic'),
            (string) config('identity.dlq_topic'),
        );

        if ($this->option('log')) {
            $this->logSummary($report);
        }

        if ($this->option('json')) {
            $this->writeRaw(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        } else {
            foreach (IngestStatusRenderer::lines($report) as $line) {
                $this->line($line);
            }
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

    /** Ceiling (seconds) for ALL ClickHouse queries of one run together; the schedule runs the command every minute. */
    private function budgetSeconds(): int
    {
        return max(1, (int) config('capture.status.total_time_budget_seconds'));
    }

    /**
     * Best effort: a failing probe is a stderr note and an unknown lag, never an error.
     *
     * @return array{known: bool, total: ?int, partitions: array<int, array{committed: ?int, high: int, lag: int}>}
     */
    private function kafkaLag(KafkaLagProbe $probe, string $label = 'Kafka lag'): array
    {
        try {
            $partitions = $probe->lag();

            return ['known' => true, 'total' => array_sum(array_column($partitions, 'lag')), 'partitions' => $partitions];
        } catch (\Throwable $e) {
            $this->getOutput()->getErrorStyle()->writeln('NOTE: '.$label.' is unknown: '.OutputFormatter::escape(Str::limit(IngestStatusFormat::oneLine($e->getMessage()), 200)));

            return ['known' => false, 'total' => null, 'partitions' => []];
        }
    }

    /**
     * Best effort, like the lag: a failing probe is a stderr note and an unknown depth.
     *
     * @return array{known: bool, total: ?int, partitions: array<int, array{low: int, high: int, depth: int}>}
     */
    private function dlqDepth(DlqDepthProbe $probe): array
    {
        try {
            $partitions = $probe->depth();

            return ['known' => true, 'total' => array_sum(array_column($partitions, 'depth')), 'partitions' => $partitions];
        } catch (\Throwable $e) {
            $this->getOutput()->getErrorStyle()->writeln('NOTE: the dead letter depth is unknown: '.OutputFormatter::escape(Str::limit(IngestStatusFormat::oneLine($e->getMessage()), 200)));

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
            $this->getOutput()->getErrorStyle()->writeln('NOTE: dropped-event counters are unknown: '.OutputFormatter::escape(Str::limit(IngestStatusFormat::oneLine($e->getMessage()), 200)));

            return ['known' => false, 'total' => null, 'by_project' => []];
        }
    }

    /** @return ?array{offset_sum: int, at: int, lag: ?int} */
    private function readSnapshot(string $key = self::SNAPSHOT_KEY): ?array
    {
        try {
            $value = Cache::get($key);
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
    private function writeSnapshot(int $offsetSum, ?int $lag, ?array $previous, int $now, string $key = self::SNAPSHOT_KEY): void
    {
        $keep = $previous !== null && $lag !== null && $lag > 0
            && ($previous['lag'] ?? 0) > 0 && $previous['offset_sum'] === $offsetSum;

        try {
            Cache::put(
                $key,
                $keep ? $previous : ['offset_sum' => $offsetSum, 'at' => $now, 'lag' => $lag],
                (int) config('capture.status.snapshot_ttl_seconds'),
            );
        } catch (\Throwable) {
            // no comparison next time
        }
    }

    private function readDlqSnapshot(): ?int
    {
        try {
            $value = Cache::get(self::DLQ_SNAPSHOT_KEY);
        } catch (\Throwable) {
            return null;
        }

        return is_int($value) ? $value : null;
    }

    /** An unknown depth leaves the stored one alone, so a Kafka blip does not hide or invent a difference. */
    private function writeDlqSnapshot(?int $depth): void
    {
        if ($depth === null) {
            return;
        }

        try {
            Cache::put(self::DLQ_SNAPSHOT_KEY, $depth, (int) config('capture.status.snapshot_ttl_seconds'));
        } catch (\Throwable) {
            // no comparison next time
        }
    }

    /**
     * The one-line scheduler log entry: fields only, nothing from the payload (no messages, no hosts, no keys).
     *
     * @param  array<string, mixed>  $report
     */
    private function logSummary(array $report): void
    {
        $lags = array_column((array) $report['kafka_lag']['partitions'], 'lag');
        $context = [
            'status' => $report['status'],
            'lag_max_partition' => $report['kafka_lag']['known'] ? ($lags === [] ? 0 : max($lags)) : null,
            'disk_free_percent' => $report['clickhouse']['disk']['free_percent'] ?? null,
        ];

        try {
            match ($report['status']) {
                IngestHealth::STATUS_OK => Log::info('capture:status', $context),
                IngestHealth::STATUS_WARN => Log::warning('capture:status', $context),
                default => Log::error('capture:status', $context),
            };
        } catch (\Throwable) {
            // a broken log channel must not change the exit code
        }
    }

    private function clickHouseFailed(\Throwable $e): int
    {
        $message = 'Cannot query ClickHouse ('.class_basename($e).'): '.Str::limit(IngestStatusFormat::oneLine($e->getMessage()), 300);
        $this->getOutput()->getErrorStyle()->writeln('<fg=red>ERROR: '.OutputFormatter::escape($message).'</>');

        if ($this->option('log')) {
            try {
                Log::error('capture:status', ['status' => 'ERROR', 'lag_max_partition' => null, 'disk_free_percent' => null]);
            } catch (\Throwable) {
                // see logSummary()
            }
        }

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
}
