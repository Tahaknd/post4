<?php

namespace App\Domain\Capture;

interface EventProducer
{
    /**
     * @param  list<array<string, mixed>>  $messages
     *
     * @throws ProducerUnavailable When a message could not be delivered
     */
    public function produce(array $messages): void;
}
