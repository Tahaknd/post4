<?php

namespace App\Domain\Identity;

/** What applying an identity event did. */
enum IdentityOutcome: string
{
    /** Neither id had a person: one was created with both ids. */
    case Created = 'created';

    /** One id had a person and the other did not: the other was attached to it. */
    case Attached = 'attached';

    /** Both ids had different persons: the other id's person was merged into the event's own and deleted. */
    case Merged = 'merged';

    /** Nothing was written (see IdentityResult::$reason). */
    case Noop = 'noop';

    /** An id may not take part in identity events (DistinctIdRules); nothing was written. */
    case Rejected = 'rejected';
}
