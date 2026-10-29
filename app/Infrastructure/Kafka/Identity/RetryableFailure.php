<?php

namespace App\Infrastructure\Kafka\Identity;

use RuntimeException;

/** A failure of one event that a later attempt can cure (a lock timeout, a lost connection, a contended merge). */
final class RetryableFailure extends RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
