<?php

/*
 * Child process of the first-user race test (RegisterFirstUserPostgresTest). Not a test itself.
 *
 *   php register_race_worker.php <startAt> <label>
 *
 * Boots the application against the scratch Postgres database given by the DB_* variables the parent
 * passes in, waits until <startAt> (so racing workers start together) and runs RegisterFirstUser once.
 * Prints one JSON line: {"label": ..., "result": "created"|"closed"|"error", "admin": bool}.
 */

use App\Actions\Users\RegisterFirstUser;
use App\Domain\Users\RegistrationClosed;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

[, $startAt, $label] = $argv;

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $user = $app->make(RegisterFirstUser::class)->handle("Worker {$label}", "{$label}@example.test", 'correct horse battery');
    echo json_encode(['label' => $label, 'result' => 'created', 'admin' => $user->is_admin]), PHP_EOL;
} catch (RegistrationClosed) {
    echo json_encode(['label' => $label, 'result' => 'closed', 'admin' => false]), PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['label' => $label, 'result' => 'error', 'message' => $e->getMessage()]), PHP_EOL;
}
