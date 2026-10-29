<?php

namespace Tests\Support;

use App\Domain\Capture\DlqDepthProbe;

final class FakeDlqDepthProbe implements DlqDepthProbe
{
    /** @param  array<int, array{low: int, high: int, depth: int}>  $partitions */
    public function __construct(
        public array $partitions = [0 => ['low' => 0, 'high' => 0, 'depth' => 0]],
        public ?\Throwable $failWith = null,
    ) {}

    public function depth(): array
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return $this->partitions;
    }
}
