<?php

namespace App\Domain\Capture\Enrichment;

/**
 * Resolves a PUBLIC ip address to the `$geoip_*` event properties. Implementations never throw: no database,
 * a corrupt database or an unknown address all give an empty array.
 */
interface GeoLookup
{
    /**
     * @return array<string, string> only validated, non-empty `$geoip_*` properties
     */
    public function lookup(string $ip): array;
}
