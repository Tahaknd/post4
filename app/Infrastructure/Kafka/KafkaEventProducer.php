<?php

namespace App\Infrastructure\Kafka;

use App\Domain\Capture\EventProducer;
use App\Domain\Capture\ProducerUnavailable;
use Illuminate\Support\Facades\Log;
use RdKafka\Conf;
use RdKafka\Producer;

/**
 * Publishes normalized event messages to Kafka and blocks until every message
 * is acknowledged (acks=all, idempotent). produce() flushes synchronously, so
 * nothing stays buffered after it returns.
 *
 * One instance lives per Octane worker (see octane.warm), so the circuit
 * breaker below is plain per-instance state: after a delivery failure the
 * instance fails fast for a cooldown instead of blocking every request for
 * the full timeout while Kafka is down.
 */
final class KafkaEventProducer implements EventProducer
{
    private ?Producer $producer = null;

    private int $failures = 0;

    /** Set by librdkafka callbacks when the client hit a fatal error and must be rebuilt. */
    private bool $fatal = false;

    /** hrtime-based seconds until which the circuit is open. */
    private float $openUntil = 0.0;

    /**
     * @param  int  $messageTimeoutMs  Upper bound for delivering one message (message.timeout.ms).
     * @param  int  $flushTimeoutMs  How long produce() waits for the flush; keep it above $messageTimeoutMs.
     * @param  float  $breakerCooldownSeconds  How long the circuit stays open after a delivery failure.
     */
    public function __construct(
        private readonly string $brokers,
        private readonly string $topic,
        private readonly int $messageTimeoutMs = 2500,
        private readonly int $flushTimeoutMs = 3000,
        private readonly float $breakerCooldownSeconds = 10.0,
    ) {}

    public function produce(array $messages): void
    {
        if ($messages === []) {
            return;
        }

        if ($this->now() < $this->openUntil) {
            throw new ProducerUnavailable('Kafka circuit open');
        }

        // Encode everything first: a message that cannot be encoded must fail the call
        // before anything is queued.
        $payloads = [];
        foreach ($messages as $message) {
            $payloads[] = [
                json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $message['project_id'].':'.$message['distinct_id'],
            ];
        }

        try {
            $this->send($payloads);
        } catch (ProducerUnavailable $e) {
            $this->openUntil = $this->now() + $this->breakerCooldownSeconds;

            throw $e;
        } finally {
            if ($this->fatal) {
                $this->producer = null;
                $this->fatal = false;
            }
        }
    }

    /** @param  list<array{0:string,1:string}>  $payloads */
    private function send(array $payloads): void
    {
        $this->failures = 0;

        try {
            $producer = $this->producer();
            $topic = $producer->newTopic($this->topic);

            foreach ($payloads as [$payload, $key]) {
                try {
                    $topic->produce(RD_KAFKA_PARTITION_UA, 0, $payload, $key);
                } catch (\RdKafka\Exception $e) {
                    if ($e->getCode() !== RD_KAFKA_RESP_ERR_MSG_SIZE_TOO_LARGE) {
                        throw $e;
                    }

                    // It can never be delivered: skip it instead of failing (and retrying) forever.
                    Log::warning('Kafka message too large, skipped', ['bytes' => strlen($payload), 'key' => $key]);

                    continue;
                }

                $producer->poll(0);
            }
        } catch (\RdKafka\Exception $e) {
            if ($this->producer !== null) {
                $this->purge($this->producer);
            }
            $this->producer = null;

            throw new ProducerUnavailable('Kafka produce failed', previous: $e);
        }

        $result = $producer->flush($this->flushTimeoutMs);

        if ($result === RD_KAFKA_RESP_ERR_NO_ERROR) {
            // Reports of the last messages may still sit in the queue; serve them before judging.
            $producer->poll(0);
        }

        if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR || $this->failures > 0 || $producer->getOutQLen() > 0) {
            $this->purge($producer);

            throw new ProducerUnavailable('Kafka delivery failed');
        }
    }

    /**
     * Drops what is still queued so a later request cannot deliver stale messages.
     * Never RD_KAFKA_PURGE_F_INFLIGHT: it forces an epoch bump on an idempotent producer.
     */
    private function purge(Producer $producer): void
    {
        $producer->purge(RD_KAFKA_PURGE_F_QUEUE);
        $producer->poll(0);
    }

    private function producer(): Producer
    {
        if ($this->producer !== null) {
            return $this->producer;
        }

        $conf = new Conf;
        $conf->set('metadata.broker.list', $this->brokers);
        $conf->set('client.id', 'post4-capture');
        $conf->set('enable.idempotence', 'true');
        $conf->set('acks', 'all');
        $conf->set('compression.codec', 'lz4');
        $conf->set('message.timeout.ms', (string) $this->messageTimeoutMs);
        $conf->set('socket.keepalive.enable', 'true');
        $conf->set('log.connection.close', 'false');
        $conf->setDrMsgCb(function ($kafka, $message): void {
            if ($message->err === RD_KAFKA_RESP_ERR__FATAL) {
                $this->fatal = true;
            }
            if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                $this->failures++;
            }
        });
        $conf->setErrorCb(function ($kafka, int $err, string $reason): void {
            // Transient errors (brokers down, transport) are already logged by the log callback.
            if ($err === RD_KAFKA_RESP_ERR__FATAL) {
                $this->fatal = true;
                Log::error('Kafka fatal error, rebuilding the client', ['code' => $err, 'reason' => $reason]);

                return;
            }

            Log::debug('Kafka client error', ['code' => $err, 'reason' => $reason]);
        });
        $conf->setLogCb(function ($kafka, int $level, string $facility, string $message): void {
            Log::log($level <= 4 ? 'warning' : 'debug', 'librdkafka '.$facility.': '.$message);
        });

        return $this->producer = new Producer($conf);
    }

    private function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
