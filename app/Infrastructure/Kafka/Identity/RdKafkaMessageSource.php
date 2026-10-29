<?php

namespace App\Infrastructure\Kafka\Identity;

use Illuminate\Support\Facades\Log;
use RdKafka\Conf;
use RdKafka\Exception;
use RdKafka\KafkaConsumer;
use RdKafka\TopicPartition;

/**
 * The events topic as a member of a consumer group, over php-rdkafka's high-level consumer.
 *
 * Offsets are committed ONLY by commit() with explicit partitions and offsets (auto commit and the offset store are
 * off), so the position librdkafka has read up to never decides what is committed. A new group starts at the
 * earliest offset (the events of a project may predate the consumer). Partition assignment is the default eager
 * one; the rebalance callback assigns what the broker hands out and calls the listeners of listen().
 */
final class RdKafkaMessageSource implements MessageSource
{
    private ?KafkaConsumer $consumer = null;

    /** @var ?\Closure(list<int>): void */
    private ?\Closure $onAssigned = null;

    /** @var ?\Closure(list<int>): void */
    private ?\Closure $onRevoked = null;

    /**
     * @param  ?\Closure(Conf): KafkaConsumer  $consumerFactory  Test seam: builds the client from the finished conf.
     */
    public function __construct(
        private readonly string $brokers,
        private readonly string $topic,
        private readonly string $group,
        private readonly ?\Closure $consumerFactory = null,
    ) {}

    public function listen(\Closure $onAssigned, \Closure $onRevoked): void
    {
        $this->onAssigned = $onAssigned;
        $this->onRevoked = $onRevoked;

        $conf = new Conf;
        $conf->set('metadata.broker.list', $this->brokers);
        $conf->set('group.id', $this->group);
        $conf->set('client.id', 'post4-identity');
        $conf->set('enable.auto.commit', 'false');
        $conf->set('enable.auto.offset.store', 'false');
        $conf->set('auto.offset.reset', 'earliest');
        // One message can wait for its retries for minutes; a member that does not poll for max.poll.interval.ms is
        // thrown out of the group. The retry budget of IdentityConsumer ends well before this.
        $conf->set('max.poll.interval.ms', '600000');
        $conf->set('session.timeout.ms', '30000');
        // The consumer works through one message at a time: do not let librdkafka prefetch 64 MB per partition.
        $conf->set('queued.max.messages.kbytes', '8192');
        $conf->set('socket.keepalive.enable', 'true');
        $conf->set('log.connection.close', 'false');
        $conf->setLogCb(function ($kafka, int $level, string $facility, string $message): void {
            Log::log($level <= 4 ? 'warning' : 'debug', 'librdkafka '.$facility.': '.$message);
        });
        $conf->setRebalanceCb(function (KafkaConsumer $kafka, int $err, ?array $partitions = null): void {
            $ids = array_map(fn (TopicPartition $p) => $p->getPartition(), $partitions ?? []);

            switch ($err) {
                case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
                    $kafka->assign($partitions);
                    ($this->onAssigned)($ids);
                    break;
                case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
                    // Still a member here: the listener can commit before the partitions are taken away.
                    ($this->onRevoked)($ids);
                    $kafka->assign(null);
                    break;
                default:
                    Log::warning('identity:consume rebalance error', ['code' => $err]);
                    $kafka->assign(null);
            }
        });

        $this->consumer = $this->consumerFactory !== null ? ($this->consumerFactory)($conf) : new KafkaConsumer($conf);
        $this->consumer->subscribe([$this->topic]);
    }

    public function poll(int $timeoutMs): ?ConsumedMessage
    {
        $message = $this->consumer()->consume($timeoutMs);

        switch ($message->err) {
            case RD_KAFKA_RESP_ERR_NO_ERROR:
                return new ConsumedMessage($message->topic_name, $message->partition, $message->offset, $message->key, (string) $message->payload);
            case RD_KAFKA_RESP_ERR__TIMED_OUT:
            case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                return null;
            case RD_KAFKA_RESP_ERR__FATAL:
                throw new \RuntimeException('Kafka consumer fatal error: '.$message->errstr());
            default:
                // Transport and metadata trouble is reported once here and retried by librdkafka itself.
                Log::debug('identity:consume consumer event', ['code' => $message->err, 'reason' => $message->errstr()]);

                return null;
        }
    }

    public function commit(array $nextOffsets): void
    {
        $partitions = [];
        foreach ($nextOffsets as $partition => $offset) {
            $partitions[] = new TopicPartition($this->topic, $partition, $offset);
        }

        try {
            $this->consumer()->commit($partitions);
        } catch (Exception $e) {
            $lost = [
                RD_KAFKA_RESP_ERR_REBALANCE_IN_PROGRESS,
                RD_KAFKA_RESP_ERR_UNKNOWN_MEMBER_ID,
                RD_KAFKA_RESP_ERR_ILLEGAL_GENERATION,
                RD_KAFKA_RESP_ERR__ASSIGNMENT_LOST,
            ];

            throw new CommitFailed('Kafka commit failed: '.$e->getMessage(), in_array($e->getCode(), $lost, true), $e);
        }
    }

    public function close(): void
    {
        if ($this->consumer !== null) {
            $this->consumer->close();
            $this->consumer = null;
        }
    }

    private function consumer(): KafkaConsumer
    {
        return $this->consumer ?? throw new \LogicException('listen() has not been called.');
    }
}
