<?php

use App\Domain\Capture\Enrichment\MmdbGeoLookup;
use App\Domain\Capture\Enrichment\PublicIp;

/*
 * OPT-IN (group "geoip", excluded from the default run): the real reader against a database YOU supply, to
 * prove that a real GeoLite2-City or DB-IP Lite City file produces only validated properties. Run:
 *
 *   docker compose exec -T -e GEOIP_TEST_DB=/app/storage/geoip/geoip.mmdb app php artisan test --group=geoip
 *
 * Skipped when GEOIP_TEST_DB is not set. Nothing here writes anything.
 */

pest()->group('geoip');

beforeEach(function () {
    $path = getenv('GEOIP_TEST_DB');
    if (! is_string($path) || $path === '' || ! is_file($path)) {
        $this->markTestSkipped('GEOIP_TEST_DB is not set to a database file');
    }
    $this->geo = new MmdbGeoLookup($path);
});

it('answers well known public addresses with validated properties only', function (string $ip) {
    $properties = $this->geo->lookup($ip);
    expect($properties)->toBeArray();

    foreach ($properties as $name => $value) {
        expect($name)->toStartWith('$geoip_')->and($value)->toBeString()->not->toBe('');
    }
    if (isset($properties['$geoip_country_code'])) {
        expect($properties['$geoip_country_code'])->toMatch('/^[A-Z]{2}$/');
    }
    if (isset($properties['$geoip_time_zone'])) {
        expect(DateTimeZone::listIdentifiers())->toContain($properties['$geoip_time_zone']);
    }
})->with(['8.8.8.8', '1.1.1.1', '9.9.9.9', '2001:4860:4860::8888', '2606:4700:4700::1111', '81.2.69.142']);

it('gives a country for at least one of the well known addresses', function () {
    $hits = array_filter(['8.8.8.8', '1.1.1.1', '9.9.9.9', '81.2.69.142'], fn ($ip) => isset($this->geo->lookup($ip)['$geoip_country_code']));

    expect($hits)->not->toBeEmpty();
});

it('never throws or returns an unvalidated value over a thousand pseudo random public addresses', function () {
    mt_srand(7);
    $answered = 0;
    for ($i = 0; $i < 1000; $i++) {
        $ip = PublicIp::normalise(mt_rand(1, 223).'.'.mt_rand(0, 255).'.'.mt_rand(0, 255).'.'.mt_rand(1, 254));
        if ($ip === null) {
            continue;
        }
        $properties = $this->geo->lookup($ip);
        $answered += $properties === [] ? 0 : 1;
        if (isset($properties['$geoip_country_code'])) {
            expect($properties['$geoip_country_code'])->toMatch('/^[A-Z]{2}$/');
        }
    }

    expect($answered)->toBeGreaterThan(0);
});
