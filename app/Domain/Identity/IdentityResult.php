<?php

namespace App\Domain\Identity;

/**
 * What IdentityResolver::apply() did, in the terms its callers need: the Kafka producer of the identity consumer
 * turns $changes into person_distinct_ids rows, and $deletedPersonId tells it which person no longer exists.
 */
final readonly class IdentityResult
{
    /**
     * @param  int|null  $personId  the person that holds the ids afterwards (null for Rejected and SameDistinctId)
     * @param  list<DistinctIdChange>  $changes  rows inserted or moved, ordered by distinct id; empty unless Created, Attached or Merged
     * @param  int|null  $deletedPersonId  the person removed by a merge (its ids are in $changes, moved to $personId)
     * @param  string|null  $detail  for Rejected: the refused id
     */
    public function __construct(
        public IdentityOutcome $outcome,
        public int $projectId,
        public ?int $personId = null,
        public ?string $personUuid = null,
        public array $changes = [],
        public ?int $deletedPersonId = null,
        public ?string $deletedPersonUuid = null,
        public ?IdentityNoopReason $reason = null,
        public ?DistinctIdProblem $problem = null,
        public ?string $detail = null,
    ) {}

    /** True when the Postgres tables changed (and therefore ClickHouse must be told). */
    public function changedAnything(): bool
    {
        return in_array($this->outcome, [IdentityOutcome::Created, IdentityOutcome::Attached, IdentityOutcome::Merged], true);
    }
}
