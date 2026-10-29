<?php

namespace App\Infrastructure\Kafka;

use App\Domain\Capture\PersonLagProbe;

/** The lag of ClickHouse's group on the person_distinct_ids topic: an {@see RdKafkaLagProbe} for that group and topic, under its own type. */
final class RdKafkaPersonLagProbe implements PersonLagProbe
{
    public function __construct(private readonly RdKafkaLagProbe $probe) {}

    public function lag(): array
    {
        return $this->probe->lag();
    }
}
