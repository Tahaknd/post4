<?php

namespace App\Jobs;

/**
 * Removes every distinct id -> person row of a deleted project from posthog.person_distinct_ids (Milestone 4).
 * Same job as PurgeProjectEvents for another table, see {@see PurgeProjectRows}. The Postgres side needs no job:
 * its persons and person_distinct_ids rows go with the project through the foreign keys.
 */
class PurgeProjectPersonDistinctIds extends PurgeProjectRows
{
    protected function table(): string
    {
        return 'person_distinct_ids';
    }
}
