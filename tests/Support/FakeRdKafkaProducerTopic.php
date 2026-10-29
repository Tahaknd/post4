<?php

namespace Tests\Support;

use RdKafka\ProducerTopic;

/** The topic handle of {@see FakeRdKafkaProducer}: producing only queues a delivery report. */
final class FakeRdKafkaProducerTopic extends ProducerTopic
{
    public function __construct(private readonly FakeRdKafkaProducer $producer) {}

    public function produce(int $partition, int $msgflags, ?string $payload = null, ?string $key = null, ?string $msg_opaque = null): void
    {
        $this->producer->calls[] = 'produce';
        $this->producer->queueReport();
    }
}
