<?php

namespace Tests\Support;

use InvalidArgumentException;

/**
 * Writes a tiny but real MaxMind DB (MMDB 2.0, 24 bit records) so the GeoIP tests can run the real
 * maxmind-db/reader without a licensed database in the repository. Supports exactly what the tests need:
 * non-overlapping networks mapped to nested records of strings, numbers, maps and lists.
 *
 * Format reference: https://maxmind.github.io/MaxMind-DB/
 */
final class MmdbFixture
{
    /**
     * @param  array<string, array<string, mixed>>  $networks  CIDR => record, for example ['8.8.8.0/24' => ['country' => [...]]]
     * @param  int  $ipVersion  6 (default; IPv4 networks live under ::/96) or 4 (IPv4 only)
     */
    public static function write(string $path, array $networks, int $ipVersion = 6, string $type = 'GeoLite2-City'): void
    {
        file_put_contents($path, self::build($networks, $ipVersion, $type));
    }

    /** @param  array<string, array<string, mixed>>  $networks */
    public static function build(array $networks, int $ipVersion = 6, string $type = 'GeoLite2-City'): string
    {
        $depth = $ipVersion === 6 ? 128 : 32;

        $data = '';
        $offsets = [];
        $nodes = [[null, null]];

        foreach ($networks as $cidr => $record) {
            [$bits, $length] = self::bits($cidr, $ipVersion);

            $encoded = self::encode($record);
            $offsets[$encoded] ??= (function () use (&$data, $encoded) {
                $offset = strlen($data);
                $data .= $encoded;

                return $offset;
            })();

            $node = 0;
            for ($i = 0; $i < $length; $i++) {
                $side = $bits[$i];
                if ($i === $length - 1) {
                    if ($nodes[$node][$side] !== null) {
                        throw new InvalidArgumentException("overlapping network {$cidr}");
                    }
                    $nodes[$node][$side] = ['data' => $offsets[$encoded]];

                    break;
                }

                $next = $nodes[$node][$side];
                if (is_array($next)) {
                    throw new InvalidArgumentException("overlapping network {$cidr}");
                }
                if ($next === null) {
                    $nodes[] = [null, null];
                    $next = array_key_last($nodes);
                    $nodes[$node][$side] = $next;
                }
                $node = $next;
            }
        }

        $nodeCount = count($nodes);
        $tree = '';
        foreach ($nodes as $pair) {
            foreach ($pair as $record) {
                $value = match (true) {
                    $record === null => $nodeCount,
                    is_int($record) => $record,
                    default => $nodeCount + 16 + $record['data'],
                };
                $tree .= substr(pack('N', $value), 1);
            }
        }

        $metadata = self::encode([
            'binary_format_major_version' => self::uint16(2),
            'binary_format_minor_version' => self::uint16(0),
            'build_epoch' => self::uint64(1_700_000_000),
            'database_type' => $type,
            'description' => ['en' => 'post4 test fixture'],
            'ip_version' => self::uint16($ipVersion),
            'languages' => ['en'],
            'node_count' => self::uint32($nodeCount),
            'record_size' => self::uint16(24),
        ]);

        return $tree.str_repeat("\0", 16).$data."\xAB\xCD\xEFMaxMind.com".$metadata;
    }

    /** @return array{0: list<int>, 1: int} */
    private static function bits(string $cidr, int $ipVersion): array
    {
        [$address, $prefix] = explode('/', $cidr);
        $packed = inet_pton($address);
        if ($packed === false) {
            throw new InvalidArgumentException("bad address {$cidr}");
        }

        $length = (int) $prefix;
        if ($ipVersion === 6 && strlen($packed) === 4) {
            $packed = str_repeat("\0", 12).$packed;
            $length += 96;
        }

        $bits = [];
        foreach (str_split($packed) as $byte) {
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = (ord($byte) >> $i) & 1;
            }
        }

        return [$bits, $length];
    }

    // --- data section encoding -----------------------------------------------------------------

    public static function uint16(int $value): object
    {
        return (object) ['t' => 5, 'v' => $value];
    }

    public static function uint32(int $value): object
    {
        return (object) ['t' => 6, 'v' => $value];
    }

    public static function uint64(int $value): object
    {
        return (object) ['t' => 9, 'v' => $value];
    }

    /** @param  mixed  $value  string, float (double), int (uint32), typed uint (see uint16()), list or map */
    public static function encode(mixed $value): string
    {
        if (is_string($value)) {
            return self::control(2, strlen($value)).$value;
        }

        if (is_float($value)) {
            return self::control(3, 8).pack('E', $value);
        }

        if (is_int($value)) {
            return self::encode(self::uint32($value));
        }

        if (is_object($value)) {
            $bytes = ltrim(pack('J', $value->v), "\0");

            return ($value->t === 9 ? self::control(0, strlen($bytes)).chr(2) : self::control($value->t, strlen($bytes))).$bytes;
        }

        if (is_array($value) && array_is_list($value)) {
            $out = self::control(0, count($value)).chr(4);
            foreach ($value as $item) {
                $out .= self::encode($item);
            }

            return $out;
        }

        $out = self::control(7, count($value));
        foreach ($value as $key => $item) {
            $out .= self::encode((string) $key).self::encode($item);
        }

        return $out;
    }

    private static function control(int $type, int $size): string
    {
        $type <<= 5;

        return match (true) {
            $size < 29 => chr($type | $size),
            $size < 285 => chr($type | 29).chr($size - 29),
            default => chr($type | 30).substr(pack('n', $size - 285), 0),
        };
    }
}
