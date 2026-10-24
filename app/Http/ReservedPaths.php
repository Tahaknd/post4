<?php

namespace App\Http;

/**
 * First path segments that belong to event capture and the posthog-js SDK endpoints. They are served
 * without session, cookie or CSRF middleware (see routes/capture.php) and SDKs hard-code them, so the
 * UI (the `web` group) must never register a route that starts with one of them.
 *
 * `array`, `decide` and `flags` are reserved ahead of the SDK stubs (Milestone 3.9).
 */
final class ReservedPaths
{
    public const array SEGMENTS = ['e', 'batch', 'capture', 'i', 'array', 'decide', 'flags'];

    /**
     * Whether a route URI (with or without a leading slash) is the reserved segment itself or sits below it.
     */
    public static function collides(string $uri): bool
    {
        $first = strtolower(explode('/', ltrim($uri, '/'), 2)[0]);

        return in_array($first, self::SEGMENTS, true);
    }

    private function __construct() {}
}
