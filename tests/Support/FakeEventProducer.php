<?php

namespace Tests\Support;

use App\Domain\Capture\EventProducer;
use App\Domain\Capture\ProducerUnavailable;

final class FakeEventProducer implements EventProducer
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    public bool $fail = false;

    public function produce(array $messages): void
    {
        if ($this->fail) {
            throw new ProducerUnavailable('fake failure');
        }

        array_push($this->messages, ...$messages);
    }
}
