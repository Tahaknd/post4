<?php

use App\Domain\Capture\Enricher;
use App\Domain\Capture\Enrichment\GeoLookup;
use App\Domain\Capture\Enrichment\LogThrottle;
use App\Domain\Capture\Enrichment\UserAgentParser;
use Illuminate\Support\Facades\Log;

const CHROME_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

/** A geo lookup that records every address it is asked about. */
function recordingGeo(array $answer = ['$geoip_country_code' => 'US', '$geoip_city_name' => 'Mountain View']): object
{
    return new class($answer) implements GeoLookup
    {
        /** @var list<string> */
        public array $asked = [];

        public function __construct(private readonly array $answer) {}

        public function lookup(string $ip): array
        {
            $this->asked[] = $ip;

            return $this->answer;
        }
    };
}

function enrich(Enricher $enricher, array $properties, ?string $ua = CHROME_MAC, ?string $ip = '8.8.8.8', bool $storeIp = false): array
{
    return $enricher->forRequest($ua, $ip, $storeIp)->apply(['event' => 'e', 'properties' => $properties])['properties'];
}

it('fills browser, os and device from the user agent header', function () {
    $props = enrich(new Enricher(new UserAgentParser), ['plan' => 'pro']);

    expect($props)->toMatchArray([
        'plan' => 'pro',
        '$browser' => 'Chrome',
        '$browser_version' => 130,
        '$os' => 'Mac OS X',
        '$device_type' => 'Desktop',
    ]);
});

it('never overrides what the sdk sent but fills empty values', function () {
    $props = enrich(new Enricher(new UserAgentParser, recordingGeo()), [
        '$browser' => 'Brave',
        '$os' => 'Plan 9',
        '$device_type' => '',
        '$os_version' => null,
        '$geoip_country_code' => 'DE',
    ]);

    expect($props['$browser'])->toBe('Brave')
        ->and($props['$os'])->toBe('Plan 9')
        ->and($props['$device_type'])->toBe('Desktop')
        ->and($props['$os_version'] ?? null)->toBeNull()->and($props)->toHaveKey('$browser_version', 130)
        ->and($props['$geoip_country_code'])->toBe('DE')
        ->and($props['$geoip_city_name'])->toBe('Mountain View');
});

it('prefers $raw_user_agent over the request header', function () {
    $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';
    $props = enrich(new Enricher(new UserAgentParser), ['$raw_user_agent' => $iphone], 'posthog-node/5.56.0');

    expect($props['$os'])->toBe('iOS')->and($props['$device_type'])->toBe('Mobile');
});

it('adds nothing for an sdk user agent and an absent header', function (?string $ua) {
    $props = enrich(new Enricher(new UserAgentParser), ['a' => 1], $ua, null);

    expect($props)->toBe(['a' => 1]);
})->with(['posthog-node/5.56.0', '', null]);

it('parses a batch of 500 events with one user agent once, even without the memo', function () {
    $parser = new UserAgentParser(cacheEntries: 0);
    $request = (new Enricher($parser))->forRequest(CHROME_MAC, null);
    for ($i = 0; $i < 500; $i++) {
        $request->apply(['event' => 'e', 'properties' => ['i' => $i]]);
    }

    expect($parser->libraryParses())->toBe(1);
});

it('does not parse at all when the sdk sent every user agent property', function () {
    $parser = new UserAgentParser;
    $props = ['$browser' => 'B', '$browser_version' => 1, '$os' => 'O', '$os_version' => '1', '$device_type' => 'Desktop'];
    (new Enricher($parser))->forRequest(CHROME_MAC, null)->apply(['properties' => $props]);

    expect($parser->libraryParses())->toBe(0);
});

it('looks up each distinct ip once per request and skips private and reserved addresses', function () {
    $geo = recordingGeo();
    $request = (new Enricher(new UserAgentParser, $geo))->forRequest(CHROME_MAC, '8.8.8.8');

    foreach (range(1, 50) as $_) {
        $request->apply(['properties' => []]);
    }
    expect($geo->asked)->toBe(['8.8.8.8']);

    foreach (['10.0.0.1', '192.168.1.1', '172.16.0.9', '127.0.0.1', '169.254.1.1', '::1', 'fe80::1', 'fc00::1', '0.0.0.0', 'garbage', ''] as $ip) {
        $geo->asked = [];
        $out = (new Enricher(new UserAgentParser, $geo))->forRequest(null, $ip)->apply(['properties' => []])['properties'];
        expect($geo->asked)->toBe([])->and($out)->not->toHaveKey('$geoip_country_code');
    }
});

it('uses a public $ip property for the lookup, otherwise the client ip', function (mixed $sent, string $expected) {
    $geo = recordingGeo();
    enrich(new Enricher(new UserAgentParser, $geo), ['$ip' => $sent], null, '1.1.1.1');

    expect($geo->asked)->toBe([$expected]);
})->with([
    'public ipv4' => ['93.184.216.34', '93.184.216.34'],
    'public ipv6' => ['2606:2800:220:1:248:1893:25c8:1946', '2606:2800:220:1:248:1893:25c8:1946'],
    'private ip falls back to the client' => ['10.1.2.3', '1.1.1.1'],
    'not an ip' => ['evil; DROP TABLE', '1.1.1.1'],
    'array' => [['8.8.4.4'], '1.1.1.1'],
    'mapped ipv4 is unwrapped' => ['::ffff:93.184.216.34', '93.184.216.34'],
]);

it('honours $geoip_disable', function (mixed $flag, bool $skipped) {
    $geo = recordingGeo();
    $props = enrich(new Enricher(new UserAgentParser, $geo), ['$geoip_disable' => $flag]);

    expect($geo->asked === [])->toBe($skipped)->and(isset($props['$geoip_country_code']))->toBe(! $skipped);
})->with([[true, true], ['true', true], [1, true], [false, false], ['false', false], [null, false]]);

it('strips $ip after the lookup by default and keeps it with store_ip', function () {
    $geo = recordingGeo();
    $enricher = new Enricher(new UserAgentParser, $geo);

    $stripped = enrich($enricher, ['$ip' => '93.184.216.34']);
    $kept = enrich($enricher, ['$ip' => '93.184.216.34'], storeIp: true);

    expect($stripped)->not->toHaveKey('$ip')->and($stripped)->toHaveKey('$geoip_country_code', 'US')
        ->and($kept)->toHaveKey('$ip', '93.184.216.34')
        ->and($geo->asked)->toBe(['93.184.216.34', '93.184.216.34']);
});

it('still strips $ip when enrichment is disabled but does no user agent or geo work', function () {
    $geo = recordingGeo();
    $props = enrich(new Enricher(new UserAgentParser, $geo, enabled: false), ['$ip' => '8.8.8.8']);

    expect($props)->toBe([])->and($geo->asked)->toBe([]);
});

it('swallows a failing lookup, logs once per interval and keeps the event', function () {
    Log::spy();
    $failing = new class implements GeoLookup
    {
        public function lookup(string $ip): array
        {
            throw new RuntimeException('boom '.$ip);
        }
    };
    $now = 5000;
    $enricher = new Enricher(new UserAgentParser, $failing, warnings: new LogThrottle(60, function () use (&$now) {
        return $now;
    }));

    for ($i = 0; $i < 20; $i++) {
        $props = enrich($enricher, ['$ip' => '8.8.8.8', 'keep' => $i]);
        expect($props)->toBe(['keep' => $i]);
    }
    Log::shouldHaveReceived('warning')->once();

    $now += 60;
    enrich($enricher, ['x' => 1]);
    Log::shouldHaveReceived('warning')->twice();
});

it('logs only the exception class, never the message with an address in it', function () {
    Log::spy();
    $failing = new class implements GeoLookup
    {
        public function lookup(string $ip): array
        {
            throw new RuntimeException('secret '.$ip);
        }
    };

    enrich(new Enricher(new UserAgentParser, $failing), []);

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $context === ['exception' => RuntimeException::class]);
});

it('handles properties that are not an array', function () {
    $out = (new Enricher(new UserAgentParser))->forRequest(CHROME_MAC, null)->apply(['event' => 'e', 'properties' => 'oops']);

    expect($out['properties'])->toHaveKey('$browser', 'Chrome');
});
