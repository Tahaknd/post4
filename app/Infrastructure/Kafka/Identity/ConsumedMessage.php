<?php

namespace App\Infrastructure\Kafka\Identity;

/** One message of the events topic, as the identity consumer sees it. */
final readonly class ConsumedMessage
{
    public function __construct(
        public string $topic,
        public int $partition,
        public int $offset,
        public ?string $key,
        public string $payload,
    ) {}
}
