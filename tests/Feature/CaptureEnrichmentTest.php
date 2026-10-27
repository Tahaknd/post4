<?php

use App\Domain\Capture\Enricher;
use App\Domain\Capture\Enrichment\MmdbGeoLookup;
use App\Domain\Capture\Enrichment\UserAgentParser;
use App\Domain\Capture\EventProducer;
use App\Models\Project;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeEventProducer;
use Tests\Support\MmdbFixture;

const ENRICH_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

beforeEach(function () {
    $this->producer = new FakeEventProducer;
    $this->app->instance(EventProducer::class, $this->producer);
    $this->key = Project::factory()->create()->api_key;

    $this->mmdb = sys_get_temp_dir().'/post4-capture-geo-'.bin2hex(random_bytes(6)).'.mmdb';
    MmdbFixture::write($this->mmdb, [
        '8.8.8.0/24' => ['country' => ['iso_code' => 'US', 'names' => ['en' => 'United States']], 'city' => ['names' => ['en' => 'Mountain View']], 'location' => ['time_zone' => 'America/Los_Angeles']],
        '81.2.69.0/24' => ['country' => ['iso_code' => 'GB', 'names' => ['en' => 'United Kingdom']], 'city' => ['names' => ['en' => 'London']]],
    ]);
    $this->app->instance(Enricher::class, new Enricher(new UserAgentParser, new MmdbGeoLookup($this->mmdb)));
});

afterEach(function () {
    @unlink($this->mmdb);
    TrustProxies::flushState();
});

function enrichedProps(object $producer, int $index = 0): array
{
    return json_decode($producer->messages[$index]['properties'], true);
}

function postWith(array $server, array $body): TestResponse
{
    return test()->call('POST', '/e/', [], [], [], ['CONTENT_TYPE' => 'application/json'] + $server, json_encode($body));
}

it('enriches events from the User-Agent header', function () {
    postWith(['HTTP_USER_AGENT' => ENRICH_CHROME], ['api_key' => $this->key, 'event' => 'e', 'distinct_id' => 'u'])->assertOk();

    expect(enrichedProps($this->producer))->toMatchArray([
        '$browser' => 'Chrome', '$browser_version' => 130, '$os' => 'Windows', '$os_version' => '10', '$device_type' => 'Desktop',
    ]);
});

it('keeps what the sdk sent and fills the rest', function () {
    postWith(['HTTP_USER_AGENT' => ENRICH_CHROME], ['api_key' => $this->key, 'event' => 'e', 'distinct_id' => 'u', 'properties' => ['$browser' => 'Brave', '$os' => 'BeOS']])->assertOk();

    expect(enrichedProps($this->producer))->toMatchArray(['$browser' => 'Brave', '$os' => 'BeOS', '$device_type' => 'Desktop']);
});

it('adds nothing for an sdk user agent', function () {
    postWith(['HTTP_USER_AGENT' => 'posthog-node/5.56.0'], ['api_key' => $this->key, 'event' => 'e', 'distinct_id' => 'u'])->assertOk();

    expect(enrichedProps($this->producer))->toBe([]);
});

it('enriches every event of a batch', function () {
    $batch = array_map(fn ($i) => ['event' => 'e', 'distinct_id' => "u{$i}"], range(1, 25));
    postWith(['HTTP_USER_AGENT' => ENRICH_CHROME], ['api_key' => $this->key, 'batch' => $batch])->assertOk();

    expect($this->producer->messages)->toHaveCount(25);
    foreach (array_keys($this->producer->messages) as $i) {
        expect(enrichedProps($this->producer, $i))->toHaveKey('$browser', 'Chrome');
    }
});

it('looks the client ip up and does not store it by default', function () {
    postWith(['HTTP_USER_AGENT' => ENRICH_CHROME, 'REMOTE_ADDR' => '8.8.8.8'], ['api_key' => $this->key, 'event' => 'e', 'distinct_id' => 'u'])->assertOk();

    expect(enrichedProps($this->producer))->toMatchArray([
        '$geoip_country_code' => 'US', '$geoip_country_name' => 'United States', '$geoip_city_name' => 'Mountain View', '$geoip_time_zone' => 'America/Los_Angeles',
    ])->not->toHaveKey('$ip');
});

it('stores $ip next to the geo properties with CAPTURE_STORE_IP', function () {
    config(['capture.store_ip' => true]);
    postWith(['REMOTE_ADDR' => '8.8.8.8'], ['api_key' => $this->key, 'event' => 'e', 'distinct_id' => 'u'])->assertOk();

    expect(enrichedProps($this->producer))->toMatchArray(['$ip' => '8.8.8.8', '$geoip_country_code' => 'US']);
});

it('ignores a spoofed X-Forwarded-For from an untrusted peer', function () {
    config(['proxies.trusted' => null]);
    app()->getProvider(AppServiceProvider::class)->boot();

    postWith(['REMOTE_ADDR' => '8.8.8.8', 'HTTP_X_FORWARDED_FOR' => '81.2.69.142'], ['api_key' => $this->key, 'event' => 'e', 'distinct_id' => 'u'])->assertOk();

    expect(enrichedProps($this->producer))->toHaveKey('$geoip_country_code', 'US');
});

it('ignores X-Forwarded-For from a peer outside the trusted list and honours it from a trusted one', function () {
    config(['proxies.trusted' => ['10.0.0.0/8']]);
    app()->getProvider(AppServiceProvider::class)->boot();

    postWith(['REMOTE_ADDR' => '8.8.8.8', 'HTTP_X_FORWARDED_FOR' => '81.2.69.142'], ['api_key' => $this->key, 'event' => 'e', 'distinct_id' => 'u'])->assertOk();
    postWith(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '81.2.69.142'], ['api_key' => $this->key, 'event' => 'e', 'distinct_id' => 'u'])->assertOk();

    expect(enrichedProps($this->producer, 0))->toHaveKey('$geoip_country_code', 'US')
        ->and(enrichedProps($this->producer, 1))->toHaveKey('$geoip_country_code', 'GB');
});

it('does not look up a private client address', function () {
    postWith(['REMOTE_ADDR' => '10.0.0.5'], ['api_key' => $this->key, 'event' => 'e', 'distinct_id' => 'u'])->assertOk();

    expect(enrichedProps($this->producer))->toBe([]);
});

it('uses a public $ip an sdk forwards and honours $geoip_disable and ?ip=0', function () {
    postWith(['REMOTE_ADDR' => '10.0.0.5'], ['api_key' => $this->key, 'event' => 'e', 'distinct_id' => 'u', 'properties' => ['$ip' => '81.2.69.142']])->assertOk();
    postWith(['REMOTE_ADDR' => '8.8.8.8'], ['api_key' => $this->key, 'event' => 'e', 'distinct_id' => 'u', 'properties' => ['$geoip_disable' => true]])->assertOk();
    $this->call('POST', '/e/?ip=0', [], [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '8.8.8.8'], json_encode(['api_key' => $this->key, 'event' => 'e', 'distinct_id' => 'u']))->assertOk();

    expect(enrichedProps($this->producer, 0))->toMatchArray(['$geoip_country_code' => 'GB', '$geoip_city_name' => 'London'])->not->toHaveKey('$ip')
        ->and(enrichedProps($this->producer, 1))->not->toHaveKey('$geoip_country_code')
        ->and(enrichedProps($this->producer, 2))->not->toHaveKey('$geoip_country_code');
});

it('captures normally when the geoip file is missing or corrupt', function (?string $contents) {
    $path = sys_get_temp_dir().'/post4-missing-'.bin2hex(random_bytes(4)).'.mmdb';
    if ($contents !== null) {
        file_put_contents($path, $contents);
    }
    $this->app->instance(Enricher::class, new Enricher(new UserAgentParser, new MmdbGeoLookup($path)));

    postWith(['HTTP_USER_AGENT' => ENRICH_CHROME, 'REMOTE_ADDR' => '8.8.8.8'], ['api_key' => $this->key, 'event' => 'e', 'distinct_id' => 'u'])->assertOk();
    @unlink($path);

    expect($this->producer->messages)->toHaveCount(1)
        ->and(enrichedProps($this->producer))->toHaveKey('$browser', 'Chrome')->not->toHaveKey('$geoip_country_code');
})->with(['missing' => [null], 'corrupt' => ['garbage']]);

it('skips enrichment when disabled but still drops $ip', function () {
    $this->app->instance(Enricher::class, new Enricher(new UserAgentParser, new MmdbGeoLookup($this->mmdb), enabled: false));

    postWith(['HTTP_USER_AGENT' => ENRICH_CHROME, 'REMOTE_ADDR' => '8.8.8.8'], ['api_key' => $this->key, 'event' => 'e', 'distinct_id' => 'u', 'properties' => ['$ip' => '8.8.8.8']])->assertOk();

    expect(enrichedProps($this->producer))->toBe([]);
});

it('keeps the Enricher in the Octane warm list so its caches live per worker', function () {
    expect(config('octane.warm'))->toContain(Enricher::class);
});

it('reads the enrichment defaults from the config', function () {
    expect(config('capture.store_ip'))->toBeFalse()
        ->and(config('capture.enrichment.enabled'))->toBeTrue()
        ->and(config('capture.enrichment.geoip_path'))->toBe('/app/storage/geoip/geoip.mmdb')
        ->and(config('capture.enrichment.ua_cache_entries'))->toBe(2000);
});
