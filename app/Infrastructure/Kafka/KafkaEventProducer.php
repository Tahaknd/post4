<?php

namespace App\Infrastructure\Kafka;

use App\Domain\Capture\EventProducer;
use App\Domain\Capture\ProducerUnavailable;
use RdKafka\Conf;
use RdKafka\Producer;

/**
 * Publishes normalized event messages to Kafka and blocks until every message
 * is acknowledged (acks=all, idempotent). produce() flushes synchronously, so
 * nothing stays buffered after it returns.
 */
final class KafkaEventProducer implements EventProducer
{
    private const FLUSH_TIMEOUT_MS = 6000;

    private ?Producer $producer = null;

    private int $failures = 0;

    public function __construct(
        private readonly string $brokers,
        private readonly string $topic,
    ) {}

    public function produce(array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $producer = $this->producer();
        $topic = $producer->newTopic($this->topic);
        $this->failures = 0;

        try {
            foreach ($messages as $message) {
                $topic->produce(
                    RD_KAFKA_PARTITION_UA,
                    0,
                    json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    $message['project_id'].':'.$message['distinct_id'],
                );
                $producer->poll(0);
            }
        } catch (\JsonException $e) {
            // Drain whatever was already queued so nothing leaks into the next call.
            $producer->flush(self::FLUSH_TIMEOUT_MS);

            throw $e;
        }

        $result = $producer->flush(self::FLUSH_TIMEOUT_MS);

        if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR || $this->failures > 0 || $producer->getOutQLen() > 0) {
            throw new ProducerUnavailable('Kafka delivery failed');
        }
    }

    private function producer(): Producer
    {
        if ($this->producer !== null) {
            return $this->producer;
        }

        $conf = new Conf;
        $conf->set('metadata.broker.list', $this->brokers);
        $conf->set('enable.idempotence', 'true');
        $conf->set('acks', 'all');
        $conf->set('compression.codec', 'lz4');
        $conf->set('message.timeout.ms', '5000');
        $conf->setDrMsgCb(function ($kafka, $message): void {
            if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                $this->failures++;
            }
        });

        return $this->producer = new Producer($conf);
    }
}
