<?php

namespace App\Providers;

use App\Domain\Capture\EventProducer;
use App\Domain\Capture\KafkaLagProbe;
use App\Domain\Capture\PayloadDecoder;
use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Runs\InsightGate;
use App\Domain\Insights\Runs\InsightRunStore;
use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Infrastructure\ClickHouse\ClickHouseMigrator;
use App\Infrastructure\ClickHouse\ClickHouseReads;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use App\Infrastructure\ClickHouse\ReadOnlyClickHouseClient;
use App\Infrastructure\ClickHouse\RoSettings;
use App\Infrastructure\Kafka\KafkaEventProducer;
use App\Infrastructure\Kafka\RdKafkaLagProbe;
use App\Models\Project;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
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

        // Distinct binding (the admin ClickHouseClient above is unaffected). The constructor fails fast
        // when the password is empty. ClickHouseReads is deliberately NOT bound globally (the admin
        // client implements it too); only the scoped reader gets the read-only client.
        $this->app->singleton(ReadOnlyClickHouseClient::class, fn () => new ReadOnlyClickHouseClient(
            config('clickhouse.url'),
            config('clickhouse.database'),
            config('clickhouse.readonly.user'),
            (string) config('clickhouse.readonly.password'),
            (int) config('clickhouse.readonly.timeout'),
            (int) config('clickhouse.connect_timeout'),
            (int) config('clickhouse.readonly.select_retries'),
            (int) config('clickhouse.readonly.select_retry_backoff_ms'),
        ));
        $this->app->when(ProjectScopedReader::class)->needs(ClickHouseReads::class)->give(ReadOnlyClickHouseClient::class);

        // The insight classes keep their state on the insight store (config('insights.cache_store'), Redis), never on the
        // DEFAULT cache store (CACHE_STORE), which the container would otherwise inject for a Repository argument.
        $this->app->when([InsightRunner::class, InsightGate::class, InsightRunStore::class])
            ->needs(CacheRepository::class)
            ->give(fn () => Cache::store((string) config('insights.cache_store')));
        $this->app->singleton(InsightRunner::class);
        $this->app->bind(InsightRunStore::class);
        $this->app->bind(InsightGate::class);

        $this->app->singleton(ClickHouseMigrator::class, fn ($app) => new ClickHouseMigrator(
            $app->make(ClickHouseClient::class),
            database_path('clickhouse/migrations'),
            // Views in the migrations read the custom setting with getSetting(), so it must be known at CREATE.
            settings: [RoSettings::SQL_PROJECT_ID => 0],
        ));

        $this->app->singleton(EventProducer::class, fn () => new KafkaEventProducer(
            config('capture.kafka_brokers'),
            config('capture.events_topic'),
            messageTimeoutMs: (int) config('capture.kafka_message_timeout_ms'),
            flushTimeoutMs: (int) config('capture.kafka_flush_timeout_ms'),
            breakerCooldownSeconds: (float) config('capture.kafka_breaker_cooldown_seconds'),
        ));

        $this->app->singleton(KafkaLagProbe::class, fn () => new RdKafkaLagProbe(
            config('capture.kafka_brokers'),
            config('capture.events_topic'),
            config('capture.status.kafka_group'),
            (int) config('capture.status.kafka_timeout_ms'),
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

        // `{project}` is 1 to 18 digits for every route that has it (a bigint cannot take more; anything else never
        // reaches Postgres as a query error).
        Route::pattern('project', '[0-9]{1,18}');

        // `{project}` resolves only among the projects the signed-in user may open, so one they cannot open is a 404
        // (not a 403) and ids do not leak. SubstituteBindings runs after Authenticate, so the user is known; the
        // default guard is asked through the request first and Auth second, and a missing user finds nothing (404).
        // An id that arrives in a BODY is not bound by this: resolve it with Project::visibleTo($user)->find()
        // (see App\Http\Requests\Concerns\ResolvesVisibleProject) from FormRequest::authorize(), answering 404.
        Route::bind('project', function (string $value): Project {
            $user = request()->user() ?? Auth::user();
            abort_if($user === null, 404);

            return Project::query()->visibleTo($user)->whereKey($value)->firstOrFail();
        });
    }

    /**
     * Read here (not in bootstrap/app.php) because config is not loaded yet while middleware is configured.
     * With nothing configured the middleware trusts no proxy, so forwarded headers are ignored.
     */
    protected function configureTrustedProxies(): void
    {
        $proxies = config('proxies.trusted');
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
