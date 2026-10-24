<?php

namespace App\Http;

/**
 * First path segments the UI (the `web` group) must never register a route under.
 *
 * SEGMENTS belong to event capture and the posthog-js SDK endpoints. They are served without session,
 * cookie or CSRF middleware (see routes/capture.php) and SDKs hard-code them.
 * Verified in posthog-js 1.438.15: `e`, `s` (session recording), `i`, `static` (toolbar and other
 * assets) and `array` (config); posthog-node: `batch`, `capture`. UNVERIFIED (kept from review, the
 * SDK builds these paths at runtime): `decide`, `flags`, `engage` (legacy people endpoint).
 * `array`, `decide`, `flags` are reserved ahead of the SDK stubs (Milestone 3.9).
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
