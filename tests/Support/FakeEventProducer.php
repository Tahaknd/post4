<?php

namespace Tests\Support;

use App\Domain\Capture\EventProducer;
use App\Domain\Capture\ProducerUnavailable;

final class FakeEventProducer implements EventProducer
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    public bool $fail = false;

    /** Thrown by produce() when set, to simulate an unexpected failure. */
    public ?\Throwable $failWith = null;

    public function produce(array $messages): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        if ($this->fail) {
            throw new ProducerUnavailable('fake failure');
        }

        array_push($this->messages, ...$messages);
    }
}
