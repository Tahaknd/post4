<?php

namespace App\Domain\Identity;

use RuntimeException;

/**
 * Thrown inside the resolver's transaction when a person it read was changed or deleted by a transaction that
 * committed before the row locks were granted. The transaction rolls back (releasing every lock) and starts over;
 * it never reaches the caller unless the state keeps changing under every attempt.
 */
final class StaleIdentityState extends RuntimeException {}
