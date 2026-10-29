<?php

namespace Tests\Support;

use App\Infrastructure\Kafka\Identity\CommitFailed;
use App\Infrastructure\Kafka\Identity\ConsumedMessage;
use App\Infrastructure\Kafka\Identity\MessageSource;

/**
 * A scripted MessageSource. Each poll() consumes script items until one produces a result: a ConsumedMessage is
 * returned, the string 'idle' returns null, ['assign' => [..]] and ['revoke' => [..]] call the rebalance listeners
 * (as librdkafka does, inside poll) and go on, a Closure is called and goes on. An empty script is an idle topic.
 */
final class ScriptedMessageSource implements MessageSource
{
    /** @var list<array<int, int>> every commit() call that succeeded */
    public array $commits = [];

    /** @var list<?CommitFailed> Failures for the next commit() calls, in order (null = succeed). */
    public array $commitOutcomes = [];

    public bool $closed = false;

    private ?\Closure $onAssigned = null;

    private ?\Closure $onRevoked = null;

    /** @param list<mixed> $script */
    public function __construct(private array $script, private readonly Trace $trace, private readonly ?\Closure $tick = null) {}

    public function listen(\Closure $onAssigned, \Closure $onRevoked): void
    {
        $this->onAssigned = $onAssigned;
        $this->onRevoked = $onRevoked;
    }

    public function poll(int $timeoutMs): ?ConsumedMessage
    {
        if ($this->tick !== null) {
            ($this->tick)();
        }

        while ($this->script !== []) {
            $item = array_shift($this->script);

            if ($item instanceof ConsumedMessage) {
                $this->trace->add("poll:{$item->partition}/{$item->offset}");

                return $item;
            }

            if ($item === 'idle') {
                return null;
            }

            if ($item instanceof \Closure) {
                $item($this);
            } elseif (isset($item['assign'])) {
                ($this->onAssigned)($item['assign']);
            } elseif (isset($item['revoke'])) {
                ($this->onRevoked)($item['revoke']);
            }
        }

        return null;
    }

    public function commit(array $nextOffsets): void
    {
        $outcome = array_shift($this->commitOutcomes);

        if ($outcome !== null) {
            $this->trace->add('commit-failed');

            throw $outcome;
        }

        $this->commits[] = $nextOffsets;
        $this->trace->add('commit:'.implode(',', array_map(fn (int $partition, int $offset) => "{$partition}={$offset}", array_keys($nextOffsets), $nextOffsets)));
    }

    public function close(): void
    {
        $this->closed = true;
        $this->trace->add('close');
    }
}
