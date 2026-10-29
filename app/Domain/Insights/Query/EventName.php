<?php

namespace App\Domain\Insights\Query;

/** The rule for the name of an event in a definition: 1 to {@see self::MAX_LENGTH} characters of valid UTF-8. */
final class EventName
{
    public const MAX_LENGTH = 200;

    private function __construct() {}

    /** @throws InvalidInsightQuery */
    public static function assertValid(mixed $name): void
    {
        if (! is_string($name) || $name === '' || mb_strlen($name) > self::MAX_LENGTH || ! mb_check_encoding($name, 'UTF-8')) {
            throw new InvalidInsightQuery('Event names must be 1 to '.self::MAX_LENGTH.' characters of valid UTF-8');
        }
    }
}
