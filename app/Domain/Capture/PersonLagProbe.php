<?php

namespace App\Domain\Capture;

/**
 * Best-effort view of how far the ClickHouse consumer group of the person_distinct_ids topic
 * (config capture.status.person_kafka_group) is behind that topic. Same contract as {@see KafkaLagProbe}; a separate type
 * so the container can hand `capture:status` one probe per consumer group.
 */
interface PersonLagProbe extends KafkaLagProbe {}
