<?php

namespace App\Infrastructure\Kafka\Identity;

use RuntimeException;

/** A commit that was not acknowledged. $ownershipLost: the partition belongs to another member now (a rebalance). */
final class CommitFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $ownershipLost = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
