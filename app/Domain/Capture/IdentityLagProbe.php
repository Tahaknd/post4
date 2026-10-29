<?php

namespace App\Domain\Capture;

/**
 * Best-effort view of how far the identity consumer's group (config identity.group) is behind the events topic. Same
 * contract as {@see KafkaLagProbe}; a separate type so the container can hand `capture:status` one probe for the
 * ClickHouse group and one for the identity group.
 */
interface IdentityLagProbe extends KafkaLagProbe {}
