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
 * They only ever touch rows of the fixed project ids below (990101-3 tenant tests, 990201-4 trends tests) and delete
 * exactly those rows afterwards. The ids are FIXED, not random, because the hand-computed expectations name them and
 * the seed starts from a clean slate: run the clickhouse group sequentially (one run at a time against one ClickHouse).
 */
final class ClickHouseIntegration
{
    /** Far above any real project id of the dev stack; used by no other test. */
    public const PROJECT_A = 990101;

    public const PROJECT_B = 990102;

    public const PROJECT_C = 990103;

    /** Projects of the trends tests (their own rows: TrendsIntegrationTest, BucketAxisOracleTest). C never gets a row. */
    public const TREND_A = 990201;

    public const TREND_B = 990202;

    public const TREND_C = 990203;

    /** Santiago DST gap day (BucketAxisOracleTest): three rows around the missing local midnight of 2023-09-03. */
    public const TREND_D = 990204;

    /** Rows seeded per project. */
    public const ROWS_A = 30;

    public const ROWS_B = 50;

    private function __construct(
        public readonly ClickHouseClient $admin,
        public readonly ReadOnlyClickHouseClient $ro,
        public readonly ProjectScopedReader $reader,
    ) {}

    private static bool $seeded = false;

    private static bool $trendsSeeded = false;

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

    /** Seeds the trends projects once per process (see seedTrends()); afterAll cleans up via cleanupTrendsAfterAll(). */
    public function seedTrendsOnce(): void
    {
        if (! self::$trendsSeeded) {
            self::$trendsSeeded = true;
            $this->seedTrends();
        }
    }

    /** Like cleanupAfterAll() for the trends projects. */
    public static function cleanupTrendsAfterAll(): void
    {
        if (! self::$trendsSeeded) {
            return;
        }
        self::$trendsSeeded = false;

        Http::swap(new Factory);
        try {
            self::connect()->cleanupTrends();
        } finally {
            Facade::clearResolvedInstances();
        }
    }

    /**
     * Fixed, hand-computed rows (fixed instants, not relative to now) for TrendsIntegrationTest. Every row is
     * its own INSERT with a fixed uuid. Project A (990201), `ts` in UTC:
     *   a1  signup   u1 03-14 21:30 plan=pro  Chrome        a2 signup u2 03-14 22:30 plan=free Firefox
     *   a12 signup   u1 03-14 23:00 plan=free Chrome        a11 signup u1 03-15 08:00 plan=pro Chrome
     *   a3  signup   u1 03-15 10:00 plan=pro  Chrome        a4 signup u3 03-15 10:00 plan=null Safari
     *   a6  signup   u2 03-16 09:00 (no plan) Firefox, inserted TWICE with the same uuid
     *   a13 signup   u1 03-31 21:30 plan=pro  Chrome        a5 purchase u1 03-15 11:00 plan=pro Chrome
     *   a10 purchase u2 03-16 23:30 plan=free               a9 other_event u1 03-15 12:00
     *   a7  signup   u1 02-10 12:00 and a8 signup u1 04-20 12:00 (both outside every tested range)
     * Project B (990202): signup u9 x3 (03-14 22:00, 22:10, 22:20) and u1 x4 (03-15 10:00..10:03) with
     * plan=enterprise, Edge; purchase u1 03-15 11:00. Project C has no rows.
     */
    public function seedTrends(): void
    {
        $this->cleanupTrends();

        $a = self::TREND_A;
        $b = self::TREND_B;
        $n = 0;
        $row = function (int $project, string $event, string $distinct, string $ts, array $props = [], ?int $uuidNumber = null) use (&$n): void {
            $uuid = sprintf('00000000-0000-4000-8000-%012d', $uuidNumber ?? ++$n);
            $this->admin->execute(
                'INSERT INTO posthog.events (uuid, project_id, event, distinct_id, properties, timestamp) '
                ."SELECT {uuid:UUID}, {p:UInt32}, {e:String}, {d:String}, {props:String}, parseDateTime64BestEffort({ts:String}, 3, 'UTC')",
                ['uuid' => $uuid, 'p' => $project, 'e' => $event, 'd' => $distinct, 'props' => json_encode($props, JSON_THROW_ON_ERROR), 'ts' => $ts],
            );
        };

        $row($a, 'signup', 'u1', '2026-03-14 21:30:00', ['plan' => 'pro', '$browser' => 'Chrome']);
        $row($a, 'signup', 'u2', '2026-03-14 22:30:00', ['plan' => 'free', '$browser' => 'Firefox']);
        $row($a, 'signup', 'u1', '2026-03-14 23:00:00', ['plan' => 'free', '$browser' => 'Chrome']);
        $row($a, 'signup', 'u1', '2026-03-15 08:00:00', ['plan' => 'pro', '$browser' => 'Chrome']);
        $row($a, 'signup', 'u1', '2026-03-15 10:00:00', ['plan' => 'pro', '$browser' => 'Chrome']);
        $row($a, 'signup', 'u3', '2026-03-15 10:00:00', ['plan' => null, '$browser' => 'Safari']);
        $row($a, 'signup', 'u2', '2026-03-16 09:00:00', ['$browser' => 'Firefox'], 900);
        $row($a, 'signup', 'u2', '2026-03-16 09:00:00', ['$browser' => 'Firefox'], 900); // same uuid again
        $row($a, 'signup', 'u1', '2026-03-31 21:30:00', ['plan' => 'pro', '$browser' => 'Chrome']);
        $row($a, 'purchase', 'u1', '2026-03-15 11:00:00', ['plan' => 'pro', '$browser' => 'Chrome']);
        $row($a, 'purchase', 'u2', '2026-03-16 23:30:00', ['plan' => 'free']);
        $row($a, 'other_event', 'u1', '2026-03-15 12:00:00');
        $row($a, 'signup', 'u1', '2026-02-10 12:00:00');
        $row($a, 'signup', 'u1', '2026-04-20 12:00:00');

        foreach (['2026-03-14 22:00:00', '2026-03-14 22:10:00', '2026-03-14 22:20:00'] as $ts) {
            $row($b, 'signup', 'u9', $ts, ['plan' => 'enterprise', '$browser' => 'Edge']);
        }
        foreach (['10:00', '10:01', '10:02', '10:03'] as $time) {
            $row($b, 'signup', 'u1', "2026-03-15 {$time}:00", ['plan' => 'enterprise', '$browser' => 'Edge']);
        }
        $row($b, 'purchase', 'u1', '2026-03-15 11:00:00', ['plan' => 'enterprise', '$browser' => 'Edge']);

        // 22 inserts; the duplicate uuid collapses to one row only when a background merge ran (21), never fewer.
        // Project D (Santiago gap): 2023-09-03 00:00 local (-04) does not exist, the clocks jump to 01:00 (-03) at 04:00 UTC.
        $row(self::TREND_D, 'visit', 'd1', '2023-09-03 03:30:00');   // Sep 2 23:30 local
        $row(self::TREND_D, 'visit', 'd1', '2023-09-03 04:30:00');   // Sep 3 01:30 local, after the gap
        $row(self::TREND_D, 'visit', 'd2', '2023-09-04 12:00:00');   // Sep 4 09:00 local

        // Typed property values (25.8: JSONExtractString turns a number or a boolean into its text), UTC day 2023-09-05.
        $row(self::TREND_D, 'typed', 'd3', '2023-09-05 12:00:00', ['n' => 5, 'flag' => true, 'ratio' => 1.5, 'nothing' => null]);

        Assert::assertContains($this->trendRowCount(), [25, 26], 'trends seeding did not produce the expected rows');
    }

    /** Lightweight delete of exactly the trends projects, then waits until the rows are gone. */
    public function cleanupTrends(): void
    {
        $ids = self::TREND_A.', '.self::TREND_B.', '.self::TREND_C.', '.self::TREND_D;
        $this->admin->execute("DELETE FROM posthog.events WHERE project_id IN ({$ids})", settings: ['lightweight_deletes_sync' => 0]);

        $deadline = microtime(true) + 30;
        while ($this->trendRowCount() !== 0) {
            if (microtime(true) > $deadline) {
                Assert::fail("Seeded trends rows of projects {$ids} were not deleted within 30 s: remove them by hand");
            }
            usleep(200_000);
        }
    }

    /** Physical rows of the trends projects as the admin sees them (a duplicate uuid counts twice until merged). */
    public function trendRowCount(): int
    {
        $rows = $this->admin->select('SELECT count() AS n FROM posthog.events WHERE project_id IN ('.self::TREND_A.', '.self::TREND_B.', '.self::TREND_C.', '.self::TREND_D.')');

        return (int) $rows[0]['n'];
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
