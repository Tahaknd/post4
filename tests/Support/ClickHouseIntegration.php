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

    /** Names whose UTF-8 bytes end or begin like a no-break space (EventNames::normalise must not cut them). */
    public const EVENTS_E = 990705;

    /** Tenants of the enrichment tests (EnrichmentClickHouseTest): events enriched by the real Enricher, then stored. */
    public const ENRICH_A = 990801;

    public const ENRICH_B = 990802;

    /**
     * Tenants of the person mapping tests (PersonDistinctIdsClickHouseTest): A and B have rows, C never gets one; D is
     * fed through the real Kafka topic; the two PURGE ids are for the purge job.
     */
    public const PERSON_A = 991001;

    public const PERSON_B = 991002;

    public const PERSON_C = 991003;

    public const PERSON_KAFKA = 991004;

    public const PERSON_PURGE_A = 991011;

    public const PERSON_PURGE_B = 991012;

    /**
     * Tenants of the person based unique users tests (TrendsPersonsClickHouseTest): A has events and a mapping with moves and
     * tombstones, B reuses A's distinct ids with a DIFFERENT mapping, C has events and no mapping at all.
     */
    public const PTREND_A = 991101;

    public const PTREND_B = 991102;

    public const PTREND_C = 991103;

    public const PTREND_IDS = [self::PTREND_A, self::PTREND_B, self::PTREND_C];

    /**
     * Tenants of the funnel tests (FunnelClickHouseTest): A has every scenario, B reuses A's distinct ids and event names
     * with other timings and another mapping, C has no row at all, D holds the seeded random dataset of the oracle check.
     */
    public const FUNNEL_A = 991201;

    public const FUNNEL_B = 991202;

    public const FUNNEL_C = 991203;

    /** The randomized dataset of the oracle comparison. */
    public const FUNNEL_D = 991204;

    public const FUNNEL_IDS = [self::FUNNEL_A, self::FUNNEL_B, self::FUNNEL_C, self::FUNNEL_D];

    /** The tenant of the spill test (ClickHouseSpillTest): millions of rows in the partition 200101, dropped as a whole afterwards. */
    public const SPILL_A = 991301;

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

    private static bool $enrichmentSeeded = false;

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

    /**
     * Stores Kafka-shaped messages (the array EventNormalizer produces: uuid, project_id, event, distinct_id,
     * properties JSON string, timestamp) in `events` exactly as the Kafka materialized view would, so the
     * materialized columns are computed by the real table. Starts from a clean slate for those projects and
     * remembers them for cleanupEnrichmentAfterAll().
     *
     * @param  \Closure(): list<array{uuid: string, project_id: int, event: string, distinct_id: string, properties: string, timestamp: string}>  $messages  builds the messages; called only the first time in a process
     */
    public function seedMessagesOnce(\Closure $messages): void
    {
        if (self::$enrichmentSeeded) {
            return;
        }
        self::$enrichmentSeeded = true;
        $messages = $messages();

        $this->deleteRowsOf([self::ENRICH_A, self::ENRICH_B]);

        $selects = [];
        $params = [];
        foreach ($messages as $i => $m) {
            $selects[] = "SELECT {u{$i}:UUID}, {p{$i}:UInt32}, {e{$i}:String}, {d{$i}:String}, {props{$i}:String}, parseDateTime64BestEffort({ts{$i}:String}, 3, 'UTC')";
            $params += ["u{$i}" => $m['uuid'], "p{$i}" => $m['project_id'], "e{$i}" => $m['event'], "d{$i}" => $m['distinct_id'], "props{$i}" => $m['properties'], "ts{$i}" => $m['timestamp']];
        }
        $this->admin->execute('INSERT INTO posthog.events (uuid, project_id, event, distinct_id, properties, timestamp) '.implode(' UNION ALL ', $selects), $params);
    }

    /** Every project id the person mapping tests write rows for (the tables they clean up). */
    public const PERSON_IDS = [self::PERSON_A, self::PERSON_B, self::PERSON_C, self::PERSON_KAFKA, self::PERSON_PURGE_A, self::PERSON_PURGE_B];

    private static bool $personTouched = false;

    /**
     * Inserts rows of person_distinct_ids as the admin, each as its OWN INSERT when $separateParts (so each becomes
     * its own part, unmerged until a background merge or OPTIMIZE), else all in ONE INSERT.
     *
     * @param  list<array{0: int, 1: string, 2: string, 3: int, 4: int, 5: string}>  $rows  project, distinct_id, person uuid, version, is_deleted, created_at (UTC)
     */
    public function insertPersonRows(array $rows, bool $separateParts = false): void
    {
        self::$personTouched = true;
        $statements = $separateParts ? array_map(fn (array $row) => [$row], $rows) : [$rows];

        foreach ($statements as $chunk) {
            $selects = [];
            $params = [];
            foreach ($chunk as $i => [$project, $distinct, $person, $version, $deleted, $createdAt]) {
                $selects[] = "SELECT {p{$i}:UInt32}, {d{$i}:String}, {u{$i}:UUID}, {v{$i}:UInt64}, {x{$i}:UInt8}, parseDateTime64BestEffort({t{$i}:String}, 3, 'UTC')";
                $params += ["p{$i}" => $project, "d{$i}" => $distinct, "u{$i}" => $person, "v{$i}" => $version, "x{$i}" => $deleted, "t{$i}" => $createdAt];
            }
            $this->admin->execute('INSERT INTO posthog.person_distinct_ids (project_id, distinct_id, person_id, version, is_deleted, created_at) '.implode(' UNION ALL ', $selects), $params);
        }
    }

    /**
     * Physical rows of person_distinct_ids for these projects as the admin sees them (rows of one key count twice until merged).
     *
     * @param  list<int>  $projectIds
     */
    public function personRowCount(array $projectIds): int
    {
        $ids = implode(', ', array_map('intval', $projectIds));

        return (int) $this->admin->select("SELECT count() AS n FROM posthog.person_distinct_ids WHERE project_id IN ({$ids})")[0]['n'];
    }

    /**
     * Deletes the rows of these projects from person_distinct_ids (asynchronous lightweight delete, then polling until
     * they are gone) after making sure merges run again, and removes the scratch rows they left in events_errors.
     *
     * @param  list<int>  $projectIds
     */
    public function deletePersonRowsOf(array $projectIds): void
    {
        $ids = implode(', ', array_map('intval', $projectIds));
        $this->admin->execute('SYSTEM START MERGES posthog.person_distinct_ids');
        $this->admin->execute("DELETE FROM posthog.person_distinct_ids WHERE project_id IN ({$ids})", settings: ['lightweight_deletes_sync' => 0]);

        $deadline = microtime(true) + 30;
        while ($this->personRowCount($projectIds) !== 0) {
            if (microtime(true) > $deadline) {
                Assert::fail("Rows of projects {$ids} were not deleted from person_distinct_ids within 30 s: remove them by hand");
            }
            usleep(200_000);
        }

        // Rejected messages of the Kafka test: the raw text carries the project id or the marker of a malformed one.
        $this->admin->execute(
            "DELETE FROM posthog.events_errors WHERE topic = 'person_distinct_ids' AND (raw LIKE {marker:String} OR raw LIKE {project:String})",
            ['marker' => '%ch-person-test-%', 'project' => '%"project_id":'.self::PERSON_KAFKA.',%'],
            settings: ['lightweight_deletes_sync' => 0],
        );
    }

    /** For Pest's afterAll(): removes the person based trends tests' events and mapping rows, also after a failed test. */
    public static function cleanupPersonTrendsAfterAll(): void
    {
        if (! self::$personTouched) {
            return;
        }

        Http::swap(new Factory);
        try {
            $ch = self::connect();
            $ch->admin->execute('SYSTEM START MERGES posthog.person_distinct_ids');
            $ch->deletePersonRowsOf(self::PTREND_IDS);
            $ch->deleteRowsOf(self::PTREND_IDS);
        } finally {
            Facade::clearResolvedInstances();
        }
    }

    /** For Pest's afterAll(): removes the funnel tests' events and mapping rows, also after a failed test. */
    public static function cleanupFunnelAfterAll(): void
    {
        if (! self::$personTouched) {
            return;
        }

        Http::swap(new Factory);
        try {
            $ch = self::connect();
            $ch->admin->execute('SYSTEM START MERGES posthog.person_distinct_ids');
            $ch->deletePersonRowsOf(self::FUNNEL_IDS);
            $ch->deleteRowsOf(self::FUNNEL_IDS);
        } finally {
            Facade::clearResolvedInstances();
        }
    }

    /** For Pest's afterAll(): removes the person mapping tests' rows (and the events they seeded), also after a failed test. */
    public static function cleanupPersonAfterAll(): void
    {
        if (! self::$personTouched) {
            return;
        }
        self::$personTouched = false;

        Http::swap(new Factory);
        try {
            $ch = self::connect();
            $ch->deletePersonRowsOf(self::PERSON_IDS);
            $ch->deleteRowsOf(self::PERSON_IDS);
        } finally {
            Facade::clearResolvedInstances();
        }
    }

    /** Like cleanupAfterAll() for the enrichment tenants. */
    public static function cleanupEnrichmentAfterAll(): void
    {
        if (! self::$enrichmentSeeded) {
            return;
        }
        self::$enrichmentSeeded = false;

        Http::swap(new Factory);
        try {
            self::connect()->deleteRowsOf([self::ENRICH_A, self::ENRICH_B]);
        } finally {
            Facade::clearResolvedInstances();
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
            self::connect()->deleteRowsOf([self::EVENTS_A, self::EVENTS_B, self::EVENTS_C, self::EVENTS_D, self::EVENTS_E]);
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
     * Project E (990705): voilà, Š, £x, ç, ©copy, °C temp, ÀÉÎ once each.
     */
    public function seedEventNames(): void
    {
        $this->deleteRowsOf([self::EVENTS_A, self::EVENTS_B, self::EVENTS_C, self::EVENTS_D, self::EVENTS_E]);

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

        foreach (['voilà', 'Š', '£x', 'ç', '©copy', '°C temp', 'ÀÉÎ'] as $name) {
            $row(self::EVENTS_E, $name, '2026-03-10 10:00:00');
        }

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

        Assert::assertSame(count($rows) + 205, (int) $this->admin->select('SELECT count() AS n FROM posthog.events WHERE project_id IN ('.implode(', ', [self::EVENTS_A, self::EVENTS_B, self::EVENTS_C, self::EVENTS_D, self::EVENTS_E]).')')[0]['n']);
    }

    /**
     * ONE multi-row INSERT (a UNION ALL of typed SELECTs) of rows `[project, event, distinct_id, properties json, timestamp]`.
     *
     * @param  list<array{0: int, 1: string, 2: string, 3: string, 4: string}>  $rows
     */
    public function insertRows(array $rows): void
    {
        self::$personTouched = true;   // the person based trends tests clean up their events through the same flag
        $selects = [];
        $params = [];
        foreach ($rows as $i => [$project, $event, $distinct, $props, $ts]) {
            $selects[] = "SELECT generateUUIDv4(), {p{$i}:UInt32}, {e{$i}:String}, {d{$i}:String}, {props{$i}:String}, parseDateTime64BestEffort({ts{$i}:String}, 3, 'UTC')";
            $params += ["p{$i}" => $project, "e{$i}" => $event, "d{$i}" => $distinct, "props{$i}" => $props, "ts{$i}" => $ts];
        }
        $this->admin->execute('INSERT INTO posthog.events (uuid, project_id, event, distinct_id, properties, timestamp) '.implode(' UNION ALL ', $selects), $params);
    }

    /** Partition (toYYYYMM) that holds ONLY the rows of {@see self::SPILL_A}: dropping it is much cheaper than deleting millions of rows. */
    public const SPILL_PARTITION = 200101;

    /**
     * Removes the spill tenant's rows. Drops the whole partition only when it holds nothing else (it is a year no real
     * event has); otherwise falls back to a normal delete.
     */
    public function cleanupSpill(): void
    {
        $others = (int) $this->admin->select('SELECT count() AS n FROM posthog.events WHERE toYYYYMM(timestamp) = '.self::SPILL_PARTITION.' AND project_id != '.self::SPILL_A)[0]['n'];
        if ($others === 0) {
            $this->admin->execute('ALTER TABLE posthog.events DROP PARTITION '.self::SPILL_PARTITION);

            return;
        }
        $this->deleteRowsOf([self::SPILL_A]);
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
        $adminClient = new ClickHouseClient($url, 'posthog', 'posthog', $admin);

        self::assertMemoryHeadroom($adminClient);

        return new self($adminClient, $roClient, new ProjectScopedReader($roClient));
    }

    /** Fraction of max_server_memory_usage above which the group refuses to start (see assertMemoryHeadroom). */
    public const MEMORY_GUARD_RATIO = 0.8;

    private static bool $memoryChecked = false;

    /**
     * Fails the group EARLY and readably when the ClickHouse server is already close to its memory ceiling,
     * instead of letting a random query die later with code 241 MEMORY_LIMIT_EXCEEDED (the usual victim was the
     * purge test that must not stack a second mutation). Checked once per process; if the two numbers cannot be
     * read (older server, missing grant) the check is skipped silently: it is a guard, not a requirement.
     */
    private static function assertMemoryHeadroom(ClickHouseClient $admin): void
    {
        if (self::$memoryChecked) {
            return;
        }
        self::$memoryChecked = true;

        try {
            $rows = $admin->select(
                "SELECT (SELECT value FROM system.metrics WHERE metric = 'MemoryTracking') AS used, "
                ."(SELECT toUInt64(value) FROM system.server_settings WHERE name = 'max_server_memory_usage') AS cap",
            );
        } catch (\Throwable) {
            return;
        }

        $used = (int) ($rows[0]['used'] ?? 0);
        $cap = (int) ($rows[0]['cap'] ?? 0);
        if ($cap <= 0 || $used <= $cap * self::MEMORY_GUARD_RATIO) {
            return;
        }

        $mib = static fn (int $bytes): string => number_format($bytes / 1048576, 0).' MiB';
        Assert::fail(sprintf(
            'ClickHouse is under memory pressure before the first test: MemoryTracking %s is %d%% of max_server_memory_usage %s '
            .'(guard: %d%%). The clickhouse group would fail at random with code 241 MEMORY_LIMIT_EXCEEDED. '
            .'Wait for background merges to settle, or check the small-host profile (docker/clickhouse/config.d/20-small-host.xml, '
            .'CLICKHOUSE_MEM_LIMIT) and that no leftover system log tables exist: '
            .'SELECT table, formatReadableSize(sum(bytes_on_disk)) FROM system.parts WHERE database = \'system\' AND active GROUP BY table.',
            $mib($used),
            (int) round($used / $cap * 100),
            $mib($cap),
            (int) (self::MEMORY_GUARD_RATIO * 100),
        ));
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
