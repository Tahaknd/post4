<?php

namespace App\Domain\Capture\Enrichment;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The enrichment of ONE capture request (see App\Domain\Capture\Enricher). Request-wide work is done lazily
 * and at most once: the User-Agent header is parsed on the first event that lacks a User-Agent derived
 * property (a batch of 500 events with one User-Agent parses once), and each distinct ip is looked up once.
 */
final class RequestEnrichment
{
    /** Properties filled from the User-Agent; the raw string itself is never stored. */
    private const UA_PROPERTIES = ['$browser', '$browser_version', '$os', '$os_version', '$device_type'];

    /** @var array<string, string|int>|null */
    private ?array $headerUserAgent = null;

    /** @var array<string, array<string, string>> ip => geo properties, for this request only */
    private array $geoByIp = [];

    public function __construct(
        private readonly ?UserAgentParser $userAgents,
        private readonly ?GeoLookup $geo,
        private readonly ?string $userAgentHeader,
        private readonly ?string $clientIp,
        private readonly bool $storeIp,
        private readonly LogThrottle $warnings,
    ) {}

    /**
     * @param  array<string, mixed>  $raw  one decoded event
     * @return array<string, mixed> the same event with enriched `properties`
     */
    public function apply(array $raw): array
    {
        $properties = is_array($raw['properties'] ?? null) ? $raw['properties'] : [];

        if ($this->userAgents !== null) {
            try {
                $properties = $this->fill($properties);
            } catch (Throwable $e) {
                $this->failed($e);
            }
        }

        if (! $this->storeIp) {
            unset($properties['$ip']);
        }

        $raw['properties'] = $properties;

        return $raw;
    }

    /**
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    private function fill(array $properties): array
    {
        $fill = $this->userAgentProperties($properties);
        if ($fill !== []) {
            $properties = self::merge($properties, $fill);
        }

        if ($this->geo !== null && ! self::truthy($properties['$geoip_disable'] ?? null)) {
            $geo = $this->geoProperties($properties);
            if ($geo !== []) {
                $properties = self::merge($properties, $geo);
            }
        }

        return $properties;
    }

    /**
     * Only parses when the event lacks at least one of the properties it could fill.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<string, string|int>
     */
    private function userAgentProperties(array $properties): array
    {
        $missing = false;
        foreach (self::UA_PROPERTIES as $name) {
            if (! self::present($properties[$name] ?? null)) {
                $missing = true;
                break;
            }
        }
        if (! $missing) {
            return [];
        }

        // An SDK that forwards the end user's browser string (`$raw_user_agent`) wins over the header of the
        // request, which is the SDK's own User-Agent.
        $own = $properties['$raw_user_agent'] ?? null;
        if (is_string($own) && trim($own) !== '') {
            return $this->userAgents->parse($own);
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
     * otherwise the trusted proxy aware client ip. Private, loopback, link-local and reserved addresses are
     * never looked up.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<string, string>
     */
    private function geoProperties(array $properties): array
    {
        $ip = self::publicIp($properties['$ip'] ?? null) ?? self::publicIp($this->clientIp);
        if ($ip === null) {
            return [];
        }

        return $this->geoByIp[$ip] ??= $this->geo->lookup($ip);
    }

    /** Public address in canonical text form (IPv4-mapped IPv6 as IPv4), or null. */
    private static function publicIp(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 45) {
            return null;
        }

        $value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($value);
        if ($packed !== false && strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $value = inet_ntop(substr($packed, 12));
        }

        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            ? null
            : $value;
    }

    /**
     * Adds every entry of $fill that the event does not have yet (missing, null or an empty string).
     *
     * @param  array<array-key, mixed>  $properties
     * @param  array<string, string|int>  $fill
     * @return array<array-key, mixed>
     */
    private static function merge(array $properties, array $fill): array
    {
        foreach ($fill as $name => $value) {
            if (! self::present($properties[$name] ?? null)) {
                $properties[$name] = $value;
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

    private function failed(Throwable $e): void
    {
        if ($this->warnings->allow()) {
            Log::warning('capture enrichment failed, the event is captured without it', ['exception' => $e::class]);
        }
    }
}
