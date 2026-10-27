<?php

use App\Domain\Capture\Enrichment\MmdbGeoLookup;
use Illuminate\Support\Facades\Log;
use Tests\Support\MmdbFixture;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/post4-geo-'.bin2hex(random_bytes(6));
    mkdir($this->dir);
    $this->path = $this->dir.'/geoip.mmdb';
});

afterEach(function () {
    foreach (glob($this->dir.'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($this->dir);
});

function mountainView(): array
{
    return [
        'country' => ['iso_code' => 'US', 'names' => ['en' => 'United States', 'de' => 'Vereinigte Staaten']],
        'city' => ['names' => ['en' => 'Mountain View']],
        'subdivisions' => [['iso_code' => 'CA', 'names' => ['en' => 'California']], ['iso_code' => 'XX', 'names' => ['en' => 'Second']]],
        'location' => ['time_zone' => 'America/Los_Angeles', 'latitude' => 37.4],
    ];
}

it('maps a city record to the geoip properties for IPv4 and IPv6', function () {
    MmdbFixture::write($this->path, ['8.8.8.0/24' => mountainView(), '2001:4860::/32' => mountainView()]);
    $geo = new MmdbGeoLookup($this->path);

    $expected = [
        '$geoip_country_code' => 'US',
        '$geoip_country_name' => 'United States',
        '$geoip_city_name' => 'Mountain View',
        '$geoip_subdivision_1_code' => 'CA',
        '$geoip_subdivision_1_name' => 'California',
        '$geoip_time_zone' => 'America/Los_Angeles',
    ];

    expect($geo->lookup('8.8.8.8'))->toBe($expected)
        ->and($geo->lookup('2001:4860:4860::8888'))->toBe($expected)
        ->and($geo->lookup('9.9.9.9'))->toBe([]);
});

it('returns only what is present and valid', function (array $record, array $expected) {
    MmdbFixture::write($this->path, ['8.8.8.0/24' => $record]);

    expect((new MmdbGeoLookup($this->path))->lookup('8.8.8.8'))->toBe($expected);
})->with([
    'country only' => [['country' => ['iso_code' => 'GB']], ['$geoip_country_code' => 'GB']],
    'lowercase country code' => [['country' => ['iso_code' => 'us', 'names' => ['en' => 'United States']]], ['$geoip_country_name' => 'United States']],
    'three letter country code' => [['country' => ['iso_code' => 'USA']], []],
    'unknown time zone' => [['location' => ['time_zone' => 'Mars/Olympus_Mons']], []],
    'time zone with a different case' => [['location' => ['time_zone' => 'america/los_angeles']], []],
    'name with a control character' => [['city' => ['names' => ['en' => "Evil\x00City"]]], []],
    'name that is too long' => [['city' => ['names' => ['en' => str_repeat('a', 129)]]], []],
    'no english name' => [['city' => ['names' => ['de' => 'Bergansicht']]], []],
    'wrong types' => [['country' => 'US', 'city' => ['names' => 5], 'subdivisions' => 'x', 'location' => ['time_zone' => ['x']]], []],
    'empty record' => [['country' => ['names' => ['en' => '']]], []],
]);

it('is silent and empty without a database, and logs one info line however many lookups there are', function () {
    Log::spy();
    $geo = new MmdbGeoLookup($this->path);

    for ($i = 0; $i < 200; $i++) {
        expect($geo->lookup('8.8.8.8'))->toBe([]);
    }

    Log::shouldHaveReceived('info')->once();
    Log::shouldNotHaveReceived('warning');
});

it('picks the database up when it appears, without a restart, after the retry interval', function () {
    $now = 1_000_000;
    $geo = new MmdbGeoLookup($this->path, 60, function () use (&$now) {
        return $now;
    });

    expect($geo->lookup('8.8.8.8'))->toBe([]);

    MmdbFixture::write($this->path, ['8.8.8.0/24' => mountainView()]);
    $now += 59;
    expect($geo->lookup('8.8.8.8'))->toBe([]);

    $now += 1;
    expect($geo->lookup('8.8.8.8'))->toHaveKey('$geoip_country_code', 'US');
});

it('survives a corrupt file: empty results, one warning per interval, recovery when it is fixed', function (string $contents) {
    Log::spy();
    file_put_contents($this->path, $contents);
    $now = 2_000_000;
    $geo = new MmdbGeoLookup($this->path, 60, function () use (&$now) {
        return $now;
    });

    for ($i = 0; $i < 100; $i++) {
        expect($geo->lookup('8.8.8.8'))->toBe([]);
    }
    Log::shouldHaveReceived('warning')->once();

    $now += 61;
    MmdbFixture::write($this->path, ['8.8.8.0/24' => mountainView()]);
    touch($this->path, $now);

    expect($geo->lookup('8.8.8.8'))->toHaveKey('$geoip_country_code', 'US');
})->with([
    'garbage' => [str_repeat('not a database', 100)],
    'empty file' => [''],
    'truncated database' => [substr(MmdbFixture::build(['8.8.8.0/24' => ['country' => ['iso_code' => 'US']]]), 0, 40)],
]);

it('reopens a database that was replaced on disk and keeps serving if the replacement is corrupt', function () {
    $now = 3_000_000;
    MmdbFixture::write($this->path, ['8.8.8.0/24' => ['country' => ['iso_code' => 'US']]]);
    touch($this->path, 1_700_000_000);
    $geo = new MmdbGeoLookup($this->path, 60, function () use (&$now) {
        return $now;
    });

    expect($geo->lookup('8.8.8.8'))->toBe(['$geoip_country_code' => 'US']);

    MmdbFixture::write($this->path, ['8.8.8.0/24' => ['country' => ['iso_code' => 'DE']]]);
    touch($this->path, 1_700_000_100);
    $now += 60;
    expect($geo->lookup('8.8.8.8'))->toBe(['$geoip_country_code' => 'DE']);

    // A replacement is a rename (a new inode): the open handle still reads the old, valid file.
    file_put_contents($this->path.'.new', 'half written');
    touch($this->path.'.new', 1_700_000_200);
    rename($this->path.'.new', $this->path);
    $now += 60;
    expect($geo->lookup('8.8.8.8'))->toBe(['$geoip_country_code' => 'DE']);
});

it('answers an IPv6 lookup in an IPv4-only database with nothing and no warning', function () {
    Log::spy();
    MmdbFixture::write($this->path, ['8.8.8.0/24' => mountainView()], ipVersion: 4);
    $geo = new MmdbGeoLookup($this->path);

    expect($geo->lookup('2001:4860:4860::8888'))->toBe([])
        ->and($geo->lookup('8.8.8.8'))->toHaveKey('$geoip_country_code', 'US');
    Log::shouldNotHaveReceived('warning');
});

it('never throws for an address string that is not an ip', function (string $address) {
    MmdbFixture::write($this->path, ['8.8.8.0/24' => mountainView()]);

    expect((new MmdbGeoLookup($this->path))->lookup($address))->toBe([]);
})->with(['', 'garbage', '999.1.1.1', "8.8.8.8\0", '8.8.8.8/24']);
