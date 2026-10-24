<?php

namespace App\Domain\Projects;

use Closure;
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

    /**
     * Characters a name must not contain anywhere: control characters (\p{Cc}: C0, DEL, C1 such as U+0085),
     * format characters (\p{Cf}: bidi overrides like U+202E, zero-width space and joiner, BOM: invisible, they let a
     * name look like another or like nothing; this also rejects emoji ZWJ sequences, a deliberate trade for a
     * simple rule) and the line and paragraph separators U+2028 / U+2029.
     */
    private const string FORBIDDEN_CHARACTERS = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';

    /**
     * A name as it is stored and compared: whitespace and every Unicode space separator (NBSP, U+2000-200A, U+3000 ...)
     * stripped at both ends, so padding can never make a project impossible to find or to confirm. This is
     * Str::trim() WITHOUT its stripping of U+FEFF, U+200B and U+200E: those are invisible format characters, which
     * name() rejects outright instead of silently cleaning (a name that needed cleaning is not what was typed).
     */
    public static function normaliseName(string $name): string
    {
        return preg_replace('/\A[\s\p{Zs}]+|[\s\p{Zs}]+\z/u', '', $name) ?? $name;
    }

    /**
     * What the delete confirmation compares: the name without any invisible character, then normalised, so even a
     * project whose stored name predates these rules (and may hold such characters) can be confirmed by typing
     * what is visible.
     */
    public static function confirmationKey(string $name): string
    {
        return self::normaliseName(preg_replace(self::FORBIDDEN_CHARACTERS, '', $name) ?? $name);
    }

    /** @return list<mixed> */
    public static function name(): array
    {
        return [
            'required',
            'string',
            'max:'.self::NAME_MAX_LENGTH,
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }

                // Blank after the Unicode trim (an NBSP-only name passes 'required'), or a forbidden character.
                if (self::normaliseName($value) === '' || preg_match(self::FORBIDDEN_CHARACTERS, $value) !== 0) {
                    $fail('The :attribute contains characters that are not allowed.');
                }
            },
        ];
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
