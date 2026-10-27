<?php

use App\Domain\Capture\Enrichment\UserAgentParser;

/** @return array<string, array{0: string, 1: array<string, string|int>}> */
function goldenUserAgents(): array
{
    $desktop = 'Desktop';

    return [
        'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
            ['$browser' => 'Chrome', '$browser_version' => 130, '$os' => 'Windows', '$os_version' => '10', '$device_type' => $desktop]],
        'Chrome on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
            ['$browser' => 'Chrome', '$browser_version' => 130, '$os' => 'Mac OS X', '$device_type' => $desktop]],
        'Chrome on Linux' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
            ['$browser' => 'Chrome', '$browser_version' => 130, '$os' => 'Linux', '$device_type' => $desktop]],
        'Firefox on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0',
            ['$browser' => 'Firefox', '$browser_version' => 132, '$os' => 'Windows', '$os_version' => '10', '$device_type' => $desktop]],
        'Firefox on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:132.0) Gecko/20100101 Firefox/132.0',
            ['$browser' => 'Firefox', '$browser_version' => 132, '$os' => 'Mac OS X', '$device_type' => $desktop]],
        'Firefox on Ubuntu counts as Linux' => ['Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0',
            ['$browser' => 'Firefox', '$browser_version' => 132, '$os' => 'Linux', '$device_type' => $desktop]],
        'Safari on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
            ['$browser' => 'Safari', '$browser_version' => 18, '$os' => 'Mac OS X', '$device_type' => $desktop]],
        'Edge on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.2849.56',
            ['$browser' => 'Microsoft Edge', '$browser_version' => 130, '$os' => 'Windows', '$os_version' => '10', '$device_type' => $desktop]],
        'Edge on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.2849.56',
            ['$browser' => 'Microsoft Edge', '$browser_version' => 130, '$os' => 'Mac OS X', '$device_type' => $desktop]],
        'Opera on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 OPR/115.0.0.0',
            ['$browser' => 'Opera', '$browser_version' => 115, '$os' => 'Windows', '$os_version' => '10', '$device_type' => $desktop]],
        'Internet Explorer 11' => ['Mozilla/5.0 (Windows NT 10.0; WOW64; Trident/7.0; rv:11.0) like Gecko',
            ['$browser' => 'Internet Explorer', '$browser_version' => 11, '$os' => 'Windows', '$os_version' => '10', '$device_type' => $desktop]],
        'Chrome on ChromeOS' => ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
            ['$browser' => 'Chrome', '$browser_version' => 130, '$os' => 'Chrome OS', '$device_type' => $desktop]],
        'Chrome on Android phone' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.6723.58 Mobile Safari/537.36',
            ['$browser' => 'Chrome', '$browser_version' => 130, '$os' => 'Android', '$os_version' => '14', '$device_type' => 'Mobile']],
        'Chrome on Android tablet' => ['Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
            ['$browser' => 'Chrome', '$browser_version' => 130, '$os' => 'Android', '$os_version' => '13', '$device_type' => 'Tablet']],
        'Samsung Internet' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36',
            ['$browser' => 'Samsung Internet', '$browser_version' => 25, '$os' => 'Android', '$os_version' => '14', '$device_type' => 'Mobile']],
        'Safari on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
            ['$browser' => 'Mobile Safari', '$browser_version' => 18, '$os' => 'iOS', '$os_version' => '18.1', '$device_type' => 'Mobile']],
        'Chrome on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0.6723.37 Mobile/15E148 Safari/604.1',
            ['$browser' => 'Chrome iOS', '$browser_version' => 130, '$os' => 'iOS', '$os_version' => '17.6', '$device_type' => 'Mobile']],
        'Safari on iPad' => ['Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
            ['$browser' => 'Mobile Safari', '$browser_version' => 17, '$os' => 'iOS', '$os_version' => '17.5', '$device_type' => 'Tablet']],
        'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            ['$browser' => 'Googlebot', '$browser_version' => 2, '$device_type' => 'Bot']],
        'Googlebot smartphone' => ['Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.6723.58 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            ['$browser' => 'Googlebot', '$browser_version' => 2, '$device_type' => 'Bot']],
        'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
            ['$browser' => 'Bing', '$browser_version' => 2, '$device_type' => 'Bot']],
        'curl' => ['curl/8.7.1', ['$browser' => 'Curl', '$browser_version' => 8, '$device_type' => 'Bot']],
        'python-requests' => ['python-requests/2.32.3', ['$browser' => 'Python', '$browser_version' => 2, '$device_type' => 'Bot']],
        'Wget' => ['Wget/1.21.4', ['$browser' => 'Wget', '$browser_version' => 1, '$device_type' => 'Bot']],
        'smart TV' => ['Mozilla/5.0 (SMART-TV; Linux; Tizen 7.0) AppleWebKit/537.36 (KHTML, like Gecko) 94.0.4606.31/7.0 TV Safari/537.36',
            ['$os' => 'Tizen', '$os_version' => '7.0', '$device_type' => 'Television']],
        'posthog-node (an SDK is not a browser)' => ['posthog-node/5.56.0', []],
        'posthog-python' => ['posthog-python/6.7.4', []],
        'posthog-php' => ['posthog-php/3.7.0', []],
        'empty' => ['', []],
        'blank' => ["  \t ", []],
        'garbage' => ['zzzz!!!', []],
    ];
}

it('parses the golden table of user agents', function (string $userAgent, array $expected) {
    expect((new UserAgentParser)->parse($userAgent))->toBe($expected);
})->with(goldenUserAgents());

it('survives binary, control character and very long user agents', function (string $userAgent) {
    $parser = new UserAgentParser;

    expect($parser->parse($userAgent))->toBeArray();
})->with([
    'nul bytes' => [str_repeat("\0", 600)],
    'invalid utf-8' => [str_repeat("\xff\xfe\x80", 300)],
    'only parentheses' => [str_repeat('(', 5000)],
    'a megabyte of a' => [str_repeat('a', 1_000_000)],
    'repeated android' => [str_repeat('Android ', 5000)],
    'control characters' => ["Mozilla/5.0 \x01\x02\x1b[31m (Windows NT 10.0)"],
]);

it('caps the user agent at 512 bytes before parsing', function () {
    $parser = new UserAgentParser;
    $chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    // The recognisable part sits after the cap: it is not seen, and the work stays bounded.
    expect($parser->parse(str_repeat('x', 600).$chrome))->toBe([])
        ->and($parser->parse($chrome.str_repeat(' ', 600)))->toHaveKey('$browser', 'Chrome');
});

it('memoises per user agent and parses a repeated string once', function () {
    $parser = new UserAgentParser;
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    $first = $parser->parse($ua);
    for ($i = 0; $i < 500; $i++) {
        expect($parser->parse($ua))->toBe($first);
    }

    expect($parser->cacheEntries())->toBe(1)->and($parser->libraryParses())->toBe(1);
});

it('bounds the memo by entries and by bytes', function () {
    $byEntries = new UserAgentParser(cacheEntries: 50, cacheBytes: 10_000_000);
    $byBytes = new UserAgentParser(cacheEntries: 100_000, cacheBytes: 20_000);

    for ($i = 0; $i < 2000; $i++) {
        $ua = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/{$i}.0.0.0 Safari/537.36 build{$i}";
        $byEntries->parse($ua);
        $byBytes->parse($ua);
    }

    expect($byEntries->cacheEntries())->toBe(50)
        ->and($byBytes->cacheBytes())->toBeLessThanOrEqual(20_000)
        ->and($byBytes->cacheEntries())->toBeLessThan(2000);
});

it('caches the empty answer of an unknown user agent too', function () {
    $parser = new UserAgentParser;

    $parser->parse('posthog-node/5.56.0');
    $parser->parse('posthog-node/5.56.0');

    expect($parser->cacheEntries())->toBe(1);
});
