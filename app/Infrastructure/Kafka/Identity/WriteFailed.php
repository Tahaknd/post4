<?php

namespace App\Infrastructure\Kafka\Identity;

use RuntimeException;

/** Kafka did not acknowledge what the identity consumer produced. */
final class WriteFailed extends RuntimeException {}
