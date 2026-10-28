<?php

use Tests\Support\MmdbFixture;

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/post4-geocheck-'.bin2hex(random_bytes(6)).'.mmdb';
    config(['capture.enrichment.geoip_path' => $this->path]);
});

afterEach(function () {
    @unlink($this->path);
});

it('shows the validated record of an ip and exits 0', function () {
    MmdbFixture::write($this->path, ['8.8.8.0/24' => [
        'country' => ['iso_code' => 'US', 'names' => ['en' => 'United States']],
        'city' => ['names' => ['en' => 'Mountain View']],
        'location' => ['time_zone' => 'America/Los_Angeles'],
    ]]);

    $this->artisan('geoip:check')
        ->expectsOutputToContain(str_pad('$geoip_country_code', 28).'US')
        ->expectsOutputToContain(str_pad('$geoip_country_name', 28).'United States')
        ->expectsOutputToContain(str_pad('$geoip_city_name', 28).'Mountain View')
        ->expectsOutputToContain(str_pad('$geoip_time_zone', 28).'America/Los_Angeles')
        ->assertExitCode(0);
});

it('exits 1 when the database has no record for the ip, or the ip is not public', function (string $ip, string $message) {
    MmdbFixture::write($this->path, ['8.8.8.0/24' => ['country' => ['iso_code' => 'US']]]);

    $this->artisan('geoip:check', ['ip' => $ip])->expectsOutputToContain($message)->assertExitCode(1);
})->with([['9.9.9.9', 'no usable record'], ['10.0.0.1', 'not a globally routable address'], ['garbage', 'not a globally routable address']]);

it('exits 2 with no database', function () {
    $this->artisan('geoip:check')->expectsOutputToContain('no database')->assertExitCode(2);
});

it('exits 2 with an unreadable database', function () {
    file_put_contents($this->path, 'not a database at all');

    $this->artisan('geoip:check')->expectsOutputToContain('unreadable')->assertExitCode(2);
});

it('takes the database from --path', function () {
    $other = $this->path.'.other';
    MmdbFixture::write($other, ['8.8.8.0/24' => ['country' => ['iso_code' => 'DE']]]);

    try {
        $this->artisan('geoip:check', ['--path' => $other])->expectsOutputToContain('DE')->assertExitCode(0);
    } finally {
        @unlink($other);
    }
});
