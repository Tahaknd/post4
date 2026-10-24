<?php

namespace App\Domain\Users;

use RuntimeException;

final class RegistrationClosed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Registration is closed: an account already exists.');
    }
}
