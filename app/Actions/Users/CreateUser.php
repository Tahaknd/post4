<?php

namespace App\Actions\Users;

use App\Domain\Users\UserCreationLock;
use App\Models\User;
use SensitiveParameter;

/**
 * The only place that writes a user row, always under the user-creation lock (see UserCreationLock).
 * Callers validate first (UserRules); this does not.
 */
final class CreateUser
{
    public function handle(string $name, string $email, #[SensitiveParameter] string $password, bool $admin = false): User
    {
        return UserCreationLock::run(function () use ($name, $email, $password, $admin): User {
            $user = new User(['name' => $name, 'email' => $email]);

            // Assigned, not mass-assigned: the password stays out of the arguments of every Eloquent frame
            // below, and is_admin is not mass-assignable on purpose.
            $user->password = $password;
            $user->is_admin = $admin;
            $user->save();

            return $user;
        });
    }
}
