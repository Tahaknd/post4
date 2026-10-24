<?php

namespace App\Domain\Users;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Serialises everything that creates a user: the first-user registration and `user:create` take this same
 * lock, so "is there a user yet?" followed by "insert the administrator" cannot interleave with another
 * creation, whichever path it comes from.
 *
 * A transaction-scoped Postgres advisory lock: a second caller blocks on it until the first commits or rolls
 * back, and the database releases it by itself on commit, rollback or a dropped connection (nothing can leave
 * user creation stuck). With zero rows there is no row to `SELECT ... FOR UPDATE`, and a unique index cannot
 * express "only the first row is admin", so the lock is the only primitive that fits. It is re-entrant within
 * one session, so a creation inside a creation is fine. Other drivers (the SQLite test database) have no
 * advisory locks and get the plain transaction; only Postgres is supported in production.
 */
final class UserCreationLock
{
    /** Arbitrary but fixed 64-bit key; nothing else in the application may use it. */
    public const int KEY = 7_036_540_218_461_005;

    /**
     * Runs $callback inside a transaction that holds the lock.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Closure $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(?)', [self::KEY]);
            }

            return $callback();
        });
    }

    private function __construct() {}
}
