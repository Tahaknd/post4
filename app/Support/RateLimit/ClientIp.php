<?php

namespace App\Support\RateLimit;

/**
 * Normalises a client ip into the string the per-ip limiters key on (capture failures and sign-in
 * throttling share it).
 */
final class ClientIp
{
    /**
     * IPv4 is used as is and an IPv4-mapped IPv6 address (::ffff:a.b.c.d) counts as that IPv4. Any other
     * IPv6 address is grouped by its /64 prefix, because one subscriber commonly owns a whole /64 and
     * per-address budgets would be unlimited. Empty or unparseable input becomes "unknown".
     */
    public static function normalise(string $ip): string
    {
        // A NUL byte makes inet_pton throw a ValueError (unreachable through Request::ip(), cheap to rule out).
        $packed = str_contains($ip, "\0") ? false : @inet_pton($ip);
        if ($packed === false) {
            return 'unknown';
        }

        if (strlen($packed) === 4) {
            return inet_ntop($packed);
        }

        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return inet_ntop(substr($packed, 12));
        }

        return 'v6-'.bin2hex(substr($packed, 0, 8));
    }

    private function __construct() {}
}
