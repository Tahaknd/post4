<?php

namespace App\Domain\Identity;

/**
 * The advisory lock key of one (project, distinct id): the two-integer form `pg_advisory_xact_lock(int, int)`.
 *
 * Postgres keeps the one-bigint and the two-int forms in separate key spaces, so no lock of this file can ever
 * collide with UserCreationLock::KEY (one bigint) or any other single-key lock. The first int is a fixed namespace
 * of identity locks, the second a 32-bit hash of the project and the distinct id. Two different ids can share a
 * hash; that only makes their resolutions wait for each other (never wrong, because they are then also taken in
 * the same order), and with 2^32 values it is rare.
 *
 * A resolution takes the keys of its ids in ASCENDING order of (namespace, hash) and each key once (see
 * sorted()). Every transaction that locks several keys uses that one order, so a cycle of waiting transactions
 * (the deadlock of "A then B" against "B then A") cannot form.
 */
final class IdentityLockKey
{
    /** Arbitrary but fixed; nothing else in the application may use the two-int form with this first key. */
    public const int NAMESPACE = 1_768_187_251;

    /** @return array{int, int} [namespace, hash], both signed 32-bit */
    public static function for(int $projectId, string $distinctId): array
    {
        // xxh32 is non-cryptographic and fast; the NUL separator keeps (1, "2:x") and (12, ":x") apart.
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', hash('xxh32', $projectId."\0".$distinctId, true));

        return [self::NAMESPACE, $unpacked[1] >= 2 ** 31 ? $unpacked[1] - 2 ** 32 : $unpacked[1]];
    }

    /**
     * The keys of the given ids, each once, in the order every transaction must take them.
     *
     * @param  list<string>  $distinctIds
     * @return list<array{int, int}>
     */
    public static function sorted(int $projectId, array $distinctIds): array
    {
        $byHash = [];

        foreach ($distinctIds as $distinctId) {
            $key = self::for($projectId, $distinctId);
            $byHash[$key[1]] = $key;
        }

        ksort($byHash, SORT_NUMERIC);

        return array_values($byHash);
    }

    private function __construct() {}
}
