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
 * They only ever touch rows of the fixed project ids below (990101-3 tenant tests, 990201-4 trends tests, 990501-2 purge tests) and delete
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

    /** Projects of the purge tests (PurgeProjectEventsClickHouseTest): one is purged, the other must be untouched. */
    public const PURGE_A = 990501;

    public const PURGE_B = 990502;

    /** Tenants of the insight API tests (InsightApiClickHouseTest): A and B have rows, C (990603) never gets one. */
    public const API_A = 990601;

    public const API_B = 990602;

    public const API_C = 990603;

    /** Tenants of the event-name list tests (InsightEventNamesClickHouseTest): A and B have a few names, C has 105, D exactly 100. */
    public const EVENTS_A = 990701;

    public const EVENTS_B = 990702;

    public const EVENTS_C = 990703;

    public const EVENTS_D = 990704;

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

    private static bool $apiSeeded = false;

    private static bool $eventNamesSeeded = false;

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

    /** Seeds the insight API tenants once per process (see seedApi()); afterAll cleans up via cleanupApiAfterAll(). */
    public function seedApiOnce(): void
    {
        if (! self::$apiSeeded) {
            self::$apiSeeded = true;
            $this->seedApi();
        }
    }

    /** Seeds the event-name tenants once per process (see seedEventNames()); afterAll cleans up via cleanupEventNamesAfterAll(). */
    public function seedEventNamesOnce(): void
    {
        if (! self::$eventNamesSeeded) {
            self::$eventNamesSeeded = true;
            $this->seedEventNames();
        }
    }

    /** Like cleanupAfterAll() for the event-name tenants. */
    public static function cleanupEventNamesAfterAll(): void
    {
        if (! self::$eventNamesSeeded) {
            return;
        }
        self::$eventNamesSeeded = false;

        Http::swap(new Factory);
        try {
            self::connect()->deleteRowsOf([self::EVENTS_A, self::EVENTS_B, self::EVENTS_C, self::EVENTS_D]);
        } finally {
            Facade::clearResolvedInstances();
        }
    }

    /**
     * Fixed rows (UTC) of the event-name tenants, for a clock fixed at 2026-03-20 08:00 UTC, i.e. the window
     * [2026-02-19 00:00, 2026-03-21 00:00). Project A (990701), counts inside the window:
     *   signup 5 (the last one later today, 12:00), $pageview 3, purchase 2, `Sign Out` 1, `a%b_c` 2, `it's` 1,
     *   `back\slash` 1, `ünï日本` 1, edge_start 1 (exactly 02-19 00:00:00.000, inclusive), edge_before_end 1 (03-20 23:59:59.999),
     *   a 200 character name 1; OUTSIDE: old_event 4 (02-18 23:59:59.999), edge_end 1 (03-21 00:00:00), a 201 character name 1.
     * Project B (990702): signup 7, b_only 2. Project C (990703): 105 names n000..n104 once each plus z_top three times.
     * Project D (990704): exactly 100 names d000..d099 once each.
     */
    public function seedEventNames(): void
    {
        $this->deleteRowsOf([self::EVENTS_A, self::EVENTS_B, self::EVENTS_C, self::EVENTS_D]);

        $rows = [];
        $row = function (int $project, string $event, string $ts, int $times = 1) use (&$rows): void {
            for ($i = 0; $i < $times; $i++) {
                $rows[] = [$project, $event, 'u'.count($rows), '{}', $ts];
            }
        };

        $a = self::EVENTS_A;
        foreach (['2026-02-20 10:00:00', '2026-03-01 10:00:00', '2026-03-10 10:00:00', '2026-03-19 10:00:00', '2026-03-20 12:00:00'] as $ts) {
            $row($a, 'signup', $ts);
        }
        $row($a, '$pageview', '2026-03-05 09:00:00', 3);
        $row($a, 'purchase', '2026-03-06 09:00:00', 2);
        $row($a, 'Sign Out', '2026-03-07 09:00:00');
        $row($a, 'a%b_c', '2026-03-08 09:00:00', 2);
        $row($a, "it's", '2026-03-08 09:00:00');
        $row($a, 'back\\slash', '2026-03-08 09:00:00');
        $row($a, 'ünï日本', '2026-03-08 09:00:00');
        $row($a, 'edge_start', '2026-02-19 00:00:00.000');
        $row($a, 'edge_before_end', '2026-03-20 23:59:59.999');
        $row($a, str_repeat('x', 200), '2026-03-08 09:00:00');
        $row($a, 'old_event', '2026-02-18 23:59:59.999', 4);
        $row($a, 'edge_end', '2026-03-21 00:00:00.000');
        $row($a, str_repeat('y', 201), '2026-03-08 09:00:00');

        $b = self::EVENTS_B;
        $row($b, 'signup', '2026-03-10 10:00:00', 7);
        $row($b, 'b_only', '2026-03-11 10:00:00', 2);

        $row(self::EVENTS_C, 'z_top', '2026-03-10 10:00:00', 3);

        $this->insertRows($rows);

        // Many distinct names, once each: generated by the server (a few hundred rows are not worth a UNION ALL).
        foreach ([[self::EVENTS_C, 'n', 105], [self::EVENTS_D, 'd', 100]] as [$project, $prefix, $count]) {
            $this->admin->execute(
                'INSERT INTO posthog.events (uuid, project_id, event, distinct_id, properties, timestamp) '
                .'SELECT generateUUIDv4(), {p:UInt32}, concat({prefix:String}, leftPad(toString(number), 3, \'0\')), \'gen\', \'{}\', '
                ."parseDateTime64BestEffort('2026-03-10 10:00:00', 3, 'UTC') FROM numbers({n:UInt32})",
                ['p' => $project, 'prefix' => $prefix, 'n' => $count],
            );
        }

        Assert::assertSame(count($rows) + 205, (int) $this->admin->select('SELECT count() AS n FROM posthog.events WHERE project_id IN ('.implode(', ', [self::EVENTS_A, self::EVENTS_B, self::EVENTS_C, self::EVENTS_D]).')')[0]['n']);
    }

    /**
     * ONE multi-row INSERT (a UNION ALL of typed SELECTs) of rows `[project, event, distinct_id, properties json, timestamp]`.
     *
     * @param  list<array{0: int, 1: string, 2: string, 3: string, 4: string}>  $rows
     */
    private function insertRows(array $rows): void
    {
        $selects = [];
        $params = [];
        foreach ($rows as $i => [$project, $event, $distinct, $props, $ts]) {
            $selects[] = "SELECT generateUUIDv4(), {p{$i}:UInt32}, {e{$i}:String}, {d{$i}:String}, {props{$i}:String}, parseDateTime64BestEffort({ts{$i}:String}, 3, 'UTC')";
            $params += ["p{$i}" => $project, "e{$i}" => $event, "d{$i}" => $distinct, "props{$i}" => $props, "ts{$i}" => $ts];
        }
        $this->admin->execute('INSERT INTO posthog.events (uuid, project_id, event, distinct_id, properties, timestamp) '.implode(' UNION ALL ', $selects), $params);
    }

    /** Like cleanupAfterAll() for the insight API tenants. */
    public static function cleanupApiAfterAll(): void
    {
        if (! self::$apiSeeded) {
            return;
        }
        self::$apiSeeded = false;

        Http::swap(new Factory);
        try {
            self::connect()->deleteRowsOf([self::API_A, self::API_B, self::API_C]);
        } finally {
            Facade::clearResolvedInstances();
        }
    }

    /**
     * Fixed rows (UTC) of the two API tenants, hand-computed in InsightApiClickHouseTest. Project A (990601):
     *   signup u1 03-14 10:00, signup u2 03-14 11:00, signup u1 03-15 09:00 (plan=pro), signup u1 03-15 09:30 (plan=free),
     *   signup u3 03-16 12:00 (plan=pro), purchase u1 03-15 13:00.
     * Project B (990602): signup b1 x2 and b2 x1 on 03-14, signup b1 x4 on 03-16, purchase b1 03-15.
     * Project C has no rows.
     */
    public function seedApi(): void
    {
        $this->deleteRowsOf([self::API_A, self::API_B, self::API_C]);

        // ONE multi-row INSERT (one HTTP round trip instead of fourteen): a UNION ALL of typed SELECTs.
        $rows = [];
        $row = function (int $project, string $event, string $distinct, string $ts, array $props = []) use (&$rows): void {
            $rows[] = [600000 + count($rows) + 1, $project, $event, $distinct, json_encode($props, JSON_THROW_ON_ERROR), $ts];
        };

        $a = self::API_A;
        $row($a, 'signup', 'u1', '2026-03-14 10:00:00');
        $row($a, 'signup', 'u2', '2026-03-14 11:00:00');
        $row($a, 'signup', 'u1', '2026-03-15 09:00:00', ['plan' => 'pro']);
        $row($a, 'signup', 'u1', '2026-03-15 09:30:00', ['plan' => 'free']);
        $row($a, 'signup', 'u3', '2026-03-16 12:00:00', ['plan' => 'pro']);
        $row($a, 'purchase', 'u1', '2026-03-15 13:00:00');

        $b = self::API_B;
        $row($b, 'signup', 'b1', '2026-03-14 10:00:00');
        $row($b, 'signup', 'b1', '2026-03-14 10:05:00');
        $row($b, 'signup', 'b2', '2026-03-14 10:10:00');
        foreach (['09:00', '09:01', '09:02', '09:03'] as $time) {
            $row($b, 'signup', 'b1', "2026-03-16 {$time}:00", ['plan' => 'enterprise']);
        }
        $row($b, 'purchase', 'b1', '2026-03-15 13:00:00');

        $selects = [];
        $params = [];
        foreach ($rows as $i => [$uuidNumber, $project, $event, $distinct, $props, $ts]) {
            $selects[] = "SELECT {u{$i}:UUID}, {p{$i}:UInt32}, {e{$i}:String}, {d{$i}:String}, {props{$i}:String}, parseDateTime64BestEffort({ts{$i}:String}, 3, 'UTC')";
            $params += ["u{$i}" => sprintf('00000000-0000-4000-8000-%012d', $uuidNumber), "p{$i}" => $project, "e{$i}" => $event, "d{$i}" => $distinct, "props{$i}" => $props, "ts{$i}" => $ts];
        }
        $this->admin->execute('INSERT INTO posthog.events (uuid, project_id, event, distinct_id, properties, timestamp) '.implode(' UNION ALL ', $selects), $params);

        Assert::assertSame(6 + 8, (int) $this->admin->select('SELECT count() AS n FROM posthog.events WHERE project_id IN ('.self::API_A.', '.self::API_B.', '.self::API_C.')')[0]['n']);
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

    /**
     * Deletes the rows of the given projects the way the tests everywhere do (an asynchronous lightweight delete,
     * then polling until the rows are gone), so no test depends on a global mutation state.
     *
     * @param  list<int>  $projectIds
     */
    public function deleteRowsOf(array $projectIds): void
    {
        $ids = implode(', ', array_map('intval', $projectIds));
        $this->admin->execute("DELETE FROM posthog.events WHERE project_id IN ({$ids})", settings: ['lightweight_deletes_sync' => 0]);

        $deadline = microtime(true) + 30;
        while ((int) $this->admin->select("SELECT count() AS n FROM posthog.events WHERE project_id IN ({$ids})")[0]['n'] !== 0) {
            if (microtime(true) > $deadline) {
                Assert::fail("Rows of projects {$ids} were not deleted within 30 s: remove them by hand");
            }
            usleep(200_000);
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
