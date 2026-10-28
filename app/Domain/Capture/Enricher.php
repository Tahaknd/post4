<?php

namespace App\Domain\Capture;

use App\Domain\Capture\Enrichment\GeoLookup;
use App\Domain\Capture\Enrichment\LogThrottle;
use App\Domain\Capture\Enrichment\NullGeoLookup;
use App\Domain\Capture\Enrichment\RequestEnrichment;
use App\Domain\Capture\Enrichment\UserAgentParser;

/**
 * Fills browser, operating system, device and (optionally) GeoIP properties of captured events, and decides
 * what is kept of the raw ip and the raw User-Agent. Runs on the capture hot path BEFORE EventNormalizer, in
 * the controller, once per request: {@see self::forRequest()} resolves the request-wide parts (User-Agent
 * header, client ip) and {@see RequestEnrichment::apply()} enriches each event.
 *
 * Rules:
 *  - FILL ONLY: a property the SDK sent (non-empty) is never replaced. posthog-js already sends `$browser`,
 *    `$os`, `$device_type`; server SDKs (posthog-node, posthog-python) do not and get them from the header.
 *  - FAIL SAFE: a failure while enriching an event is swallowed (one log line per minute per kind, class name
 *    only) and the event goes on without that part of the enrichment. It never rejects an event.
 *  - BOUNDED: the User-Agent is capped (512 bytes), parse results live in a bounded LRU, the number of distinct
 *    User-Agents one request may make the library parse is budgeted, GeoIP is a local file lookup; there is
 *    no network or database call.
 *  - PRIVACY: the raw ip (`$ip`, also inside `$set` and `$set_once`) and the SDK's raw User-Agent are used and
 *    then DROPPED unless `store_ip` / `store_raw_user_agent` are set (CAPTURE_STORE_IP,
 *    CAPTURE_STORE_RAW_UA). This deviates from PostHog, which stores `$ip` by default.
 *
 * ALL options are read ONCE when the singleton is built (config/capture.php, at worker boot), never per
 * request. One instance lives per Octane worker (listed in config/octane.php `warm`), so the caches persist; a
 * test that changes the config must rebuild the instance.
 */
final class Enricher
{
    private readonly LogThrottle $userAgentWarnings;

    private readonly LogThrottle $geoWarnings;

    public function __construct(
        private readonly UserAgentParser $userAgents,
        private readonly GeoLookup $geo = new NullGeoLookup,
        private readonly bool $enabled = true,
        private readonly bool $storeIp = false,
        private readonly bool $storeRawUserAgent = false,
        private readonly int $maxUserAgentParsesPerRequest = 20,
        ?LogThrottle $userAgentWarnings = null,
        ?LogThrottle $geoWarnings = null,
    ) {
        $this->userAgentWarnings = $userAgentWarnings ?? new LogThrottle(60);
        $this->geoWarnings = $geoWarnings ?? new LogThrottle(60);
    }

    /**
     * @param  string|null  $userAgent  the request's User-Agent header
     * @param  string|null  $clientIp  the client ip as the trusted proxy aware request resolved it
     * @param  bool  $ipDisabled  the request carried `?ip=0`
     */
    public function forRequest(?string $userAgent, ?string $clientIp, bool $ipDisabled = false): RequestEnrichment
    {
        return new RequestEnrichment(
            $this->enabled ? $this->userAgents : null,
            $this->enabled ? $this->geo : null,
            $userAgent,
            $clientIp,
            $ipDisabled,
            $this->storeIp,
            $this->storeRawUserAgent,
            $this->maxUserAgentParsesPerRequest,
            $this->userAgentWarnings,
            $this->geoWarnings,
        );
    }
}
