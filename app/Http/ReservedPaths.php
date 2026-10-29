<?php

namespace App\Http;

/**
 * First path segments the UI (the `web` group) must never register a route under.
 *
 * SEGMENTS belong to event capture and the posthog-js SDK endpoints. They are served without session,
 * cookie or CSRF middleware (see routes/capture.php) and SDKs hard-code them.
 * Verified in the pinned posthog-js 1.438.24 (task 3.9, read from the package source and seen in a real browser):
 * `e` (events, default `/e/`), `i` (`/i/v0/e/` via the analytics endpoint), `s` (session recording, `/s/`), `static`
 * (lazy extension scripts `/static/{version}/{kind}.js`, legacy `/static/{kind}.js`), `array` (`/array/{token}/config`
 * and `config.js`), `flags` (`POST /flags/?v=2`). posthog-node: `batch`, `capture`.
 * `decide` is no longer called by 1.438.24 (it is what SDKs before the /flags split ask), and `engage` (the legacy
 * people endpoint) does not appear in it at all: both stay reserved for older SDKs.
 * Served in 3.9: `e`, `i`, `batch`, `capture`, `array`, `flags`, `decide` (the last three as stubs, SdkStubController).
 * Reserved but NOT served: `s` (the stubbed remote config switches session recording off), `static`, `engage`.
 *
 * APP_OWNED are names the application or its packages already use: the health route, built assets,
 * the storage disk, Horizon, Inertia's DevTools and the JSON API. A UI page cannot take them; only
 * the owner can (tests/Feature/ReservedRoutePrefixesTest.php lists the exceptions).
 */
final class ReservedPaths
{
    public const array SEGMENTS = ['e', 'batch', 'capture', 'i', 's', 'static', 'engage', 'array', 'decide', 'flags'];

    public const array APP_OWNED = ['up', 'build', 'storage', 'horizon', '_inertia', 'api'];

    /**
     * Whether a route URI (with or without a leading slash) is a capture or SDK segment itself or sits below it.
     */
    public static function collides(string $uri): bool
    {
        return in_array(self::firstSegment($uri), self::SEGMENTS, true);
    }

    /**
     * Whether a route URI starts with a name owned by the application or one of its packages.
     */
    public static function takesAppOwnedName(string $uri): bool
    {
        return in_array(self::firstSegment($uri), self::APP_OWNED, true);
    }

    private static function firstSegment(string $uri): string
    {
        return strtolower(explode('/', ltrim($uri, '/'), 2)[0]);
    }

    private function __construct() {}
}
