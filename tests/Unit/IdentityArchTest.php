<?php

use App\Console\Commands\IdentityConsume;
use App\Domain\Identity\IdentityResolver;
use App\Infrastructure\Kafka\Identity\IdentityMessageProcessor;
use Illuminate\Support\Facades\DB;

/*
 * The identity domain is pure PHP plus ONE class that talks to Postgres. It must not reach into the HTTP layer, the
 * console, the queue jobs or the infrastructure (ClickHouse, Kafka): the consumer of Milestone 4.2 calls the resolver
 * and turns its result into Kafka messages, the domain never produces them itself.
 */

arch('the identity domain depends on neither the HTTP layer, the console, the jobs nor the infrastructure')
    ->expect('App\Domain\Identity')
    ->not->toUse([
        'App\Http',
        'App\Console',
        'App\Jobs',
        'App\Infrastructure',
        'Illuminate\Http',
        'Illuminate\Support\Facades\Http',
    ]);

arch('only the resolver of the identity domain uses the database')
    ->expect('App\Domain\Identity')
    ->not->toUse([DB::class, 'Illuminate\Database'])
    ->ignoring(IdentityResolver::class);

// The identity consumer is the one caller: the command builds it, the processor calls the resolver. The Kafka loop,
// the adapters and the dead-letter and row classes know nothing about persons or SQL (next test).
arch('nothing outside the identity domain, the consumer and the container wiring uses the resolver')
    ->expect(IdentityResolver::class)
    ->toOnlyBeUsedIn([
        'App\Domain\Identity',
        'App\Providers\AppServiceProvider',
        IdentityMessageProcessor::class,
        IdentityConsume::class,
    ]);

arch('only the processor of the identity consumer uses the database: the loop and the Kafka adapters do not')
    ->expect('App\Infrastructure\Kafka\Identity')
    ->not->toUse([DB::class, 'Illuminate\Database', 'App\Models', IdentityResolver::class])
    ->ignoring(IdentityMessageProcessor::class);

it('writes persons and their distinct ids only inside the identity resolver', function () {
    // The version column, the merge order and the locks are the resolver's contract: a write from anywhere else
    // (a query builder call, raw SQL, a model) would bypass all three. Deleting a project removes both tables'
    // rows through the foreign key, which needs no write here.
    $offenders = [];
    // These name the CLICKHOUSE table of the same name (the registry of scoped relations, the project purge jobs
    // that delete a deleted project's rows there): they never touch the Postgres tables.
    $clickHouseSide = [
        'Infrastructure/ClickHouse/ScopedRelations.php',
        'Jobs/PurgeProjectRows.php',
        'Jobs/PurgeProjectEvents.php',
        'Jobs/PurgeProjectPersonDistinctIds.php',
        'Infrastructure/ClickHouse/PersonDistinctIdProjectIds.php',
    ];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = str_replace(app_path().'/', '', $file->getPathname());

        if ($file->getExtension() !== 'php' || $relative === 'Domain/Identity/IdentityResolver.php' || in_array($relative, $clickHouseSide, true)) {
            continue;
        }

        // Comments removed (tokenised, so a "#" or "//" inside a string hides nothing): prose may name the tables.
        $source = '';
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            $source .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
        }

        if (preg_match('/[\'"`]\s*(persons|person_distinct_ids)\s*[\'"`]|\b(from|into|update|join|truncate)\s+["`]?(persons|person_distinct_ids)\b/i', $source) === 1
            || preg_match('/\b(Person|PersonDistinctId)::|new\s+(Person|PersonDistinctId)\b/', $source) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});
