<?php

namespace App\Domain\Capture\Enrichment;

use WhichBrowser\Parser;

/**
 * Turns a User-Agent string into the PostHog compatible event properties `$browser`, `$browser_version`
 * (major, integer), `$os`, `$os_version` and `$device_type` (Desktop, Mobile, Tablet, Bot, ...).
 *
 * Library: whichbrowser/parser (MIT); it loads its data tables lazily (a one-off cost per worker). Parsing is
 * memoised per User-Agent in a bounded LRU keyed by the capped string itself, with the key length counted in
 * the byte budget: browsers send a handful of distinct strings, so the hit rate is very high and a batch of
 * 500 events with one User-Agent parses it once. Garbage User-Agents are cached too (an empty result) but
 * cannot grow the cache past its entry and byte bounds (a full default memo is roughly 1.5 MB per worker, hard
 * capped by the byte bound). The number of DISTINCT strings one request may make the library parse is limited
 * separately, see RequestEnrichment. Measurements: tests/Unit/EnricherPerformanceTest.php.
 *
 * Only properties that could be determined are returned; an unknown User-Agent (posthog-node, curl without a
 * match, an empty string) yields an empty array, never placeholder values.
 */
/* Not final only so that a test can make parse() throw and prove that a User-Agent failure keeps the geo values. */
class UserAgentParser
{
    /** User-Agents are capped to this many bytes before anything is done with them. */
    public const MAX_LENGTH = 512;

    /** Linux distributions whichbrowser names by distribution; posthog-js reports all of them as `Linux`. */
    private const LINUX_DISTRIBUTIONS = [
        'Ubuntu', 'Kubuntu', 'Xubuntu', 'Lubuntu', 'Debian', 'Fedora', 'Red Hat', 'CentOS', 'Arch Linux', 'Linux Mint',
        'Mint', 'Gentoo', 'openSUSE', 'SUSE', 'Mandriva', 'Mageia', 'Slackware', 'Raspbian', 'Deepin', 'elementary OS',
    ];

    private readonly BoundedLru $cache;

    private int $libraryParses = 0;

    public function __construct(
        int $cacheEntries = 2000,
        int $cacheBytes = 2 * 1024 * 1024,
        private readonly int $maxLength = self::MAX_LENGTH,
    ) {
        $this->cache = new BoundedLru($cacheEntries, $cacheBytes);
    }

    /**
     * The memoised result of a User-Agent, or null when it is not in the memo. Never parses.
     *
     * @return array<string, string|int>|null
     */
    public function cached(string $userAgent): ?array
    {
        $key = $this->normalise($userAgent);

        return $key === '' ? [] : $this->cache->get($key);
    }

    /** @return array<string, string|int> */
    public function parse(string $userAgent): array
    {
        $key = $this->normalise($userAgent);
        if ($key === '') {
            return [];
        }

        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $this->libraryParses++;

        try {
            $properties = $this->detect($key);
        } catch (\Throwable) {
            // A library failure on one odd string must not cost the event; cache the empty answer.
            $properties = [];
        }

        $this->cache->put($key, $properties, $this->estimateBytes($properties));

        return $properties;
    }

    /** How many times the library actually ran (cache misses).
     *
     * @internal tests and diagnostics only
     */
    public function libraryParses(): int
    {
        return $this->libraryParses;
    }

    /** @internal tests and diagnostics only */
    public function cacheEntries(): int
    {
        return $this->cache->count();
    }

    /** @internal tests and diagnostics only */
    public function cacheBytes(): int
    {
        return $this->cache->bytes();
    }

    private function normalise(string $userAgent): string
    {
        return trim(strlen($userAgent) > $this->maxLength ? substr($userAgent, 0, $this->maxLength) : $userAgent);
    }

    /** @return array<string, string|int> */
    private function detect(string $userAgent): array
    {
        $result = new Parser($userAgent);
        $properties = [];

        $browser = $this->clean($result->browser->name ?? null);
        $os = $this->clean($result->os->name ?? null);
        $deviceType = is_string($result->device->type ?? null) ? $result->device->type : '';

        if ($browser !== null) {
            $properties['$browser'] = $this->browserName($browser, $os);

            $major = $this->major($result->browser->version ?? null);
            if ($major !== null) {
                $properties['$browser_version'] = $major;
            }
        }

        if ($os !== null) {
            $properties['$os'] = $this->osName($os);

            $version = $this->clean($result->os->version?->toString());
            if ($version !== null) {
                $properties['$os_version'] = $version;
            }
        }

        $device = $this->deviceType($deviceType);
        if ($device !== null) {
            $properties['$device_type'] = $device;
        }

        return $properties;
    }

    /** posthog-js names: `Microsoft Edge`, `Mobile Safari`, `Chrome iOS`, `Firefox iOS`, `Facebook Mobile`. */
    private function browserName(string $browser, ?string $os): string
    {
        $ios = $os === 'iOS';

        return match (true) {
            $browser === 'Edge' => 'Microsoft Edge',
            $browser === 'Safari' && $ios => 'Mobile Safari',
            $browser === 'Chrome' && $ios => 'Chrome iOS',
            $browser === 'Firefox' && $ios => 'Firefox iOS',
            $browser === 'Facebook' => 'Facebook Mobile',
            default => $browser,
        };
    }

    /** posthog-js names: `Mac OS X`, `Windows`, `Linux`, `Android`, `iOS`, `Chrome OS`. */
    private function osName(string $os): string
    {
        if ($os === 'OS X') {
            return 'Mac OS X';
        }

        return in_array($os, self::LINUX_DISTRIBUTIONS, true) ? 'Linux' : $os;
    }

    private function deviceType(string $type): ?string
    {
        return match ($type) {
            '' => null,
            'desktop' => 'Desktop',
            'mobile' => 'Mobile',
            'tablet' => 'Tablet',
            'bot' => 'Bot',
            default => ucfirst($type),
        };
    }

    private function major(mixed $version): ?int
    {
        $text = is_object($version) && method_exists($version, 'toString') ? $version->toString() : null;

        return is_string($text) && preg_match('/^(\d{1,6})/', $text, $m) === 1 ? (int) $m[1] : null;
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        // The library's names are ASCII; anything else (or a runaway string) is not worth storing.
        return $value !== '' && strlen($value) <= 64 && preg_match('/^[\x20-\x7E]+$/', $value) === 1 ? $value : null;
    }

    /** @param  array<string, string|int>  $properties */
    private function estimateBytes(array $properties): int
    {
        $bytes = 0;
        foreach ($properties as $name => $value) {
            $bytes += strlen($name) + (is_string($value) ? strlen($value) : 8) + 48;
        }

        return $bytes;
    }
}
