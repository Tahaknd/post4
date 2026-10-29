<?php

namespace App\Domain\Identity;

/**
 * The events that link distinct ids, with the property that carries the other id (PostHog's names).
 *
 * `$merge_dangerously` also reads `alias`: that is the property PostHog's own SDKs and plugin server use for it.
 */
enum IdentityKind: string
{
    case Identify = '$identify';
    case CreateAlias = '$create_alias';
    case MergeDangerously = '$merge_dangerously';

    /** The kind an event name stands for, or null for every other event. */
    public static function fromEventName(string $event): ?self
    {
        return self::tryFrom($event);
    }

    /** Property of the event that holds the id to link to the event's own distinct_id. */
    public function counterpartProperty(): string
    {
        return match ($this) {
            self::Identify => '$anon_distinct_id',
            self::CreateAlias, self::MergeDangerously => 'alias',
        };
    }

    /** A second property some clients use for `$merge_dangerously` (the id to merge in). Null for the other kinds. */
    public function alternativeCounterpartProperty(): ?string
    {
        return $this === self::MergeDangerously ? 'merge_distinct_id' : null;
    }
}
