<?php

namespace App\Console\Commands;

use App\Domain\Identity\IdentityResolver;
use App\Infrastructure\Kafka\Identity\ConsumerGaveUp;
use App\Infrastructure\Kafka\Identity\IdentityConsumer;
use App\Infrastructure\Kafka\Identity\IdentityMessageProcessor;
use App\Infrastructure\Kafka\Identity\MessageSource;
use App\Infrastructure\Kafka\Identity\RowWriter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IdentityConsume extends Command
{
    protected $signature = 'identity:consume
        {--group= : Consumer group (default: config identity.group, "identity"). Another group replays the topic from its oldest event}
        {--max-messages= : Stop after consuming this many messages}
        {--stop-after-idle= : Stop when no message arrived for this many seconds (counted from the first partition assignment)}
        {--max-runtime= : Stop after this many seconds}
        {--max-memory-mb= : Stop (exit 0) above this memory use (default: config identity.max_memory_mb)}
        {--summary-interval= : Seconds between the one-line log summaries (default 30)}';

    protected $description = 'Applies $identify, $create_alias and $merge_dangerously events from Kafka to persons and person_distinct_ids (runs until stopped)';

    protected $help = <<<'HELP'
Reads the events topic as consumer group `identity`, applies every `$identify`, `$create_alias` and
`$merge_dangerously` event to Postgres with App\Domain\Identity\IdentityResolver and writes one row per changed
distinct id to the person_distinct_ids topic (shape: App\Infrastructure\Kafka\Identity\PersonDistinctIdRow).

At-least-once: an offset is committed only after the rows were acknowledged by Kafka. Events that can never be
processed go to the events_dlq topic (shape: App\Infrastructure\Kafka\Identity\DeadLetter) and never block the
partition. A retryable failure (lock timeout, lost connection, Kafka down) is repeated with a growing delay and
nothing is committed past the message; after the retry budget the command exits 1 and the supervisor restarts it.

Runs until SIGTERM or SIGINT (the current message is finished and committed first, exit 0). --max-messages,
--stop-after-idle and --max-runtime end it by themselves (exit 0), which is what scripts and tests use.

Logs one summary line every 30 seconds with counts only: never an event, a distinct id or a database message.
Exit codes: 0 stopped as asked, 1 gave up after repeated failures.
HELP;

    public function handle(): int
    {
        $options = $this->options();

        foreach (['max-messages', 'stop-after-idle', 'max-runtime', 'max-memory-mb', 'summary-interval'] as $name) {
            if ($options[$name] !== null && (! ctype_digit((string) $options[$name]) || (int) $options[$name] < 1)) {
                $this->error("--{$name} must be a positive integer.");

                return self::INVALID;
            }
        }

        $group = $options['group'] ?: (string) config('identity.group');
        $int = fn (string $name): ?int => $options[$name] === null ? null : (int) $options[$name];

        $consumer = new IdentityConsumer(
            $this->laravel->make(MessageSource::class, ['group' => $group]),
            $this->laravel->make(RowWriter::class),
            new IdentityMessageProcessor(
                $this->laravel->make(IdentityResolver::class),
                static fn () => DB::purge(),
                static fn () => CarbonImmutable::now(),
            ),
            Log::channel(),
            rowsTopic: (string) config('identity.rows_topic'),
            dlqTopic: (string) config('identity.dlq_topic'),
            maxMessages: $int('max-messages'),
            stopAfterIdleSeconds: $int('stop-after-idle'),
            maxRuntimeSeconds: $int('max-runtime'),
            maxMemoryBytes: ($int('max-memory-mb') ?? (int) config('identity.max_memory_mb')) * 1024 * 1024,
            summaryIntervalSeconds: (float) ($int('summary-interval') ?? config('identity.summary_interval_seconds')),
            maxAttempts: (int) config('identity.retry_attempts'),
            backoffBaseSeconds: (int) config('identity.retry_base_ms') / 1000,
            backoffMaxSeconds: (int) config('identity.retry_max_ms') / 1000,
        );

        // The loop notices the request between messages (the poll timeout is one second), finishes the message it is
        // working on, commits and leaves the group.
        $this->trap([SIGTERM, SIGINT], fn () => $consumer->requestStop());

        try {
            $reason = $consumer->run();
        } catch (ConsumerGaveUp $e) {
            $this->error($e->getMessage().' Nothing past the failing message was committed.');

            return self::FAILURE;
        }

        $this->line(sprintf('identity:consume stopped (%s): %s', $reason, json_encode($consumer->counts(), JSON_THROW_ON_ERROR)));

        return self::SUCCESS;
    }
}
