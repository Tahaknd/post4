<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates the very first account, which becomes the administrator, and refuses every later one.
 *
 * Race-freedom: the "is there a user yet?" check and the insert run in one transaction that first
 * takes a transaction-scoped Postgres advisory lock. Two simultaneous first registrations therefore
 * run one after the other: the second one blocks on the lock until the first commits, then its
 * check (a new statement under READ COMMITTED) sees the committed row and throws RegistrationClosed.
 * Nothing but the lock could do this: with zero rows there is no row to `SELECT ... FOR UPDATE`, and a
 * unique index cannot express "only the first row is admin". The lock is released automatically at
 * commit or rollback, so a crash cannot leave registration stuck.
 *
 * Other drivers (the SQLite test database) have no advisory locks; there the transaction alone is
 * all we have, which is fine because only Postgres is supported in production.
 */
final class RegisterFirstUser
{
    /** Arbitrary but fixed 64-bit key of the advisory lock; nothing else in the application may use it. */
    public const int LOCK_KEY = 7_036_540_218_461_005;

    public function __construct(private readonly CreateUser $createUser) {}

    /**
     * @throws RegistrationClosed when any user already exists
     */
    public function handle(string $name, string $email, string $password): User
    {
        return DB::transaction(function () use ($name, $email, $password): User {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
            }

            if (User::query()->exists()) {
                throw new RegistrationClosed;
            }

            return $this->createUser->handle($name, $email, $password, admin: true);
        });
    }
}
