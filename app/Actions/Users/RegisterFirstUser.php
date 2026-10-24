<?php

namespace App\Actions\Users;

use App\Domain\Users\RegistrationClosed;
use App\Domain\Users\UserCreationLock;
use App\Models\User;
use SensitiveParameter;

/**
 * Creates the very first account, which becomes the administrator, and refuses every later one.
 *
 * Race-freedom: the "is there a user yet?" check and the insert run under UserCreationLock, which every
 * user creation takes. Two simultaneous first registrations therefore run one after the other: the second
 * blocks until the first commits, then its check (a new statement under READ COMMITTED) sees the committed
 * row and throws RegistrationClosed.
 */
final class RegisterFirstUser
{
    public function __construct(private readonly CreateUser $createUser) {}

    /**
     * @throws RegistrationClosed when any user already exists
     */
    public function handle(string $name, string $email, #[SensitiveParameter] string $password): User
    {
        return UserCreationLock::run(function () use ($name, $email, $password): User {
            if (User::query()->exists()) {
                throw new RegistrationClosed;
            }

            return $this->createUser->handle($name, $email, $password, admin: true);
        });
    }
}
