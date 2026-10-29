<?php

namespace App\Infrastructure\Kafka\Identity;

/** What IdentityConsumer needs of the thing that handles one message (IdentityMessageProcessor; a fake in tests). */
interface MessageProcessor
{
    /**
     * @param  bool  $reconcile  The message may be a replay after a crash: re-send the current rows of a person
     *                           the resolver finds already up to date.
     *
     * @throws RetryableFailure
     */
    public function process(ConsumedMessage $message, bool $reconcile = false): Processed;
}
