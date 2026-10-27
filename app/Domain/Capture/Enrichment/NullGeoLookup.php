<?php

namespace App\Domain\Capture\Enrichment;

/** Used when GeoIP is not available: every lookup is empty and costs nothing. */
final class NullGeoLookup implements GeoLookup
{
    public function lookup(string $ip): array
    {
        return [];
    }
}
