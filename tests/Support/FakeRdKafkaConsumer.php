<?php

namespace Tests\Support;

use RdKafka\Exception;
use RdKafka\KafkaConsumer;
use RdKafka\Message;

/** An in-memory stand-in for RdKafka\KafkaConsumer (the real constructor is never called: no client, no socket). */
final class FakeRdKafkaConsumer extends KafkaConsumer
{
    /** @var list<string> */
    public array $subscribed = [];

    /** @var list<array<int, int>> partition => offset of every commit() */
    public array $commits = [];

    public ?Exception $commitFails = null;

    public bool $closed = false;

    /** @var list<Message> Answers of the next consume() calls; a timeout once the list is empty. */
    public array $messages = [];

    public function __construct() {}

    public function subscribe(array $topics): void
    {
        $this->subscribed = $topics;
    }

    public function consume(int $timeout_ms): Message
    {
        if ($this->messages !== []) {
            return array_shift($this->messages);
        }

        $timeout = new Message;
        $timeout->err = RD_KAFKA_RESP_ERR__TIMED_OUT;

        return $timeout;
    }

    public function commit($message_or_offsets = null): void
    {
        if ($this->commitFails !== null) {
            throw $this->commitFails;
        }

        $offsets = [];
        foreach ($message_or_offsets as $partition) {
            $offsets[$partition->getPartition()] = $partition->getOffset();
        }
        $this->commits[] = $offsets;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
