<?php

namespace App\Actions\Users;

use App\Models\User;

/**
 * The only place that writes a user row. Callers validate first (UserRules); this does not.
 */
final class CreateUser
{
    public function handle(string $name, string $email, string $password, bool $admin = false): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);

        // is_admin is not mass-assignable on purpose.
        $user->is_admin = $admin;
        $user->save();

        return $user;
    }
}
