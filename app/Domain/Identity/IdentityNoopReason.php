<?php

namespace App\Domain\Identity;

/** Why an event that was accepted wrote nothing. */
enum IdentityNoopReason: string
{
    /** Both ids of the event are the same id. */
    case SameDistinctId = 'same_distinct_id';

    /** Both ids already belong to the same person (a repeated event, or the reverse of an earlier one). */
    case SamePerson = 'same_person';

    /** `$identify`: the anonymous id's person is already identified, so it is not merged into another one. */
    case SourceAlreadyIdentified = 'source_already_identified';

    /** `$create_alias`: both persons are already identified. Only `$merge_dangerously` merges them. */
    case BothAlreadyIdentified = 'both_already_identified';
}
