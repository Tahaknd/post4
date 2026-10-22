<?php

namespace App\Domain\Capture;

interface EventProducer
{
    /**
     * @param  list<array<string, mixed>>  $messages
     *
     * @throws ProducerUnavailable Teslim edilemeyen mesaj varsa
     */
    public function produce(array $messages): void;
}
