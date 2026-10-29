<?php

namespace Tests\Support;

use RdKafka\Exception;
use RdKafka\Producer;
use RdKafka\ProducerTopic;
use RdKafka\TopicConf;

/**
 * An in-memory stand-in for RdKafka\Producer (the constructor of the real one is never called, so no
 * client, thread or socket exists). Delivery reports are queued and handed to the delivery callback the way
 * librdkafka does: only inside poll() / flush().
 *
 * @see FakeRdKafkaProducerTopic
 */
final class FakeRdKafkaProducer extends Producer
{
    /** @var list<string> Names of the calls the producer made, in order (poll, newTopic, produce, flush, purge). */
    public array $calls = [];

    /** @var list<array{topic: string, payload: ?string, key: ?string}> What was produced, in order. */
    public array $messages = [];

    /** @var list<int> Delivery report error codes waiting for the next poll()/flush(). */
    public array $pendingReports = [];

    /** @var int Error code the delivery report of every message produced from now on carries. */
    public int $deliverWith = RD_KAFKA_RESP_ERR_NO_ERROR;

    /** @var ?Exception Thrown by every produce() while set (a full queue, an unknown topic). */
    public ?Exception $produceFails = null;

    /** @var int What flush() returns. */
    public int $flushResult = RD_KAFKA_RESP_ERR_NO_ERROR;

    public function __construct(private readonly \Closure $onDelivery) {}

    /** The report of one message produced earlier whose delivery failed after its batch was already judged. */
    public function leaveStaleFailure(int $error = RD_KAFKA_RESP_ERR__MSG_TIMED_OUT): void
    {
        $this->pendingReports[] = $error;
    }

    /** @internal Called by the fake topic: the message gets its report when the queue is served. */
    public function queueReport(): void
    {
        $this->pendingReports[] = $this->deliverWith;
    }

    public function newTopic(string $topic_name, ?TopicConf $topic_conf = null): ProducerTopic
    {
        $this->calls[] = 'newTopic';

        return new FakeRdKafkaProducerTopic($this, $topic_name);
    }

    public function poll(int $timeout_ms): int
    {
        $this->calls[] = 'poll';

        return $this->serveReports();
    }

    public function flush(int $timeout_ms): int
    {
        $this->calls[] = 'flush';
        $this->serveReports();

        return $this->flushResult;
    }

    public function purge(int $purge_flags): int
    {
        $this->calls[] = 'purge';

        return RD_KAFKA_RESP_ERR_NO_ERROR;
    }

    private function serveReports(): int
    {
        $served = 0;
        foreach ($this->pendingReports as $error) {
            ($this->onDelivery)($this, (object) ['err' => $error]);
            $served++;
        }
        $this->pendingReports = [];

        return $served;
    }
}
