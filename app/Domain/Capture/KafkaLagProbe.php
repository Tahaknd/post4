<?php

namespace App\Domain\Capture;

/**
 * Best-effort view of how far the ClickHouse consumer group is behind the events topic. The real
 * implementation talks to the brokers (App\Infrastructure\Kafka\RdKafkaLagProbe); `capture:status`
 * treats any failure as "lag unknown", never as a fatal error.
 */
interface KafkaLagProbe
{
    /**
     * Per partition: the group's committed offset (null when it never committed), the high watermark
     * and the resulting lag (never negative).
     *
     * @return array<int, array{committed: ?int, high: int, lag: int}>
     *
     * @throws \Throwable when the brokers cannot be reached in time or answer with an error
     */
    public function lag(): array;
}
