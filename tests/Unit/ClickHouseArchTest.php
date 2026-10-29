<?php

use App\Domain\Insights\Events\EventNames;
use App\Domain\Insights\InsightRunner;
use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use App\Infrastructure\ClickHouse\ReadOnlyClickHouseClient;

/*
 * Tenant isolation lives in ProjectScopedReader: nothing else may read events through the read-only client,
 * and the admin client (write access, no row policy) stays in infrastructure code. A new user of either class
 * must be added here on purpose, which is the moment to ask whether it should go through the scoped reader.
 */

arch('only the ClickHouse infrastructure and its container wiring use the read-only client')
    ->expect(ReadOnlyClickHouseClient::class)
    ->toOnlyBeUsedIn([
        'App\Infrastructure\ClickHouse',
        'App\Providers\AppServiceProvider',
    ]);

arch('the admin client is used only by ClickHouse infrastructure (capture:status reads through IngestStatusQueries), its wiring and the project purge jobs')
    ->expect(ClickHouseClient::class)
    ->toOnlyBeUsedIn([
        'App\Infrastructure\ClickHouse',
        'App\Providers\AppServiceProvider',
        'App\Jobs\PurgeProjectRows',
    ]);

arch('the scoped reader is the only class that takes the read-only client as a dependency')
    ->expect('App')
    ->not->toUse(ReadOnlyClickHouseClient::class)
    ->ignoring([
        'App\Infrastructure\ClickHouse',
        'App\Providers\AppServiceProvider',
    ]);

it('names the scoped views in no file of app/ except the reader, its relation registry and their docs', function () {
    // Builders and domain code read project_events / project_person_distinct_ids (the CTEs the reader prepends),
    // never a view or a table.
    $allowed = [
        'Infrastructure/ClickHouse/ProjectScopedReader.php',
        'Infrastructure/ClickHouse/ReadOnlyClickHouseClient.php',
        'Infrastructure/ClickHouse/RoSettings.php',
        'Infrastructure/ClickHouse/ScopedRelation.php',
        'Infrastructure/ClickHouse/ScopedRelations.php',
    ];
    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = str_replace(app_path().'/', '', $file->getPathname());
        $source = (string) file_get_contents($file->getPathname());
        if ($file->getExtension() === 'php' && ! in_array($relative, $allowed, true)
            && (str_contains($source, 'project_events_v') || str_contains($source, 'project_person_distinct_ids_v'))) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});

arch('only the insight runner, the event-name list, the ClickHouse infrastructure and the container wiring use the scoped reader')
    ->expect(ProjectScopedReader::class)
    ->toOnlyBeUsedIn([
        InsightRunner::class,
        EventNames::class,
        'App\Infrastructure\ClickHouse',
        'App\Providers\AppServiceProvider',
    ]);

it('creates a BuiltQuery only inside classes named *SqlBuilder', function () {
    // SQL text for the reader is produced by the constants-only builders; nothing else may hand it one.
    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && ! str_ends_with($file->getBasename('.php'), 'SqlBuilder')
            && preg_match('/new\s+\\\\?(?:[A-Za-z\\\\]+\\\\)?BuiltQuery\b/', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = str_replace(app_path().'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});
