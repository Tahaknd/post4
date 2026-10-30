<?php

namespace App\Domain\Dashboards;

use App\Domain\Projects\ProjectRules;
use Closure;

/**
 * The names and descriptions of saved insights and dashboards: one definition for the actions (which re-validate what
 * they are given) and the form requests. A name is trimmed (Unicode aware, like a project name) BEFORE it is
 * validated, so 1..120 counts what is stored; invisible and control characters are refused, never silently cleaned.
 * A description may span lines (newline and tab are fine) and is stored as null when it is blank.
 */
final class Labels
{
    public const int NAME_MAX_LENGTH = 120;

    public const int DESCRIPTION_MAX_LENGTH = 500;

    public static function normaliseName(string $name): string
    {
        return ProjectRules::normaliseName($name);
    }

    public static function normaliseDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }
        $trimmed = ProjectRules::normaliseName($description);

        return $trimmed === '' ? null : $trimmed;
    }

    /** @return list<mixed> */
    public static function name(): array
    {
        return [
            'required',
            'string',
            'max:'.self::NAME_MAX_LENGTH,
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && (self::normaliseName($value) === '' || preg_match(ProjectRules::FORBIDDEN_CHARACTERS, $value) !== 0)) {
                    $fail('The :attribute contains characters that are not allowed.');
                }
            },
        ];
    }

    /** @return list<mixed> */
    public static function description(): array
    {
        return [
            'nullable',
            'string',
            'max:'.self::DESCRIPTION_MAX_LENGTH,
            function (string $attribute, mixed $value, Closure $fail): void {
                // Line breaks and tabs are text here; every other control or invisible character is not.
                if (is_string($value) && preg_match(ProjectRules::FORBIDDEN_CHARACTERS, str_replace(["\r", "\n", "\t"], '', $value)) !== 0) {
                    $fail('The :attribute contains characters that are not allowed.');
                }
            },
        ];
    }

    private function __construct() {}
}
