<?php

namespace App\Domain\Capture;

final class CapturePayload
{
    /** @param list<array<string, mixed>> $events */
    public function __construct(
        public readonly ?string $apiKey,
        public readonly array $events,
        public readonly ?string $sentAt,
    ) {}
}
