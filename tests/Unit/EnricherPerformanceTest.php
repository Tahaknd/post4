<?php

use App\Domain\Capture\Enricher;
use App\Domain\Capture\Enrichment\GeoLookup;
use App\Domain\Capture\Enrichment\UserAgentParser;

/*
 * Hot path guard: 10 000 events enriched with a warm User-Agent memo and a (fake, instant) geo lookup. The
 * assertions are structural (no parse after the warm-up) plus an order of magnitude time bound that a loaded
 * CI box cannot trip: 3 s for 10 000 events is a few hundred microseconds per event, while an accidental
 * parse per event or a quadratic loop would exceed it. Typical numbers are far below the bound; measure with
 * this test's loop rather than trusting a figure written down here.
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
