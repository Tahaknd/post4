<?php

namespace Tests\Support;

use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use App\Infrastructure\ClickHouse\ReadOnlyClickHouseClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;

/**
 * Helpers of the opt-in ClickHouse tests (group "clickhouse", see ClickHouseTenantIsolationTest).
 *
 * The phpunit environment pins CLICKHOUSE_URL to a dead address on purpose, so these helpers never go
 * through the app's clickhouse config: they build their OWN admin and read-only clients from
 * CLICKHOUSE_INTEGRATION_URL and explicit passwords (users `posthog` / `posthog_ro`, database `posthog`).
 * They only ever touch rows of the two fixed project ids below and delete exactly those rows afterwards.
 */
final class ClickHouseIntegration
{
    /** Far above any real project id of the dev stack; used by no other test. */
    public const PROJECT_A = 990101;

    public const PROJECT_B = 990102;

    public const PROJECT_C = 990103;

    /** Rows seeded per project. */
    public const ROWS_A = 30;

    public const ROWS_B = 50;

    private function __construct(
        public readonly ClickHouseClient $admin,
        public readonly ReadOnlyClickHouseClient $ro,
        public readonly ProjectScopedReader $reader,
    ) {}

    private static bool $seeded = false;

    /**
     * Seeds once per process (the tests of the file only read) and remembers it, so afterAll() knows whether
     * there is anything to delete.
     */
    public function seedOnce(): void
    {
        if (! self::$seeded) {
            self::$seeded = true;
            $this->seed();
        }
    }

    /**
     * For Pest's afterAll(): no Laravel app exists any more, so the Http facade gets a bare factory first.
     * Does nothing when the variables are missing (that test run already failed with a message) or when
     * nothing was seeded.
     */
    public static function cleanupAfterAll(): void
    {
        if (! self::$seeded) {
            return;
        }
        self::$seeded = false;

        Http::swap(new Factory);
        try {
            self::connect()->cleanup();
        } finally {
            Facade::clearResolvedInstances();
        }
    }

    /** Fails (never skips) when a CLICKHOUSE_INTEGRATION_* variable is missing. */
    public static function connect(): self
    {
        $url = self::env('CLICKHOUSE_INTEGRATION_URL');
        $admin = self::env('CLICKHOUSE_INTEGRATION_ADMIN_PASSWORD');
        $ro = self::env('CLICKHOUSE_INTEGRATION_RO_PASSWORD');

        // Pest.php prevents stray HTTP requests in Feature tests: allow exactly this ClickHouse, nothing else.
        Http::allowStrayRequests([rtrim($url, '/').'*']);

        $roClient = new ReadOnlyClickHouseClient($url, 'posthog', 'posthog_ro', $ro);

        return new self(
            new ClickHouseClient($url, 'posthog', 'posthog', $admin),
            $roClient,
            new ProjectScopedReader($roClient),
        );
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        if (! is_string($value) || $value === '') {
            Assert::fail("{$name} is not set. The clickhouse group needs a real ClickHouse: set CLICKHOUSE_INTEGRATION_URL, "
                .'CLICKHOUSE_INTEGRATION_ADMIN_PASSWORD and CLICKHOUSE_INTEGRATION_RO_PASSWORD, e.g. '
                .'docker compose exec -T -e CLICKHOUSE_INTEGRATION_URL=http://clickhouse:8123 '
                .'-e CLICKHOUSE_INTEGRATION_ADMIN_PASSWORD=posthog -e CLICKHOUSE_INTEGRATION_RO_PASSWORD=posthog_ro '
                .'app php artisan test --group=clickhouse');
        }

        return $value;
    }

    /**
     * Seeds both projects with the admin client: A has 30 rows (20 `it_view`, 10 `it_click`, distinct ids
     * a1..a3, current_url https://a.test/p, browser Firefox, timestamps within the last hour), B has 50 rows (all
     * `it_buy`, distinct ids b1..b5, https://b.test/, Chrome, timestamps about 40 days ago).
     * Starts from a clean slate (rows left behind by an aborted run are removed first).
     */
    public function seed(): void
    {
        if ($this->rowCount() !== 0) {
            $this->cleanup();
        }

        $a = self::PROJECT_A;
        $b = self::PROJECT_B;
        // Separate INSERTs, and project B a month or more in the past: its rows sit in their OWN part and
        // partition (toYYYYMM) that a background merge can never combine with A's, so mergeTreeIndex shows
        // B's primary key values as the first row of a granule of B's part (the S1 leak tests rely on it).
        // The JSON properties fill the MATERIALIZED columns current_url and browser.
        $this->admin->execute(
            'INSERT INTO posthog.events (uuid, project_id, event, distinct_id, properties, timestamp) '
            ."SELECT generateUUIDv4(), {$a}, if(number < 20, 'it_view', 'it_click'), concat('a', toString(number % 3 + 1)), "
            .'\'{"$current_url":"https://a.test/p","$browser":"Firefox"}\', now64(3) - toIntervalMinute(number) FROM numbers('.self::ROWS_A.')',
        );
        $this->admin->execute(
            'INSERT INTO posthog.events (uuid, project_id, event, distinct_id, properties, timestamp) '
            ."SELECT generateUUIDv4(), {$b}, 'it_buy', concat('b', toString(number % 5 + 1)), "
            .'\'{"$current_url":"https://b.test/","$browser":"Chrome"}\', now64(3) - toIntervalDay(40) - toIntervalMinute(number) FROM numbers('.self::ROWS_B.')',
        );

        Assert::assertSame(self::ROWS_A + self::ROWS_B, $this->rowCount(), 'seeding did not produce the expected rows');
    }

    /** Admin lightweight delete of exactly the two test projects, then waits until the rows are gone. */
    public function cleanup(): void
    {
        $this->admin->execute(
            'DELETE FROM posthog.events WHERE project_id IN ('.self::PROJECT_A.', '.self::PROJECT_B.', '.self::PROJECT_C.')',
            settings: ['lightweight_deletes_sync' => 0],
        );

        $deadline = microtime(true) + 30;
        while ($this->rowCount() !== 0) {
            if (microtime(true) > $deadline) {
                Assert::fail('Seeded ClickHouse rows of projects '.self::PROJECT_A.'/'.self::PROJECT_B.' were not deleted within 30 s: remove them by hand');
            }
            usleep(200_000);
        }
    }

    /** Rows of the test projects as the ADMIN sees them (no row policy). */
    public function rowCount(): int
    {
        $rows = $this->admin->select(
            'SELECT count() AS n FROM posthog.events WHERE project_id IN ('.self::PROJECT_A.', '.self::PROJECT_B.', '.self::PROJECT_C.')',
        );

        return (int) $rows[0]['n'];
    }
}
