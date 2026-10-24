<?php

/*
 * Child process of the first-user race test (RegisterFirstUserPostgresTest). Not a test itself.
 *
 *   php register_race_worker.php <label> <mode> <barrierFile>
 *
 * Boots the application against the scratch Postgres database given by the DB_* variables the parent passes
 * in, prints "READY", waits until <barrierFile> exists (so racing workers are released together) and then
 * does one creation, printing one JSON line: {"label": ..., "result": "created"|"closed"|"error", "admin": bool}.
 *
 * Modes:
 *   register       RegisterFirstUser::handle() (the real registration, under UserCreationLock)
 *   create         CreateUser::handle() as `user:create` calls it (also under UserCreationLock)
 *   register_nolock  the same check-then-insert WITHOUT the lock and with a pause between check and insert: the
 *                  negative control that shows the test can see two administrators when nothing serialises them
 */

use App\Actions\Users\CreateUser;
use App\Actions\Users\RegisterFirstUser;
use App\Domain\Users\RegistrationClosed;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

[, $label, $mode, $barrier] = $argv;

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo "READY\n";
fflush(STDOUT);

$deadline = microtime(true) + 60;
while (! file_exists($barrier) && microtime(true) < $deadline) {
    usleep(500);
}

try {
    $email = "{$label}@example.test";

    $user = match ($mode) {
        'register' => $app->make(RegisterFirstUser::class)->handle("Worker {$label}", $email, 'correct horse battery'),
        'create' => $app->make(CreateUser::class)->handle("Worker {$label}", $email, 'correct horse battery', admin: true),
        'register_nolock' => DB::transaction(function () use ($label, $email) {
            if (User::query()->exists()) {
                throw new RegistrationClosed;
            }

            usleep(300_000);

            $user = new User(['name' => "Worker {$label}", 'email' => $email]);
            $user->password = 'correct horse battery';
            $user->is_admin = true;
            $user->save();

            return $user;
        }),
    };

    echo json_encode(['label' => $label, 'result' => 'created', 'admin' => $user->is_admin]), PHP_EOL;
} catch (RegistrationClosed) {
    echo json_encode(['label' => $label, 'result' => 'closed', 'admin' => false]), PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['label' => $label, 'result' => 'error', 'message' => $e->getMessage()]), PHP_EOL;
}
