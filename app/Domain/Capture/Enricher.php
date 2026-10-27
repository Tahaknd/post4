<?php

namespace App\Domain\Capture;

use App\Domain\Capture\Enrichment\GeoLookup;
use App\Domain\Capture\Enrichment\LogThrottle;
use App\Domain\Capture\Enrichment\NullGeoLookup;
use App\Domain\Capture\Enrichment\RequestEnrichment;
use App\Domain\Capture\Enrichment\UserAgentParser;

/**
 * Fills browser, operating system, device and (optionally) GeoIP properties of captured events, and removes
 * the raw ip unless the operator asked to keep it. Runs on the capture hot path BEFORE EventNormalizer, in
 * the controller, once per request: {@see self::forRequest()} resolves the request-wide parts (User-Agent
 * header, client ip) and {@see RequestEnrichment::apply()} enriches each event.
 *
 * Rules:
 *  - FILL ONLY: a property the SDK sent (non-empty) is never replaced. posthog-js already sends `$browser`,
 *    `$os`, `$device_type`; server SDKs (posthog-node, posthog-python) do not and get them from the header.
 *  - FAIL SAFE: a failure while enriching an event is swallowed (one log line per minute, class name only) and
 *    the event goes on without the enrichment. It never rejects an event.
 *  - BOUNDED: the User-Agent is capped (512 bytes), parse results live in a bounded LRU, GeoIP is a local
 *    file lookup; there is no network or database call.
 *  - PRIVACY: `$ip` is removed after the lookup unless `store_ip` is set (CAPTURE_STORE_IP=true). This
 *    deviates from PostHog, which stores `$ip` by default.
 *
 * One instance lives per Octane worker (listed in config/octane.php `warm`), so the caches persist.
 */
final class Enricher
{
    private readonly LogThrottle $warnings;

    public function __construct(
        private readonly UserAgentParser $userAgents,
        private readonly GeoLookup $geo = new NullGeoLookup,
        private readonly bool $enabled = true,
        ?LogThrottle $warnings = null,
    ) {
        $this->warnings = $warnings ?? new LogThrottle(60);
    }

    /**
     * @param  string|null  $userAgent  the request's User-Agent header
     * @param  string|null  $clientIp  the client ip as the trusted proxy aware request resolved it, null when
     *                                 the client asked for no ip (`?ip=0`)
     * @param  bool  $storeIp  keep `$ip` in the stored properties (default: strip it)
     */
    public function forRequest(?string $userAgent, ?string $clientIp, bool $storeIp = false): RequestEnrichment
    {
        return new RequestEnrichment(
            $this->enabled ? $this->userAgents : null,
            $this->enabled ? $this->geo : null,
            $userAgent,
            $clientIp,
            $storeIp,
            $this->warnings,
        );
    }
}
