<?php

namespace App\Providers;

use App\Domain\Capture\EventProducer;
use App\Domain\Capture\PayloadDecoder;
use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Infrastructure\ClickHouse\ClickHouseMigrator;
use App\Infrastructure\ClickHouse\ReadOnlyClickHouseClient;
use App\Infrastructure\Kafka\KafkaEventProducer;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
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
            (int) config('clickhouse.timeout'),
            (int) config('clickhouse.connect_timeout'),
        ));

        // Distinct binding (the admin ClickHouseClient above is unaffected): query builders type-hint
        // ProjectScopedReader, which takes this one.
        $this->app->singleton(ReadOnlyClickHouseClient::class, fn () => new ReadOnlyClickHouseClient(
            config('clickhouse.url'),
            config('clickhouse.database'),
            config('clickhouse.readonly.user'),
            config('clickhouse.readonly.password'),
            (int) config('clickhouse.timeout'),
            (int) config('clickhouse.connect_timeout'),
        ));

        $this->app->singleton(ClickHouseMigrator::class, fn ($app) => new ClickHouseMigrator(
            $app->make(ClickHouseClient::class),
            database_path('clickhouse/migrations'),
        ));

        $this->app->singleton(EventProducer::class, fn () => new KafkaEventProducer(
            config('capture.kafka_brokers'),
            config('capture.events_topic'),
            messageTimeoutMs: (int) config('capture.kafka_message_timeout_ms'),
            flushTimeoutMs: (int) config('capture.kafka_flush_timeout_ms'),
            breakerCooldownSeconds: (float) config('capture.kafka_breaker_cooldown_seconds'),
        ));

        $this->app->singleton(PayloadDecoder::class, fn () => new PayloadDecoder(
            (int) config('capture.max_decoded_bytes'),
            (int) config('capture.max_batch_size'),
            (int) config('capture.max_containers', 100000),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction());

        $this->configureTrustedProxies();
    }

    /**
     * Read here (not in bootstrap/app.php) because config is not loaded yet while middleware is configured.
     * With nothing configured the middleware trusts no proxy, so forwarded headers are ignored.
     */
    protected function configureTrustedProxies(): void
    {
        $proxies = config('capture.trusted_proxies');
        if ($proxies === null) {
            return;
        }

        TrustProxies::at($proxies);
        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
        );
    }
}
