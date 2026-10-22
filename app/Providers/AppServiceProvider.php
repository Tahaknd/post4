<?php

namespace App\Providers;

use App\Domain\Capture\EventProducer;
use App\Domain\Capture\PayloadDecoder;
use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Infrastructure\ClickHouse\ClickHouseMigrator;
use App\Infrastructure\Kafka\KafkaEventProducer;
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
