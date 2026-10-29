<?php

use App\Domain\Capture\Enricher;
use App\Domain\Capture\Enrichment\UserAgentParser;
use App\Domain\Capture\EventProducer;
use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Trends\Breakdown;
use App\Domain\Insights\Trends\BreakdownColumn;
use App\Models\Project;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Str;
use Tests\Support\ClickHouseIntegration as CH;
use Tests\Support\FakeEventProducer;
use Tests\Support\TrendsFixtures as T;

/*
 * Enrichment end to end against a real ClickHouse (25.8): events go through the real CaptureController and the
 * real Enricher (User-Agent header), the Kafka message it builds is stored in `events` the way the Kafka
 * materialized view does (the Kafka hop itself is covered by scripts/smoke-capture.sh and the live check), and
 * the MATERIALIZED columns browser/os/device_type/utm_* plus the Trends breakdown are read back through the
 * read-only user and the scoped view. Opt-in (group "clickhouse"). Fixed tenants 990801 / 990802, hand-computed:
 *
 * A (all `signup`, 2026-03-14): 1 Chrome/Windows, utm news/email/spring; 2 Chrome/Windows, utm news; 3 iPhone
 *   Safari, utm ads/spring; 4 Googlebot; 5 posthog-node (nothing to enrich); 6 Chrome/Windows with the SDK's own
 *   `$os` = Plan 9 (never overridden).
 * B (all `signup`, 2026-03-14): 2 Android Chrome (utm news), 1 Firefox on Linux.
 */

pest()->group('clickhouse');

const ENRICH_UA_CHROME_WIN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
const ENRICH_UA_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';
const ENRICH_UA_ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.6723.58 Mobile Safari/537.36';
const ENRICH_UA_FIREFOX_LINUX = 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0';
const ENRICH_UA_GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

beforeEach(function () {
    $this->ch = CH::connect();
    $this->runner = new InsightRunner($this->ch->reader, new Repository(new ArrayStore));

    // Seeded once per process, like the other ClickHouse files: the capture requests below run only the first time.
    $this->ch->seedMessagesOnce(function () {
        $producer = new FakeEventProducer;
        $this->app->instance(EventProducer::class, $producer);
        $this->app->instance(Enricher::class, new Enricher(new UserAgentParser));

        $send = function (int $project, string $ua, array $properties = []) {
            Project::query()->whereKey($project)->exists() || Project::factory()->create(['id' => $project]);
            $key = Project::query()->whereKey($project)->value('api_key');
            $this->call('POST', '/e/', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_USER_AGENT' => $ua], json_encode([
                'api_key' => $key, 'event' => 'signup', 'distinct_id' => 'u'.Str::random(6), 'timestamp' => '2026-03-14T10:00:00Z',
                'properties' => $properties,
            ]))->assertOk();
        };

        $send(CH::ENRICH_A, ENRICH_UA_CHROME_WIN, ['utm_source' => 'news', 'utm_medium' => 'email', 'utm_campaign' => 'spring']);
        $send(CH::ENRICH_A, ENRICH_UA_CHROME_WIN, ['utm_source' => 'news']);
        $send(CH::ENRICH_A, ENRICH_UA_IPHONE, ['utm_source' => 'ads', 'utm_campaign' => 'spring']);
        $send(CH::ENRICH_A, ENRICH_UA_GOOGLEBOT);
        $send(CH::ENRICH_A, 'posthog-node/5.56.0');
        $send(CH::ENRICH_A, ENRICH_UA_CHROME_WIN, ['$os' => 'Plan 9']);
        $send(CH::ENRICH_B, ENRICH_UA_ANDROID, ['utm_source' => 'news']);
        $send(CH::ENRICH_B, ENRICH_UA_ANDROID, ['utm_source' => 'news']);
        $send(CH::ENRICH_B, ENRICH_UA_FIREFOX_LINUX);

        return $producer->messages;
    });
});

afterAll(function () {
    CH::cleanupEnrichmentAfterAll();
});

function breakdownCounts($test, int $project, BreakdownColumn $column): array
{
    $result = $test->runner->trends($project, T::integration([
        'range' => T::window('2026-03-14', '2026-03-15'),
        'breakdown' => Breakdown::byColumn($column, 25),
    ]));

    $out = [];
    foreach ($result->series as $s) {
        $label = $s['breakdown'] === Breakdown::NONE_LABEL ? 'None' : $s['breakdown'];
        $out[$label] = array_sum($s['data']);
    }
    ksort($out);

    return $out;
}

it('computes the materialized columns of the enriched events in the real table', function () {
    $rows = $this->ch->admin->select(
        'SELECT browser, os, device_type, utm_source, utm_medium, utm_campaign, count() AS n FROM posthog.events WHERE project_id = {p:UInt32} '
        .'GROUP BY browser, os, device_type, utm_source, utm_medium, utm_campaign ORDER BY browser, os, utm_source, utm_medium, utm_campaign',
        ['p' => CH::ENRICH_A],
    );
    $rows = array_map(fn ($r) => array_values(array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $r)), $rows);

    expect($rows)->toBe([
        ['', '', '', '', '', '', 1],                                    // posthog-node: nothing enriched
        ['Chrome', 'Plan 9', 'Desktop', '', '', '', 1],                 // the SDK's $os is kept
        ['Chrome', 'Windows', 'Desktop', 'news', '', '', 1],
        ['Chrome', 'Windows', 'Desktop', 'news', 'email', 'spring', 1],
        ['Googlebot', '', 'Bot', '', '', '', 1],
        ['Mobile Safari', 'iOS', 'Mobile', 'ads', '', 'spring', 1],
    ]);
});

it('stores no raw ip and no raw user agent', function () {
    $props = array_column($this->ch->admin->select('SELECT properties FROM posthog.events WHERE project_id = {p:UInt32}', ['p' => CH::ENRICH_A]), 'properties');

    expect($props)->toHaveCount(6);
    foreach ($props as $json) {
        expect($json)->not->toContain('$ip')->and($json)->not->toContain('Mozilla');
    }
});

it('breaks down by os for two tenants without mixing them', function () {
    expect(breakdownCounts($this, CH::ENRICH_A, BreakdownColumn::Os))->toEqual(['Plan 9' => 1, 'Windows' => 2, 'iOS' => 1, 'None' => 2])
        ->and(breakdownCounts($this, CH::ENRICH_B, BreakdownColumn::Os))->toEqual(['Android' => 2, 'Linux' => 1]);
});

it('breaks down by device type, browser and utm columns, None for a missing value', function () {
    expect(breakdownCounts($this, CH::ENRICH_A, BreakdownColumn::DeviceType))->toEqual(['Bot' => 1, 'Desktop' => 3, 'Mobile' => 1, 'None' => 1])
        ->and(breakdownCounts($this, CH::ENRICH_B, BreakdownColumn::DeviceType))->toEqual(['Desktop' => 1, 'Mobile' => 2])
        ->and(breakdownCounts($this, CH::ENRICH_A, BreakdownColumn::Browser))->toEqual(['Chrome' => 3, 'Googlebot' => 1, 'Mobile Safari' => 1, 'None' => 1])
        ->and(breakdownCounts($this, CH::ENRICH_A, BreakdownColumn::UtmSource))->toEqual(['ads' => 1, 'news' => 2, 'None' => 3])
        ->and(breakdownCounts($this, CH::ENRICH_B, BreakdownColumn::UtmSource))->toEqual(['news' => 2, 'None' => 1])
        ->and(breakdownCounts($this, CH::ENRICH_A, BreakdownColumn::UtmMedium))->toEqual(['email' => 1, 'None' => 5])
        ->and(breakdownCounts($this, CH::ENRICH_A, BreakdownColumn::UtmCampaign))->toEqual(['spring' => 2, 'None' => 4]);
});

it('reads every new column through the read-only user and the scoped view for one tenant only', function () {
    $rows = $this->ch->reader->select(
        CH::ENRICH_B,
        'SELECT os, device_type, utm_source, count() AS n FROM project_events GROUP BY os, device_type, utm_source ORDER BY os',
    );

    expect(array_map(fn ($r) => [$r['os'], $r['device_type'], $r['utm_source'], (int) $r['n']], $rows))
        ->toBe([['Android', 'Mobile', 'news', 2], ['Linux', 'Desktop', '', 1]]);
});
