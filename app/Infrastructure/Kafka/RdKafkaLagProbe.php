<?php

namespace App\Infrastructure\Kafka;

use App\Domain\Capture\KafkaLagProbe;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\TopicPartition;
use RuntimeException;

/**
 * Reads the committed offsets of a consumer group and the topic's high watermarks with php-rdkafka.
 *
 * The consumer handle uses the real group id only to ask the brokers for that group's committed
 * offsets. It never subscribes or assigns, so it does not join the group (no rebalance for the live
 * ClickHouse consumer) and auto commit is off, so it can never write an offset.
 */
final class RdKafkaLagProbe implements KafkaLagProbe
{
    public function __construct(
        private readonly string $brokers,
        private readonly string $topic,
        private readonly string $group,
        private readonly int $timeoutMs = 3000,
    ) {}

    public function lag(): array
    {
        $conf = new Conf;
        $conf->set('metadata.broker.list', $this->brokers);
        $conf->set('group.id', $this->group);
        $conf->set('client.id', 'post4-capture-status');
        $conf->set('enable.auto.commit', 'false');
        $conf->set('log_level', '3');
        $conf->set('socket.timeout.ms', (string) max(1000, $this->timeoutMs));
        // Keep librdkafka's own diagnostics out of the command's stdout/stderr.
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

            $committed = $consumer->getCommittedOffsets(
                array_map(fn (int $p) => new TopicPartition($this->topic, $p), $partitions),
                $this->timeoutMs,
            );
            $committedByPartition = [];
            foreach ($committed as $tp) {
                // Negative values are librdkafka's logical offsets (e.g. -1001 = nothing committed).
                $committedByPartition[$tp->getPartition()] = $tp->getOffset() >= 0 ? $tp->getOffset() : null;
            }

            $result = [];
            foreach ($partitions as $partition) {
                $low = 0;
                $high = 0;
                $consumer->queryWatermarkOffsets($this->topic, $partition, $low, $high, $this->timeoutMs);
                $done = $committedByPartition[$partition] ?? null;
                // Never committed: the group would start at the log start, so everything retained is lag.
                $result[$partition] = [
                    'committed' => $done,
                    'high' => (int) $high,
                    'lag' => max(0, (int) $high - ($done ?? (int) $low)),
                ];
            }

            return $result;
        } finally {
            // Not subscribed, so closing neither commits nor leaves a group.
            unset($consumer);
        }
    }
}
