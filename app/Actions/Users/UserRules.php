<?php

namespace App\Actions\Users;

use Closure;
use Illuminate\Validation\Rules\Password;

/**
 * The one definition of what a valid account looks like, shared by the registration form and the
 * `user:create` / `user:reset-password` commands. Emails must be normalised (User::normaliseEmail)
 * BEFORE they are validated, so `unique` compares what is stored.
 *
 * Passwords: at least 12 characters and, because bcrypt silently ignores everything after byte 72,
 * at most 72 bytes (a longer one would be accepted but only its prefix would count). No
 * composition rules and no breached-password lookup (that would send hashes to a third party).
 */
final class UserRules
{
    public const int PASSWORD_MIN_LENGTH = 12;

    public const int PASSWORD_MAX_BYTES = 72;

    /** @return list<mixed> */
    public static function name(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /** @return list<mixed> */
    public static function email(): array
    {
        return ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'];
    }

    /** @return list<mixed> */
    public static function password(): array
    {
        return [
            'required',
            'string',
            Password::min(self::PASSWORD_MIN_LENGTH),
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > self::PASSWORD_MAX_BYTES) {
                    $fail('The :attribute must not be longer than '.self::PASSWORD_MAX_BYTES.' bytes.');
                }
            },
        ];
    }

    private function __construct() {}
}
