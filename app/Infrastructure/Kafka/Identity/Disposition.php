<?php

namespace App\Infrastructure\Kafka\Identity;

/** What the identity consumer did with one message (the counters of its log line carry these names). */
enum Disposition: string
{
    /** Not an identity event: skipped without being decoded (the cheap check) or after it (a false positive). */
    case NotIdentity = 'filtered';

    /** An identity event that names no second id (a plain `$identify` that only sets properties): nothing to link. */
    case NoCounterpart = 'no_counterpart';

    case Created = 'created';
    case Attached = 'attached';
    case Merged = 'merged';

    /** A repeated event right after a restart: the current rows of its person were sent again (see the processor). */
    case Reconciled = 'reconciled';

    /** The resolver wrote nothing (a repeat, a refused merge, two equal ids). */
    case Noop = 'noop';

    /** An id that may not take part in identity events (DistinctIdRules). */
    case Rejected = 'rejected';

    /** The project was deleted after the event was captured: there is nobody to attach persons to. */
    case ProjectGone = 'project_gone';

    /** The event can never be processed (not JSON, malformed, an error no retry cures): it goes to the DLQ. */
    case Poison = 'poison';

    /**
     * True when the offset is committed right after the message was handled (it reached the resolver, or the DLQ).
     * False for the two kinds that touched nothing: their offsets are committed lazily, in batches.
     */
    public function commitsAtOnce(): bool
    {
        return ! in_array($this, [self::NotIdentity, self::NoCounterpart], true);
    }
}
