<?php

namespace App\Infrastructure\Kafka\Identity;

use Illuminate\Support\Facades\Log;
use RdKafka\Conf;
use RdKafka\Exception;
use RdKafka\Producer;
use RdKafka\ProducerTopic;

/**
 * Writes the person_distinct_ids rows and the dead letters with an idempotent producer (acks=all) and waits for every
 * delivery report before publish() returns, so the caller may commit the offset it read them from.
 *
 * Unlike the capture producer there is no circuit breaker (a failure is retried with a backoff by the consumer loop)
 * and a message that is too large is a failure, not a skip: dropping a row silently would lose an identity.
 */
final class RdKafkaRowWriter implements RowWriter
{
    private ?Producer $producer = null;

    /** @var array<string, ProducerTopic> */
    private array $topics = [];

    private int $failures = 0;

    private bool $fatal = false;

    /**
     * @param  ?\Closure(Conf, \Closure): Producer  $producerFactory  Test seam: builds the client from the finished conf
     *                                                                and the delivery-report callback.
     */
    public function __construct(
        private readonly string $brokers,
        private readonly int $messageTimeoutMs = 30000,
        private readonly int $flushTimeoutMs = 35000,
        private readonly ?\Closure $producerFactory = null,
    ) {}

    public function publish(string $topic, array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $this->failures = 0;

        try {
            $producer = $this->producer();
            $handle = $this->topics[$topic] ??= $producer->newTopic($topic);

            foreach ($messages as ['key' => $key, 'payload' => $payload]) {
                $handle->produce(RD_KAFKA_PARTITION_UA, 0, $payload, $key);
                $producer->poll(0);
            }
        } catch (Exception $e) {
            $this->reset();

            throw new WriteFailed('Kafka produce failed: '.$e->getMessage(), 0, $e);
        }

        $result = $producer->flush($this->flushTimeoutMs);

        if ($result === RD_KAFKA_RESP_ERR_NO_ERROR) {
            // Reports of the last messages may still wait in the queue; serve them before judging.
            $producer->poll(0);
        }

        if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR || $this->failures > 0) {
            $fatal = $this->fatal;
            // Drops what is still queued, so a repeat of the whole batch cannot be followed by stale copies of it.
            $producer->purge(RD_KAFKA_PURGE_F_QUEUE);
            $producer->poll(0);

            if ($fatal) {
                $this->reset();
            }

            throw new WriteFailed('Kafka delivery failed');
        }
    }

    private function reset(): void
    {
        $this->producer = null;
        $this->topics = [];
        $this->fatal = false;
    }

    private function producer(): Producer
    {
        if ($this->producer !== null) {
            return $this->producer;
        }

        $conf = new Conf;
        $conf->set('metadata.broker.list', $this->brokers);
        $conf->set('client.id', 'post4-identity');
        $conf->set('enable.idempotence', 'true');
        $conf->set('acks', 'all');
        $conf->set('compression.codec', 'lz4');
        $conf->set('message.timeout.ms', (string) $this->messageTimeoutMs);
        $conf->set('socket.keepalive.enable', 'true');
        $conf->set('log.connection.close', 'false');
        $onDelivery = function ($kafka, $message): void {
            if ($message->err === RD_KAFKA_RESP_ERR__FATAL) {
                $this->fatal = true;
            }
            if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                $this->failures++;
            }
        };
        $conf->setDrMsgCb($onDelivery);
        $conf->setErrorCb(function ($kafka, int $err, string $reason): void {
            if ($err === RD_KAFKA_RESP_ERR__FATAL) {
                $this->fatal = true;
                Log::error('identity:consume Kafka producer fatal error, rebuilding the client', ['code' => $err]);

                return;
            }

            Log::debug('identity:consume Kafka producer error', ['code' => $err, 'reason' => $reason]);
        });
        $conf->setLogCb(function ($kafka, int $level, string $facility, string $message): void {
            Log::log($level <= 4 ? 'warning' : 'debug', 'librdkafka '.$facility.': '.$message);
        });

        return $this->producer = $this->producerFactory !== null
            ? ($this->producerFactory)($conf, $onDelivery)
            : new Producer($conf);
    }
}
