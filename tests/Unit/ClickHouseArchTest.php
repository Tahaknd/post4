<?php

use App\Infrastructure\ClickHouse\ClickHouseClient;
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

arch('the admin client is used only by ClickHouse infrastructure, its wiring and capture:status')
    ->expect(ClickHouseClient::class)
    ->toOnlyBeUsedIn([
        'App\Infrastructure\ClickHouse',
        'App\Providers\AppServiceProvider',
        'App\Console\Commands\CaptureStatus',
    ]);

arch('the scoped reader is the only class that takes the read-only client as a dependency')
    ->expect('App')
    ->not->toUse(ReadOnlyClickHouseClient::class)
    ->ignoring([
        'App\Infrastructure\ClickHouse',
        'App\Providers\AppServiceProvider',
    ]);
