<?php

/*
 * Child process of the identity race tests (tests/Integration/IdentityResolverRaceTest.php). Not a test itself.
 *
 *   php identity_race_worker.php <label> <barrierFile> <jsonEvents>
 *
 * Boots the whole application against the database of the DB_* variables the parent passes in (and refuses unless
 * that is the throwaway Postgres, by the same rule as TestCase), prints "READY", waits until <barrierFile> exists (so
 * racing workers are released together), applies the events in order with IdentityResolver, and prints ONE JSON
 * line: {"label": ..., "results": [{outcome, reason, personId, deletedPersonId, changes}...]} or, if any resolution
 * threw, {"label": ..., "error": "<class>: <message>", "sqlstate": ...}.
 *
 * <jsonEvents> is a list of {"project": int, "kind": "$identify", "a": "<distinct id>", "b": "<other id>"}.
 */

use App\Domain\Identity\IdentityEvent;
use App\Domain\Identity\IdentityKind;
use App\Domain\Identity\IdentityResolver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Tests\Support\TestDatabaseGuard;

require __DIR__.'/../../vendor/autoload.php';

[, $label, $barrier, $json] = $argv;

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$problem = TestDatabaseGuard::problem('pgsql', (array) config('database.connections.pgsql'), getenv('TESTS_ALLOW_PGSQL'));

if (config('database.default') !== 'pgsql' || $problem !== null) {
    echo json_encode(['label' => $label, 'error' => 'refused: '.($problem ?? 'default connection is not pgsql')]), PHP_EOL;
    exit(1);
}

echo "READY\n";
fflush(STDOUT);

$deadline = microtime(true) + 60;
while (! file_exists($barrier) && microtime(true) < $deadline) {
    usleep(300);
}

try {
    $resolver = $app->make(IdentityResolver::class);
    $results = [];

    foreach (json_decode($json, true, flags: JSON_THROW_ON_ERROR) as $spec) {
        $result = $resolver->apply(new IdentityEvent($spec['project'], IdentityKind::from($spec['kind']), $spec['a'], $spec['b']));

        $results[] = [
            'outcome' => $result->outcome->value,
            'reason' => $result->reason?->value,
            'personId' => $result->personId,
            'deletedPersonId' => $result->deletedPersonId,
            'changes' => array_map(fn ($change) => [$change->distinctId, $change->personId, $change->version], $result->changes),
        ];
    }

    echo json_encode(['label' => $label, 'results' => $results]), PHP_EOL;
} catch (Throwable $e) {
    echo json_encode([
        'label' => $label,
        'error' => $e::class.': '.$e->getMessage(),
        'sqlstate' => $e instanceof QueryException ? $e->errorInfo[0] ?? null : null,
    ]), PHP_EOL;
}
