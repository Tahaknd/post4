<?php

namespace App\Infrastructure\Kafka;

use App\Domain\Capture\IdentityLagProbe;

/** The lag of the identity consumer's group: an {@see RdKafkaLagProbe} for that group, under its own type. */
final class RdKafkaIdentityLagProbe implements IdentityLagProbe
{
    public function __construct(private readonly RdKafkaLagProbe $probe) {}

    public function lag(): array
    {
        return $this->probe->lag();
    }
}
