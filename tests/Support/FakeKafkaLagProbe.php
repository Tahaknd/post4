<?php

namespace Tests\Support;

use App\Domain\Capture\KafkaLagProbe;

final class FakeKafkaLagProbe implements KafkaLagProbe
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
