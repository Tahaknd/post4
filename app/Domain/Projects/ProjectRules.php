<?php

namespace App\Domain\Projects;

use DateTimeZone;
use Illuminate\Validation\Rule;

/**
 * The one definition of a valid project, shared by the Actions (which re-validate whatever they are given) and
 * the form requests. Names are trimmed BEFORE validation, so 1..100 counts what is stored.
 */
final class ProjectRules
{
    public const int NAME_MAX_LENGTH = 100;

    public const string DEFAULT_TIMEZONE = 'UTC';

    /** @return list<mixed> */
    public static function name(): array
    {
        // No control characters: the name ends up in log lines, the navigation and a confirmation prompt.
        return ['required', 'string', 'max:'.self::NAME_MAX_LENGTH, 'regex:/\A[^\x00-\x1F\x7F]+\z/u'];
    }

    /** @return list<mixed> */
    public static function timezone(): array
    {
        return ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())];
    }

    /** @return list<string> every timezone identifier a project may use, for the create form */
    public static function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    private function __construct() {}
}
