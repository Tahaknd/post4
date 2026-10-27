<?php

use App\Domain\Capture\Enricher;
use App\Domain\Capture\Enrichment\GeoLookup;
use App\Domain\Capture\Enrichment\UserAgentParser;

/*
 * Hot path guard: 10 000 events enriched with a warm User-Agent memo and a (fake, instant) geo lookup. Typical
 * numbers on the dev machine (php-cli inside the app container, no opcache): about 8 ms for the 10 000
 * events, i.e. under 1 microsecond per event of enrichment work; a cold User-Agent parse is about 100 microseconds
 * (the first one of a worker about 11 ms for the lazily loaded tables); a full memo of 2000 distinct User-Agents
 * is about 1 MB estimated and 1.4 MB measured. The assertion is an order of
 * magnitude bound only (3 s = about 300 microseconds per event), so a loaded CI box cannot make it flaky, while
 * an accidental parse-per-event (10 000 x 100 us = 1 s plus the cache misses) or a quadratic loop still shows.
 */
it('enriches 10 000 events with a warm memo well inside an order of magnitude bound', function () {
    $geo = new class implements GeoLookup
    {
        public function lookup(string $ip): array
        {
            return ['$geoip_country_code' => 'US'];
        }
    };
    $parser = new UserAgentParser;
    $enricher = new Enricher($parser, $geo);
    $agents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
        'curl/8.7.1',
    ];

    // warm the memo (one parse per distinct user agent)
    foreach ($agents as $ua) {
        $enricher->forRequest($ua, '8.8.8.8')->apply(['properties' => []]);
    }
    $parsesAfterWarmUp = $parser->libraryParses();

    $start = hrtime(true);
    for ($batch = 0; $batch < 20; $batch++) {
        $request = $enricher->forRequest($agents[$batch % 4], '8.8.8.8');
        for ($i = 0; $i < 500; $i++) {
            $request->apply(['event' => 'e', 'properties' => ['i' => $i, '$ip' => '8.8.8.8']]);
        }
    }
    $seconds = (hrtime(true) - $start) / 1e9;

    expect($parser->libraryParses())->toBe($parsesAfterWarmUp)
        ->and($seconds)->toBeLessThan(3.0);
});
