<?php

namespace App\Providers;

use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Infrastructure\ClickHouse\ClickHouseMigrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClickHouseClient::class, fn () => new ClickHouseClient(
            config('clickhouse.url'),
            config('clickhouse.database'),
            config('clickhouse.user'),
            config('clickhouse.password'),
        ));

        $this->app->singleton(ClickHouseMigrator::class, fn ($app) => new ClickHouseMigrator(
            $app->make(ClickHouseClient::class),
            database_path('clickhouse/migrations'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
