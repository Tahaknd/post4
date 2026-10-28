<?php

namespace App\Domain\Capture\Enrichment;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The enrichment of ONE capture request (see App\Domain\Capture\Enricher). Request-wide work is done lazily
 * and at most once: the User-Agent header is parsed on the first event that lacks a User-Agent derived
 * property (a batch of 500 events with one User-Agent parses once), and each distinct ip is looked up once.
 *
 * {@see self::derive()} is the PURE part: given an event's properties it returns the User-Agent and GeoIP
 * values that would be filled, and changes nothing. {@see self::apply()} uses it and then does the privacy
 * work (raw ip and raw User-Agent). It is also the intended reuse point of later milestones, without any
 * copy of the rules: the person properties of Milestone 4 (`$set` / `$set_once` with initial_* values from the
 * same derived browser, os, device and geo) and the `/flags` endpoint of Milestone 7 (geo and device targeting
 * of a flag from the request's own User-Agent and ip). Nothing of that exists yet.
 *
 * Values are read back from ClickHouse with JSONExtractString, which STRINGIFIES a non-string: an SDK that
 * sends `$os: 5` is stored (and broken down) as '5'. The enricher treats any non-null, non-empty value as
 * "the SDK sent it" and fills nothing in its place.
 */
final class RequestEnrichment
{
    /** Longest `$raw_user_agent` kept when the operator opted in to storing it. */
    public const MAX_STORED_RAW_USER_AGENT_BYTES = 512;

    private const RAW_USER_AGENT = '$raw_user_agent';

    /** @var array<string, string|int>|null */
    private ?array $headerUserAgent = null;

    /** @var array<string, array<string, string|int>> an event's own raw User-Agent => its result, this request only */
    private array $ownUserAgents = [];

    private int $ownParses = 0;

    /** @var array<string, array<string, string>> ip => geo properties, this request only */
    private array $geoByIp = [];

    /**
     * @param  string|null  $clientIp  the trusted proxy aware client ip, null when there is none
     * @param  bool  $ipDisabled  the request said `?ip=0`: no ip of any kind is used (no GeoIP, even for a body
     *                            `$ip`) and none is added; a body `$ip` is stored only when $storeIp is set
     * @param  int  $maxUserAgentParses  most DISTINCT User-Agent strings the events of this request may make the
     *                                   library parse; over it an event gets the header User-Agent's result
     */
    public function __construct(
        private readonly ?UserAgentParser $userAgents,
        private readonly ?GeoLookup $geo,
        private readonly ?string $userAgentHeader,
        private readonly ?string $clientIp,
        private readonly bool $ipDisabled,
        private readonly bool $storeIp,
        private readonly bool $storeRawUserAgent,
        private readonly int $maxUserAgentParses,
        private readonly LogThrottle $userAgentWarnings,
        private readonly LogThrottle $geoWarnings,
    ) {}

    /**
     * @param  array<string, mixed>  $raw  one decoded event
     * @return array<string, mixed> the same event with enriched, privacy filtered `properties`
     */
    public function apply(array $raw): array
    {
        $properties = is_array($raw['properties'] ?? null) ? $raw['properties'] : [];

        $derived = $this->derive($properties);
        foreach ($derived as $name => $value) {
            $properties[$name] = $value;
        }

        $properties = $this->filterRawUserAgent($properties);
        $properties = $this->filterIp($properties);

        $raw['properties'] = $properties;

        return $raw;
    }

    /**
     * The User-Agent and GeoIP properties this event would get: only those it lacks (missing, null or an empty
     * string). Pure: no merge, no stripping. A User-Agent failure never costs the GeoIP values and the other
     * way round (each has its own guard and its own throttled log line).
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<string, string|int>
     */
    public function derive(array $properties): array
    {
        $derived = [];

        if ($this->userAgents !== null) {
            try {
                $derived += $this->deriveUserAgent($properties);
            } catch (Throwable $e) {
                $this->failed($this->userAgentWarnings, 'user agent', $e);
            }
        }

        if ($this->geo !== null && ! $this->ipDisabled && ! self::truthy($properties['$geoip_disable'] ?? null)) {
            try {
                $derived += $this->deriveGeo($properties);
            } catch (Throwable $e) {
                $this->failed($this->geoWarnings, 'geoip', $e);
            }
        }

        return $derived;
    }

    /**
     * Family atomicity: a version is filled only together with the name it belongs to. An SDK that sent `$os`
     * keeps its own `$os` AND gets no `$os_version` from a different (the header's) User-Agent; the same for
     * `$browser`. `$device_type` stands alone.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<string, string|int>
     */
    private function deriveUserAgent(array $properties): array
    {
        $needsBrowser = ! self::present($properties['$browser'] ?? null);
        $needsOs = ! self::present($properties['$os'] ?? null);
        $needsDevice = ! self::present($properties['$device_type'] ?? null);
        if (! $needsBrowser && ! $needsOs && ! $needsDevice) {
            return [];
        }

        $parsed = $this->parsedUserAgent($properties);
        $derived = [];

        if ($needsBrowser && isset($parsed['$browser'])) {
            $derived['$browser'] = $parsed['$browser'];
            if (isset($parsed['$browser_version']) && ! self::present($properties['$browser_version'] ?? null)) {
                $derived['$browser_version'] = $parsed['$browser_version'];
            }
        }
        if ($needsOs && isset($parsed['$os'])) {
            $derived['$os'] = $parsed['$os'];
            if (isset($parsed['$os_version']) && ! self::present($properties['$os_version'] ?? null)) {
                $derived['$os_version'] = $parsed['$os_version'];
            }
        }
        if ($needsDevice && isset($parsed['$device_type'])) {
            $derived['$device_type'] = $parsed['$device_type'];
        }

        return $derived;
    }

    /**
     * An SDK that forwards the end user's browser string (`$raw_user_agent`) wins over the request header, which
     * is the SDK's own User-Agent. Such strings are attacker controlled and may differ in every event of a
     * batch, which makes the memo useless, so the number of DISTINCT strings one request may have parsed is
     * budgeted: memo hits and repeats within the request are free, the header never counts, and an event over
     * the budget simply gets the header's result.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<string, string|int>
     */
    private function parsedUserAgent(array $properties): array
    {
        $own = $properties[self::RAW_USER_AGENT] ?? null;
        if (is_string($own) && trim($own) !== '') {
            $own = substr($own, 0, UserAgentParser::MAX_LENGTH);
            if (isset($this->ownUserAgents[$own])) {
                return $this->ownUserAgents[$own];
            }

            $known = $this->userAgents->cached($own);
            if ($known === null && $this->ownParses < $this->maxUserAgentParses) {
                $this->ownParses++;
                $known = $this->userAgents->parse($own);
            }
            if ($known !== null) {
                // Bounded by the budget plus the memo hits of one request (at most the batch size).
                return $this->ownUserAgents[$own] = $known;
            }
        }

        if ($this->headerUserAgent === null) {
            $this->headerUserAgent = $this->userAgentHeader === null || $this->userAgentHeader === ''
                ? []
                : $this->userAgents->parse($this->userAgentHeader);
        }

        return $this->headerUserAgent;
    }

    /**
     * The ip to look up: an explicit public `$ip` property (server SDKs forward their end user's address),
     * otherwise the trusted proxy aware client ip. Only globally routable unicast addresses count
     * ({@see PublicIp}).
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<string, string>
     */
    private function deriveGeo(array $properties): array
    {
        $ip = PublicIp::normalise($properties['$ip'] ?? null) ?? PublicIp::normalise($this->clientIp);
        if ($ip === null) {
            return [];
        }

        $found = $this->geoByIp[$ip] ??= $this->geo->lookup($ip);

        // Fill only: a `$geoip_*` value the SDK sent stays.
        return array_filter($found, fn (string $name) => ! self::present($properties[$name] ?? null), ARRAY_FILTER_USE_KEY);
    }

    /**
     * The raw User-Agent is used (above) and then dropped, like the ip, unless the operator opted in; a kept
     * value is capped.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    private function filterRawUserAgent(array $properties): array
    {
        if (! array_key_exists(self::RAW_USER_AGENT, $properties)) {
            return $properties;
        }

        if (! $this->storeRawUserAgent) {
            unset($properties[self::RAW_USER_AGENT]);
        } elseif (is_string($properties[self::RAW_USER_AGENT])) {
            $properties[self::RAW_USER_AGENT] = mb_strcut($properties[self::RAW_USER_AGENT], 0, self::MAX_STORED_RAW_USER_AGENT_BYTES, 'UTF-8');
        }

        return $properties;
    }

    /**
     * THE one place that decides what is stored of the ip. Default: no `$ip` anywhere (top level, `$set`,
     * `$set_once`). With store_ip: the SDK's own `$ip` stays and, unless the request said `?ip=0`, the client ip
     * is added when the event has none.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    private function filterIp(array $properties): array
    {
        if ($this->storeIp) {
            if (! $this->ipDisabled && $this->clientIp !== null && ! self::present($properties['$ip'] ?? null)) {
                $properties['$ip'] = $this->clientIp;
            }

            return $properties;
        }

        unset($properties['$ip']);
        foreach (['$set', '$set_once'] as $bag) {
            if (is_array($properties[$bag] ?? null)) {
                unset($properties[$bag]['$ip']);
            }
        }

        return $properties;
    }

    private static function present(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || (is_string($value) && strtolower($value) === 'true');
    }

    private function failed(LogThrottle $throttle, string $what, Throwable $e): void
    {
        if ($throttle->allow()) {
            Log::warning("capture {$what} enrichment failed, the event is captured without it", ['exception' => $e::class]);
        }
    }
}
