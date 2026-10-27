<?php

namespace App\Domain\Insights\Events;

use RuntimeException;

/** The event list could not get its turn in time (slot, per-project lock or budget): answer 429 and ask again soon. */
final class EventNamesBusy extends RuntimeException
{
    public const RETRY_AFTER_SECONDS = 2;

    public function __construct(public readonly int $retryAfterSeconds = self::RETRY_AFTER_SECONDS)
    {
        parent::__construct('The event list is busy.');
    }
}
