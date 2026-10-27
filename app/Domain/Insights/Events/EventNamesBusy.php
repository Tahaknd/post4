<?php

namespace App\Domain\Insights\Events;

use RuntimeException;

/** The single event-list slot is taken: answer 429 and let the client ask again in a moment. */
final class EventNamesBusy extends RuntimeException
{
    public const RETRY_AFTER_SECONDS = 2;

    public function __construct()
    {
        parent::__construct('The event list is busy.');
    }
}
