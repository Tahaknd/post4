<?php

namespace App\Domain\Capture\Enrichment;

use WhichBrowser\Parser;

/**
 * Turns a User-Agent string into the PostHog compatible event properties `$browser`, `$browser_version`
 * (major, integer), `$os`, `$os_version` and `$device_type` (Desktop, Mobile, Tablet, Bot, ...).
 *
 * Library: whichbrowser/parser (MIT). Cost on a warm worker: about 100 microseconds per parse (about 0.4 ms
 * for the worst 512 byte inputs), 11 ms once per worker for the lazily loaded data tables, about 3 MB.
 * Parsing is memoised per User-Agent in a bounded LRU: browsers send a handful of distinct strings, so the
 * hit rate is very high and a batch of 500 events with one User-Agent parses it once. Garbage User-Agents
 * are cached too (an empty result) but cannot grow the cache past its entry and byte bounds.
 *
 * Only properties that could be determined are returned; an unknown User-Agent (posthog-node, curl without a
 * match, an empty string) yields an empty array, never placeholder values.
 */
final class UserAgentParser
{
    /** Linux distributions whichbrowser names by distribution; posthog-js reports all of them as `Linux`. */
    private const LINUX_DISTRIBUTIONS = [
        'Ubuntu', 'Kubuntu', 'Xubuntu', 'Lubuntu', 'Debian', 'Fedora', 'Red Hat', 'CentOS', 'Arch Linux', 'Linux Mint',
        'Mint', 'Gentoo', 'openSUSE', 'SUSE', 'Mandriva', 'Mageia', 'Slackware', 'Raspbian', 'Deepin', 'elementary OS',
    ];

    private readonly BoundedLru $cache;

    public function __construct(
        int $cacheEntries = 2000,
        int $cacheBytes = 2 * 1024 * 1024,
        private readonly int $maxLength = 512,
    ) {
        $this->cache = new BoundedLru($cacheEntries, $cacheBytes);
    }

    /** @return array<string, string|int> */
    public function parse(string $userAgent): array
    {
        if (strlen($userAgent) > $this->maxLength) {
            $userAgent = substr($userAgent, 0, $this->maxLength);
        }

        $userAgent = trim($userAgent);
        if ($userAgent === '') {
            return [];
        }

        $key = hash('xxh128', $userAgent);
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $properties = $this->detect($userAgent);
        } catch (\Throwable) {
            // A library failure on one odd string must not cost the event; cache the empty answer.
            $properties = [];
        }

        $this->cache->put($key, $properties, $this->estimateBytes($properties));

        return $properties;
    }

    public function cacheEntries(): int
    {
        return $this->cache->count();
    }

    public function cacheBytes(): int
    {
        return $this->cache->bytes();
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
