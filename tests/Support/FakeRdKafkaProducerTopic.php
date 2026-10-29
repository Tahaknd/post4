<?php

namespace Tests\Support;

use RdKafka\ProducerTopic;

/** The topic handle of {@see FakeRdKafkaProducer}: producing only queues a delivery report. */
final class FakeRdKafkaProducerTopic extends ProducerTopic
{
    public function __construct(private readonly FakeRdKafkaProducer $producer, private readonly string $name = '') {}

    public function produce(int $partition, int $msgflags, ?string $payload = null, ?string $key = null, ?string $msg_opaque = null): void
    {
        if ($this->producer->produceFails !== null) {
            throw $this->producer->produceFails;
        }

        $this->producer->calls[] = 'produce';
        $this->producer->messages[] = ['topic' => $this->name, 'payload' => $payload, 'key' => $key];
        $this->producer->queueReport();
    }
}
