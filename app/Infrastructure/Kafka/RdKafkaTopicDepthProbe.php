<?php

namespace App\Infrastructure\Kafka;

use App\Domain\Capture\DlqDepthProbe;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RuntimeException;

/**
 * Reads the low and high watermarks of every partition of a topic: the messages it holds right now. The handle never
 * subscribes and never commits, so it does not join any group (the group id is only a required setting).
 */
final class RdKafkaTopicDepthProbe implements DlqDepthProbe
{
    public function __construct(
        private readonly string $brokers,
        private readonly string $topic,
        private readonly int $timeoutMs = 3000,
    ) {}

    public function depth(): array
    {
        $conf = new Conf;
        $conf->set('metadata.broker.list', $this->brokers);
        $conf->set('group.id', 'post4-capture-status-depth');
        $conf->set('client.id', 'post4-capture-status');
        $conf->set('enable.auto.commit', 'false');
        $conf->set('log_level', '3');
        $conf->set('socket.timeout.ms', (string) max(1000, $this->timeoutMs));
        $conf->setLogCb(static function (): void {});
        $conf->setErrorCb(static function (): void {});

        $consumer = new KafkaConsumer($conf);

        try {
            $metadata = $consumer->getMetadata(false, $consumer->newTopic($this->topic), $this->timeoutMs);

            $partitions = [];
            foreach ($metadata->getTopics() as $topic) {
                if ($topic->getTopic() !== $this->topic) {
                    continue;
                }
                if ($topic->getErr() !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                    throw new RuntimeException('Kafka topic metadata error: '.rd_kafka_err2str($topic->getErr()));
                }
                foreach ($topic->getPartitions() as $partition) {
                    $partitions[] = $partition->getId();
                }
            }
            if ($partitions === []) {
                throw new RuntimeException("Kafka topic {$this->topic} has no partitions in the metadata");
            }
            sort($partitions);

            $result = [];
            foreach ($partitions as $partition) {
                $low = 0;
                $high = 0;
                $consumer->queryWatermarkOffsets($this->topic, $partition, $low, $high, $this->timeoutMs);
                $result[$partition] = ['low' => (int) $low, 'high' => (int) $high, 'depth' => max(0, (int) $high - (int) $low)];
            }

            return $result;
        } finally {
            unset($consumer);
        }
    }
}
