<?php

namespace App\Domain\Identity;

/**
 * One person_distinct_ids row that was inserted or moved to another person: exactly what the ClickHouse copy of the
 * table needs as a new row (project, distinct id, person, version; the latest version of a distinct id wins).
 */
final readonly class DistinctIdChange
{
    public function __construct(
        public int $projectId,
        public string $distinctId,
        public int $personId,
        public string $personUuid,
        public int $version,
    ) {}
}
