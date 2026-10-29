<?php

namespace App\Infrastructure\Kafka\Identity;

use RuntimeException;

/**
 * Thrown when one step kept failing for the whole retry budget. Nothing past the failing message was committed, so
 * the supervisor restarts the process and the message is delivered again.
 */
final class ConsumerGaveUp extends RuntimeException {}
