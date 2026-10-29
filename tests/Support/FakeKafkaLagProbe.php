<?php

namespace Tests\Support;

use App\Domain\Capture\IdentityLagProbe;
use App\Domain\Capture\PersonLagProbe;

/** Stands in for the ClickHouse group's probe and, being an IdentityLagProbe and a PersonLagProbe too, for those groups'. */
final class FakeKafkaLagProbe implements IdentityLagProbe, PersonLagProbe
{
    /**
     * @param  array<int, array{committed: ?int, high: int, lag: int}>  $partitions
     */
    public function __construct(
        public array $partitions = [0 => ['committed' => 10, 'high' => 10, 'lag' => 0]],
        public ?\Throwable $failWith = null,
    ) {}

    public function lag(): array
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return $this->partitions;
    }
}
