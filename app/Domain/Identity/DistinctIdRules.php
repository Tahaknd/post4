<?php

namespace App\Domain\Identity;

/**
 * Which distinct ids may be linked to each other.
 *
 * Applied to EVERY id of an identity event, on both sides. A client that sends `$identify` with a missing user id
 * (JavaScript's `String(undefined)`, `String(null)`, `String({})`, a literal `0` or `true`) would otherwise link
 * every such visitor to ONE person, and merges cannot be undone. The ids are only refused for identity events: the
 * event itself is still stored under whatever distinct_id it carried, exactly like PostHog.
 *
 * The list is PostHog's (plugin server `person-state`: the ids it refuses to merge on), written from memory of that
 * source and not diffed against a copy of it. PostHog compares a few of them case-sensitively (`NaN`, `None`,
 * `none`, `null`, `[object Object]`); here every entry is compared case-insensitively after trimming, which is
 * slightly stricter (`nan`, `NULL`, `[Object object]` are refused too) and cannot weaken the guard.
 */
final class DistinctIdRules
{
    /**
     * Longest accepted id in characters: the limit EventNormalizer puts on every event's distinct_id, so an id that
     * was accepted at capture time is never refused here for its length, and the id of the other side (alias,
     * $anon_distinct_id), which the normalizer never saw, is held to the same limit.
     */
    public const int MAX_LENGTH = 200;

    /**
     * Lower case. The first group is what a broken client sends for "no value" (JavaScript string conversions of
     * undefined, null, NaN, objects and booleans, and 0); the second is what applications commonly send as a
     * placeholder instead of the user's real id, which would merge all of those users into one person.
     */
    public const array RESERVED = [
        'null', 'undefined', 'nan', 'none', '0', '[object object]', 'true', 'false',
        'anonymous', 'guest', 'distinctid', 'distinct_id', 'id', 'not_authenticated', 'email',
    ];

    /** Null when the id may take part in an identity event. */
    public static function problem(string $distinctId): ?DistinctIdProblem
    {
        // Unicode-aware: PHP's trim() leaves a no-break or ideographic space, which would make a blank id look real.
        $trimmed = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $distinctId) ?? trim($distinctId);

        if ($trimmed === '') {
            return DistinctIdProblem::Blank;
        }

        // 4 bytes per character is the UTF-8 maximum: a cheap check before counting characters.
        if (strlen($distinctId) > self::MAX_LENGTH * 4 || mb_strlen($distinctId) > self::MAX_LENGTH) {
            return DistinctIdProblem::TooLong;
        }

        // NUL cannot be stored in a Postgres text column; the other C0 controls are the normalizer's rule too.
        if (preg_match('/[\x00-\x1F\x7F]/', $distinctId) === 1) {
            return DistinctIdProblem::ControlCharacters;
        }

        if (in_array(mb_strtolower($trimmed), self::RESERVED, true)) {
            return DistinctIdProblem::Reserved;
        }

        return null;
    }

    public static function isValid(string $distinctId): bool
    {
        return self::problem($distinctId) === null;
    }

    private function __construct() {}
}
