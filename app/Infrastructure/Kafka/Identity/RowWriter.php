<?php

namespace App\Infrastructure\Kafka\Identity;

/** Where the identity consumer writes: a topic, a batch at a time, acknowledged before it returns. */
interface RowWriter
{
    /**
     * Produces the messages and waits until the brokers acknowledged every one of them.
     *
     * @param  list<array{key: ?string, payload: string}>  $messages
     *
     * @throws WriteFailed when any message was not acknowledged (some may have been, so the caller repeats the whole
     *                     batch: every consumer of these topics must tolerate a message twice)
     */
    public function publish(string $topic, array $messages): void;
}
