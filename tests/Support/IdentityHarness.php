<?php

namespace Tests\Support;

use App\Infrastructure\Kafka\Identity\ConsumedMessage;
use App\Infrastructure\Kafka\Identity\Disposition;
use App\Infrastructure\Kafka\Identity\IdentityConsumer;
use App\Infrastructure\Kafka\Identity\Processed;

/** Wires an IdentityConsumer to the fakes. A fake clock advances 10 ms per poll; sleeping advances it too. */
final class IdentityHarness
{
    public Trace $trace;

    public ScriptedMessageSource $source;

    public RecordingRowWriter $writer;

    public ScriptedProcessor $processor;

    public RecordingLogger $log;

    public IdentityConsumer $consumer;

    public float $now = 1000.0;

    /** @var list<float> */
    public array $sleeps = [];

    public int $memory = 10_000_000;

    /** @var ?\Closure(ConsumedMessage): void Runs inside every process() call. */
    public ?\Closure $onProcess = null;

    /**
     * @param  list<mixed>  $script
     * @param  array<int, list<Processed|\Throwable>>  $outcomes
     * @param  array<string, mixed>  $options  named arguments of IdentityConsumer to override
     */
    public function __construct(array $script, array $outcomes = [], array $options = [])
    {
        $this->trace = new Trace;
        $this->source = new ScriptedMessageSource($script, $this->trace, function (): void {
            $this->now += 0.01;
        });
        $this->writer = new RecordingRowWriter($this->trace);
        $this->processor = new ScriptedProcessor($outcomes, $this->trace, function (ConsumedMessage $message): void {
            if ($this->onProcess !== null) {
                ($this->onProcess)($message);
            }
        });
        $this->log = new RecordingLogger;

        $this->consumer = new IdentityConsumer(...[
            'source' => $this->source,
            'writer' => $this->writer,
            'processor' => $this->processor,
            'log' => $this->log,
            'rowsTopic' => 'person_distinct_ids',
            'dlqTopic' => 'events_dlq',
            'stopAfterIdleSeconds' => 0.03,
            'maxAttempts' => 4,
            'backoffBaseSeconds' => 0.5,
            'backoffMaxSeconds' => 2.0,
            'clock' => fn (): float => $this->now,
            'sleeper' => function (float $seconds): void {
                $this->sleeps[] = $seconds;
                $this->now += $seconds;
            },
            'memoryBytes' => fn (): int => $this->memory,
            'timestamp' => fn (): string => '2026-10-29 12:00:00.000',
            ...$options,
        ]);
    }

    public static function message(int $offset, int $partition = 0, string $payload = '{"event":"$identify"}', ?string $key = '1:alice'): ConsumedMessage
    {
        return new ConsumedMessage('events', $partition, $offset, $key, $payload);
    }

    /** A processed identity event with these rows. */
    public static function applied(Disposition $disposition = Disposition::Created, int $rows = 2): Processed
    {
        $rows = array_map(fn (int $i) => [
            'project_id' => 1, 'distinct_id' => "id-{$i}", 'person_id' => '0194c1e2-0000-7000-8000-000000000001',
            'version' => 0, 'is_deleted' => 0, 'created_at' => '2026-10-29 11:59:59.000',
        ], range(1, $rows));

        return new Processed($disposition, $rows);
    }
}
