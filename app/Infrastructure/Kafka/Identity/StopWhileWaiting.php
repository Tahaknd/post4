<?php

namespace App\Infrastructure\Kafka\Identity;

use RuntimeException;

/** Internal to IdentityConsumer: a stop was requested while a failed step waited to be repeated. */
final class StopWhileWaiting extends RuntimeException {}
