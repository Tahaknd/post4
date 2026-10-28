<?php

namespace App\Domain\Capture\Enrichment;

/**
 * Decides which addresses may be handed to the GeoIP database: only GLOBALLY ROUTABLE UNICAST addresses, in
 * canonical text form. Everything else is not an end user's public address and would only produce a wrong
 * answer or waste a lookup.
 *
 * Refused (IANA special-purpose registries):
 *  - IPv4: 0/8, 10/8, 100.64/10 (carrier grade NAT), 127/8, 169.254/16, 172.16/12, 192.0.0/24, 192.0.2/24,
 *    192.88.99/24 (6to4 relay, deprecated), 192.168/16, 198.18/15 (benchmarking), 198.51.100/24, 203.0.113/24,
 *    224/4 (multicast) and 240/4 (reserved, includes the broadcast address).
 *  - IPv6: ::/128, ::1/128, 64:ff9b::/96 and 64:ff9b:1::/48 (NAT64: DECISION, not unwrapped. The embedded IPv4
 *    could be looked up, but a NAT64 prefix is an operator's translator, not the client, and a database has
 *    no entry for it), 100::/64, 2001::/23 (IETF protocol assignments, Teredo included), 2001:db8::/32 and
 *    3fff::/20 (documentation), 5f00::/16, fc00::/7 (unique local), fe80::/10, fec0::/10, ff00::/8 (multicast).
 *  - an IPv4-mapped IPv6 address (::ffff:a.b.c.d) is UNWRAPPED and judged as that IPv4 address.
 *  - 6to4 (2002::/16) is allowed: it embeds the client's own public IPv4 and databases list it.
 * Only the dotted quad form is accepted: `filter_var` already rejects decimal (2130706433), octal (0177.0.0.1)
 * and hexadecimal (0x7f.0.0.1) IPv4 spellings, so they never reach the table.
 */
final class PublicIp
{
    /** @var list<array{0: string, 1: int}> IPv4 prefix (dotted) and length */
    private const V4 = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12],
        ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15],
        ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
    ];

    /** @var list<array{0: string, 1: int}> IPv6 prefix and length */
    private const V6 = [
        ['::', 128], ['::1', 128], ['64:ff9b::', 96], ['64:ff9b:1::', 48], ['100::', 64], ['2001::', 23],
        ['2001:db8::', 32], ['3fff::', 20], ['5f00::', 16], ['fc00::', 7], ['fe80::', 10], ['fec0::', 10], ['ff00::', 8],
    ];

    /** The canonical public address, or null when the value is not a globally routable unicast address. */
    public static function normalise(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 45) {
            return null;
        }

        $value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($value);
        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        foreach (strlen($packed) === 4 ? self::V4 : self::V6 as [$prefix, $bits]) {
            if (self::within($packed, (string) inet_pton($prefix), $bits)) {
                return null;
            }
        }

        return inet_ntop($packed) ?: null;
    }

    private static function within(string $packed, string $prefix, int $bits): bool
    {
        $whole = intdiv($bits, 8);
        if (substr($packed, 0, $whole) !== substr($prefix, 0, $whole)) {
            return false;
        }

        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$whole]) & $mask) === (ord($prefix[$whole]) & $mask);
    }

    private function __construct() {}
}
