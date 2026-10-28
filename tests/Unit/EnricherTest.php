<?php

use App\Domain\Capture\Enricher;
use App\Domain\Capture\Enrichment\GeoLookup;
use App\Domain\Capture\Enrichment\LogThrottle;
use App\Domain\Capture\Enrichment\RequestEnrichment;
use App\Domain\Capture\Enrichment\UserAgentParser;
use Illuminate\Support\Facades\Log;

const CHROME_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
const CHROME_WIN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
const IPHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';

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

function failingGeo(): GeoLookup
{
    return new class implements GeoLookup
    {
        public function lookup(string $ip): array
        {
            throw new RuntimeException('secret '.$ip);
        }
    };
}

function enrich(Enricher $enricher, array $properties, ?string $ua = CHROME_MAC, ?string $ip = '8.8.8.8', bool $ipDisabled = false): array
{
    return $enricher->forRequest($ua, $ip, $ipDisabled)->apply(['event' => 'e', 'properties' => $properties])['properties'];
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
        ->and($props['$geoip_country_code'])->toBe('DE')
        ->and($props['$geoip_city_name'])->toBe('Mountain View');
});

it('keeps a name and its version together: an sdk name gets no version from another user agent', function () {
    $enricher = new Enricher(new UserAgentParser);

    $os = enrich($enricher, ['$os' => 'Plan 9'], CHROME_WIN);
    $browser = enrich($enricher, ['$browser' => 'Brave'], CHROME_MAC);
    $both = enrich($enricher, [], CHROME_WIN);
    $versionOnly = enrich($enricher, ['$os_version' => '99'], CHROME_WIN);

    expect($os['$os'])->toBe('Plan 9')->and($os)->not->toHaveKey('$os_version')
        ->and($os)->toHaveKey('$browser_version', 130)->and($os)->toHaveKey('$device_type', 'Desktop')
        ->and($browser['$browser'])->toBe('Brave')->and($browser)->not->toHaveKey('$browser_version')
        ->and($browser)->toHaveKey('$os', 'Mac OS X')
        ->and($both)->toHaveKey('$os_version', '10')->and($both)->toHaveKey('$browser_version', 130)
        ->and($versionOnly['$os_version'])->toBe('99');
});

it('prefers $raw_user_agent over the request header and drops it by default', function () {
    $props = enrich(new Enricher(new UserAgentParser), ['$raw_user_agent' => IPHONE_UA], 'posthog-node/5.56.0');

    expect($props['$os'])->toBe('iOS')->and($props['$device_type'])->toBe('Mobile')
        ->and($props)->not->toHaveKey('$raw_user_agent');
});

it('keeps $raw_user_agent only when asked to, capped at 512 bytes, and still derives from it', function () {
    $long = IPHONE_UA.str_repeat(' pad', 400);
    $props = enrich(new Enricher(new UserAgentParser, storeRawUserAgent: true), ['$raw_user_agent' => $long], null, null);

    expect($props['$os'])->toBe('iOS')
        ->and(strlen($props['$raw_user_agent']))->toBeLessThanOrEqual(512)
        ->and($props['$raw_user_agent'])->toStartWith(IPHONE_UA);
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

it('parses a batch of 500 events that all carry the same raw user agent once, even without the memo', function () {
    $parser = new UserAgentParser(cacheEntries: 0);
    $request = (new Enricher($parser, maxUserAgentParsesPerRequest: 20))->forRequest(CHROME_MAC, null);
    for ($i = 0; $i < 500; $i++) {
        $out = $request->apply(['properties' => ['$raw_user_agent' => IPHONE_UA]])['properties'];
    }

    expect($parser->libraryParses())->toBe(1)->and($out['$os'])->toBe('iOS');
});

it('does not parse at all when the sdk sent every user agent property', function () {
    $parser = new UserAgentParser;
    $props = ['$browser' => 'B', '$browser_version' => 1, '$os' => 'O', '$os_version' => '1', '$device_type' => 'Desktop'];
    (new Enricher($parser))->forRequest(CHROME_MAC, null)->apply(['properties' => $props]);

    expect($parser->libraryParses())->toBe(0);
});

describe('the per request parse budget', function () {
    /** 500 events, each with its own distinct raw User-Agent (a different Chrome major version). */
    function distinctRawAgents(RequestEnrichment $request, int $count = 500): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/'.(200 + $i).'.0.0.0 Safari/537.36';
            $out[] = $request->apply(['properties' => ['$raw_user_agent' => $ua]])['properties'];
        }

        return $out;
    }

    it('parses at most N distinct strings per request and gives the rest the header result', function () {
        $parser = new UserAgentParser;
        $out = distinctRawAgents((new Enricher($parser, maxUserAgentParsesPerRequest: 20))->forRequest(CHROME_MAC, null));

        // 20 own strings parsed; the header (Mac, Chrome 130) is the one extra parse and never counts
        expect($parser->libraryParses())->toBe(21)
            ->and($out[0])->toMatchArray(['$os' => 'Windows', '$browser_version' => 200])
            ->and($out[19])->toMatchArray(['$os' => 'Windows', '$browser_version' => 219])
            ->and($out[20])->toMatchArray(['$os' => 'Mac OS X', '$browser_version' => 130])
            ->and($out[499])->toMatchArray(['$os' => 'Mac OS X', '$browser_version' => 130]);
    });

    it('lets memo hits and repeated strings through without spending budget', function () {
        $parser = new UserAgentParser;
        $enricher = new Enricher($parser, maxUserAgentParsesPerRequest: 2);
        $parser->parse(IPHONE_UA);                                  // already in the memo
        $request = $enricher->forRequest(null, null);

        foreach ([IPHONE_UA, CHROME_WIN, CHROME_WIN, IPHONE_UA, CHROME_MAC, 'curl/8.7.1', CHROME_WIN] as $ua) {
            $out[] = $request->apply(['properties' => ['$raw_user_agent' => $ua]])['properties'];
        }

        // budget 2: CHROME_WIN and CHROME_MAC parse; 'curl/8.7.1' is over budget and there is no header: nothing
        expect($out[0]['$os'])->toBe('iOS')->and($out[1]['$os'])->toBe('Windows')->and($out[2]['$os'])->toBe('Windows')
            ->and($out[3]['$os'])->toBe('iOS')->and($out[4]['$os'])->toBe('Mac OS X')
            ->and($out[5])->not->toHaveKey('$os')->and($out[6]['$os'])->toBe('Windows')
            ->and($parser->libraryParses())->toBe(3);
    });

    it('does not slow a batch with one user agent and counts nothing for the header', function () {
        $parser = new UserAgentParser;
        $request = (new Enricher($parser, maxUserAgentParsesPerRequest: 0))->forRequest(CHROME_MAC, null);
        for ($i = 0; $i < 500; $i++) {
            $out = $request->apply(['properties' => ['i' => $i]])['properties'];
        }

        expect($out)->toHaveKey('$os', 'Mac OS X')->and($parser->libraryParses())->toBe(1);
    });
});

describe('addresses that may be looked up', function () {
    it('looks each distinct ip up once per request', function () {
        $geo = recordingGeo();
        $request = (new Enricher(new UserAgentParser, $geo))->forRequest(CHROME_MAC, '8.8.8.8');

        foreach (range(1, 50) as $_) {
            $request->apply(['properties' => []]);
        }

        expect($geo->asked)->toBe(['8.8.8.8']);
    });

    it('never looks up anything but a globally routable unicast address', function (string $address) {
        $geo = recordingGeo();
        $out = enrich(new Enricher(new UserAgentParser, $geo), [], null, $address);

        expect($geo->asked)->toBe([])->and($out)->not->toHaveKey('$geoip_country_code');
    })->with([
        'private 10/8' => '10.0.0.1', 'private 172.16/12' => '172.16.0.9', 'private 172.31' => '172.31.255.255', 'private 192.168/16' => '192.168.1.1',
        'loopback' => '127.0.0.1', 'loopback high' => '127.255.255.254', 'this network' => '0.0.0.0', 'this network 0/8' => '0.1.2.3',
        'link local' => '169.254.1.1', 'cgnat low' => '100.64.0.1', 'cgnat high' => '100.127.255.254',
        'multicast low' => '224.0.0.1', 'multicast high' => '239.255.255.255',
        'ietf 192.0.0/24' => '192.0.0.8', 'doc 192.0.2/24' => '192.0.2.1', 'relay 192.88.99/24' => '192.88.99.1',
        'benchmark 198.18' => '198.18.0.1', 'benchmark 198.19' => '198.19.255.255', 'doc 198.51.100/24' => '198.51.100.7',
        'doc 203.0.113/24' => '203.0.113.9', 'reserved 240/4' => '240.0.0.1', 'broadcast' => '255.255.255.255',
        'ipv6 loopback' => '::1', 'ipv6 unspecified' => '::', 'ipv6 link local' => 'fe80::1', 'ipv6 unique local fc' => 'fc00::1',
        'ipv6 unique local fd' => 'fd12:3456:789a::1', 'ipv6 multicast' => 'ff02::1', 'ipv6 documentation' => '2001:db8::1',
        'ipv6 documentation 3fff' => '3fff::1', 'nat64' => '64:ff9b::808:808', 'nat64 local use' => '64:ff9b:1::1', 'discard' => '100::1',
        'ietf 2001::/23' => '2001:1::1', 'teredo' => '2001:0:4136:e378:8000:63bf:3fff:fdd2', 'site local' => 'fec0::1',
        'mapped private' => '::ffff:10.0.0.1', 'mapped loopback' => '::ffff:127.0.0.1', 'mapped cgnat' => '::ffff:100.64.0.1',
        'decimal ipv4' => '2130706433', 'octal ipv4' => '0177.0.0.1', 'hex ipv4' => '0x7f.0.0.1', 'short ipv4' => '127.1',
        'garbage' => 'garbage', 'empty' => '', 'padded garbage' => '8.8.8.8/24',
    ]);

    it('looks up public addresses, unwrapping an ipv4-mapped one', function (string $address, string $expected) {
        $geo = recordingGeo();
        enrich(new Enricher(new UserAgentParser, $geo), [], null, $address);

        expect($geo->asked)->toBe([$expected]);
    })->with([
        'google dns' => ['8.8.8.8', '8.8.8.8'], 'just below cgnat' => ['100.63.255.255', '100.63.255.255'], 'just above cgnat' => ['100.128.0.1', '100.128.0.1'],
        'just below 172.16' => ['172.15.255.255', '172.15.255.255'], 'just above 172.31' => ['172.32.0.1', '172.32.0.1'],
        'just below benchmark' => ['198.17.255.255', '198.17.255.255'], 'just above benchmark' => ['198.20.0.1', '198.20.0.1'],
        'ipv6' => ['2606:2800:220:1:248:1893:25c8:1946', '2606:2800:220:1:248:1893:25c8:1946'], '6to4 is allowed' => ['2002:808:808::1', '2002:808:808::1'],
        'mapped public' => ['::ffff:93.184.216.34', '93.184.216.34'],
    ]);
});

it('uses a public $ip property for the lookup, otherwise the client ip', function (mixed $sent, string $expected) {
    $geo = recordingGeo();
    enrich(new Enricher(new UserAgentParser, $geo), ['$ip' => $sent], null, '1.1.1.1');

    expect($geo->asked)->toBe([$expected]);
})->with([
    'public ipv4' => ['93.184.216.34', '93.184.216.34'],
    'private ip falls back to the client' => ['10.1.2.3', '1.1.1.1'],
    'cgnat falls back to the client' => ['100.64.1.1', '1.1.1.1'],
    'not an ip' => ['evil; DROP TABLE', '1.1.1.1'],
    'array' => [['8.8.4.4'], '1.1.1.1'],
]);

it('honours $geoip_disable', function (mixed $flag, bool $skipped) {
    $geo = recordingGeo();
    $props = enrich(new Enricher(new UserAgentParser, $geo), ['$geoip_disable' => $flag]);

    expect($geo->asked === [])->toBe($skipped)->and(isset($props['$geoip_country_code']))->toBe(! $skipped);
})->with([[true, true], ['true', true], [1, true], [false, false], ['false', false], [null, false]]);

describe('what is stored of the ip', function () {
    $sdkIp = ['$ip' => '93.184.216.34', '$set' => ['$ip' => '93.184.216.34', 'plan' => 'pro'], '$set_once' => ['$ip' => '93.184.216.34', 'first' => 1], '$unset' => ['$ip']];

    it('strips $ip at the top level, in $set and in $set_once by default, after the lookup', function () use ($sdkIp) {
        $geo = recordingGeo();
        $props = enrich(new Enricher(new UserAgentParser, $geo), $sdkIp);

        expect($props)->not->toHaveKey('$ip')
            ->and($props['$set'])->toBe(['plan' => 'pro'])
            ->and($props['$set_once'])->toBe(['first' => 1])
            ->and($props['$unset'])->toBe(['$ip'])
            ->and($props)->toHaveKey('$geoip_country_code', 'US')
            ->and($geo->asked)->toBe(['93.184.216.34']);
    });

    it('keeps every $ip and adds the client ip when store_ip is on', function () use ($sdkIp) {
        $enricher = new Enricher(new UserAgentParser, recordingGeo(), storeIp: true);

        $kept = enrich($enricher, $sdkIp);
        $added = enrich($enricher, ['a' => 1]);

        expect($kept)->toMatchArray($sdkIp)
            ->and($added)->toHaveKey('$ip', '8.8.8.8');
    });

    it('stores a private client ip too when asked to (it is a store decision, not a lookup one)', function () {
        $added = enrich(new Enricher(new UserAgentParser, storeIp: true), [], CHROME_MAC, '10.0.0.5');

        expect($added)->toHaveKey('$ip', '10.0.0.5');
    });

    it('treats $set and $set_once that are not arrays as they are', function () {
        $props = enrich(new Enricher(new UserAgentParser), ['$set' => 'x', '$set_once' => null]);

        expect($props)->toMatchArray(['$set' => 'x', '$set_once' => null]);
    });

    it('still strips when enrichment is disabled but does no user agent or geo work', function () {
        $geo = recordingGeo();
        $props = enrich(new Enricher(new UserAgentParser, $geo, enabled: false), ['$ip' => '8.8.8.8', '$raw_user_agent' => CHROME_MAC, '$set' => ['$ip' => '8.8.8.8']]);

        expect($props)->toBe(['$set' => []])->and($geo->asked)->toBe([]);
    });
});

describe('?ip=0 together with an sdk $ip', function () {
    it('looks nothing up and adds no ip, whatever store_ip says', function (bool $storeIp) {
        $geo = recordingGeo();
        $props = enrich(new Enricher(new UserAgentParser, $geo, storeIp: $storeIp), [], CHROME_MAC, '8.8.8.8', ipDisabled: true);

        expect($geo->asked)->toBe([])->and($props)->not->toHaveKey('$ip')->and($props)->not->toHaveKey('$geoip_country_code');
    })->with([false, true]);

    it('does not use a body $ip for the lookup and stores it only with store_ip', function (bool $storeIp) {
        $geo = recordingGeo();
        $props = enrich(new Enricher(new UserAgentParser, $geo, storeIp: $storeIp), ['$ip' => '93.184.216.34'], CHROME_MAC, '8.8.8.8', ipDisabled: true);

        expect($geo->asked)->toBe([])->and($props)->not->toHaveKey('$geoip_country_code')
            ->and(array_key_exists('$ip', $props))->toBe($storeIp);
    })->with([false, true]);

    it('still derives browser, os and device', function () {
        expect(enrich(new Enricher(new UserAgentParser), [], CHROME_MAC, '8.8.8.8', ipDisabled: true))->toHaveKey('$os', 'Mac OS X');
    });
});

describe('failures', function () {
    it('swallows a failing lookup, logs once per interval and keeps the event and its user agent values', function () {
        Log::spy();
        $now = 5000;
        $enricher = new Enricher(new UserAgentParser, failingGeo(), geoWarnings: new LogThrottle(60, function () use (&$now) {
            return $now;
        }));

        for ($i = 0; $i < 20; $i++) {
            $props = enrich($enricher, ['$ip' => '8.8.8.8', 'keep' => $i]);
            expect($props)->toMatchArray(['keep' => $i, '$os' => 'Mac OS X'])->not->toHaveKey('$ip');
        }
        Log::shouldHaveReceived('warning')->once();

        $now += 60;
        enrich($enricher, ['x' => 1]);
        Log::shouldHaveReceived('warning')->twice();
    });

    it('keeps the user agent values when the geo lookup fails and the other way round, each with its own log line', function () {
        Log::spy();
        $brokenParser = new class extends UserAgentParser
        {
            public function parse(string $userAgent): array
            {
                throw new LogicException('boom '.$userAgent);
            }
        };
        $uaFails = (new Enricher($brokenParser, recordingGeo()))->forRequest(CHROME_MAC, '8.8.8.8');
        expect($uaFails->derive([]))->toHaveKey('$geoip_country_code', 'US')->not->toHaveKey('$os');
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => str_contains($m, 'user agent') && $c === ['exception' => LogicException::class])->once();

        $geoFails = (new Enricher(new UserAgentParser, failingGeo()))->forRequest(CHROME_MAC, '8.8.8.8');
        expect($geoFails->derive([]))->toHaveKey('$os', 'Mac OS X')->not->toHaveKey('$geoip_country_code');
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => str_contains($m, 'geoip') && $c === ['exception' => RuntimeException::class])->once();
    });

    it('logs only the exception class, never the message with an address in it', function () {
        Log::spy();

        enrich(new Enricher(new UserAgentParser, failingGeo()), []);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $context === ['exception' => RuntimeException::class]);
    });
});

describe('derive()', function () {
    it('is pure: it returns what would be filled and changes and strips nothing', function () {
        $request = (new Enricher(new UserAgentParser, recordingGeo()))->forRequest(CHROME_MAC, '8.8.8.8');
        $properties = ['$ip' => '93.184.216.34', '$os' => 'Plan 9', '$raw_user_agent' => IPHONE_UA];
        $before = $properties;

        $derived = $request->derive($properties);

        expect($properties)->toBe($before)
            ->and($derived)->toHaveKey('$browser', 'Mobile Safari')->and($derived)->toHaveKey('$device_type', 'Mobile')
            ->and($derived)->not->toHaveKey('$os')->and($derived)->not->toHaveKey('$os_version')
            ->and($derived)->toHaveKey('$geoip_country_code', 'US');
    });

    it('is empty when enrichment is disabled', function () {
        expect((new Enricher(new UserAgentParser, recordingGeo(), enabled: false))->forRequest(CHROME_MAC, '8.8.8.8')->derive([]))->toBe([]);
    });
});

it('handles properties that are not an array', function () {
    $out = (new Enricher(new UserAgentParser))->forRequest(CHROME_MAC, null)->apply(['event' => 'e', 'properties' => 'oops']);

    expect($out['properties'])->toHaveKey('$browser', 'Chrome');
});
