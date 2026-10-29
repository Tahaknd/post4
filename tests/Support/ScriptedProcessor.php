<?php

namespace Tests\Support;

use App\Infrastructure\Kafka\Identity\ConsumedMessage;
use App\Infrastructure\Kafka\Identity\Disposition;
use App\Infrastructure\Kafka\Identity\MessageProcessor;
use App\Infrastructure\Kafka\Identity\Processed;

/** Answers per offset: a list of Processed (or Throwable to throw), used in order; NotIdentity when nothing is scripted. */
final class ScriptedProcessor implements MessageProcessor
{
    /** @var list<array{partition: int, offset: int, reconcile: bool}> */
    public array $calls = [];

    /** @param array<int, list<Processed|\Throwable>> $outcomes */
    /** @param ?\Closure(ConsumedMessage): void $hook Runs after the call is traced and before it answers (a signal arriving, memory growing). */
    public function __construct(private array $outcomes, private readonly Trace $trace, public ?\Closure $hook = null) {}

    public function process(ConsumedMessage $message, bool $reconcile = false): Processed
    {
        $this->trace->add("process:{$message->partition}/{$message->offset}");
        $this->calls[] = ['partition' => $message->partition, 'offset' => $message->offset, 'reconcile' => $reconcile];

        if ($this->hook !== null) {
            ($this->hook)($message);
        }

        $queue = $this->outcomes[$message->offset] ?? [];
        $next = array_shift($queue) ?? new Processed(Disposition::NotIdentity);
        $this->outcomes[$message->offset] = $queue;

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
