<?php

namespace App\Http;

/**
 * The page a guest asked for before being sent to /login, carried as `?next=` and sanitised before every use
 * (when the login page is built and again when the form is posted). Only a same-origin PATH is ever accepted:
 *
 *  - starts with exactly one "/" ("//host" and "/\host" are protocol-relative to a browser),
 *  - no backslash and no control character (browsers drop tabs and line breaks inside URLs, so "/<tab>/host"
 *    would become "//host"),
 *  - at most 200 characters,
 *  - not a capture or SDK path, not an application-owned prefix (health, assets, API ...; Horizon is a page the
 *    application owns and may be returned to), and not the sign-in pages themselves.
 * Anything else becomes null and the caller falls back to "/".
 */
final class NextUrl
{
    public const int MAX_LENGTH = 200;

    private const array AUTH_PAGES = ['login', 'register', 'logout'];

    /**
     * @param  bool  $allowAuthPages  true when the target is where a form was posted from (a 419 may go back to /login)
     */
    public static function sanitise(mixed $next, bool $allowAuthPages = false): ?string
    {
        // "/" is the fallback anyway, so it is not worth a query string.
        if (! is_string($next) || $next === '' || $next === '/' || strlen($next) > self::MAX_LENGTH) {
            return null;
        }

        if ($next[0] !== '/' || str_starts_with($next, '//') || preg_match('/[\x00-\x20\x7f\\\\]/', $next) === 1) {
            return null;
        }

        $parts = parse_url($next);

        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || ! isset($parts['path'])) {
            return null;
        }

        $segment = strtolower(explode('/', ltrim($parts['path'], '/'), 2)[0]);

        if (
            ReservedPaths::collides($parts['path'])
            || ($segment !== 'horizon' && ReservedPaths::takesAppOwnedName($parts['path']))
            || (! $allowAuthPages && in_array($segment, self::AUTH_PAGES, true))
        ) {
            return null;
        }

        return $next;
    }

    private function __construct() {}
}
