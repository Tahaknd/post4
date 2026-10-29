<?php

namespace App\Infrastructure\Kafka\Identity;

/** The result of IdentityMessageProcessor::process(): what happened and what has to be produced because of it. */
final readonly class Processed
{
    /**
     * @param  list<array<string, mixed>>  $rows  person_distinct_ids rows to produce (see PersonDistinctIdRow)
     * @param  ?string  $errorClass  Poison only
     * @param  ?string  $errorMessage  Poison only (stored in the DLQ message, never logged)
     */
    public function __construct(
        public Disposition $disposition,
        public array $rows = [],
        public ?string $errorClass = null,
        public ?string $errorMessage = null,
    ) {}
}
