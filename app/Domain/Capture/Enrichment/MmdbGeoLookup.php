<?php

namespace App\Domain\Capture\Enrichment;

use Closure;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader;
use Throwable;

/**
 * GeoIP from an operator supplied MaxMind DB file (GeoLite2-City, or DB-IP Lite City: same layout). The
 * database is never bundled (licences); with no file the lookup is silently empty.
 *
 * Fail-safe and cheap by design, because it runs on the capture hot path inside a long-lived Octane worker:
 *  - the reader is opened lazily, once per worker, and kept open (maxmind-db/reader reads the file in small
 *    chunks, so the worker's memory does not grow with the database size);
 *  - a missing file logs ONE info line and a corrupt or unreadable one ONE warning, then it is not touched
 *    again for $retrySeconds (so an absent file costs one is_file() per interval, not per event);
 *  - every $retrySeconds the file's modification time is compared and a replaced database (a monthly
 *    update) is reopened without a restart;
 *  - every lookup runs inside a try/catch: a failure gives an empty result and never reaches the event.
 *
 * Output is validated, not trusted: the country code must match ^[A-Z]{2}$, the time zone must be a known
 * IANA identifier, names must be short valid UTF-8 without control characters.
 */
final class MmdbGeoLookup implements GeoLookup
{
    private const MAX_NAME_BYTES = 128;

    /** @var array<string, true>|null flipped DateTimeZone::listIdentifiers(), built once per process */
    private static ?array $timeZones = null;

    private ?Reader $reader = null;

    private ?int $loadedMtime = null;

    private int $checkedAt = 0;

    private bool $everChecked = false;

    private readonly LogThrottle $warnings;

    /** @param  Closure(): int|null  $clock  unix time, injected by tests */
    public function __construct(
        private readonly string $path,
        private readonly int $retrySeconds = 60,
        private readonly ?Closure $clock = null,
        ?LogThrottle $warnings = null,
    ) {
        $this->warnings = $warnings ?? new LogThrottle($retrySeconds, $clock);
    }

    public function lookup(string $ip): array
    {
        try {
            $reader = $this->reader();
            if ($reader === null) {
                return [];
            }

            $record = $reader->get($ip);

            return is_array($record) ? $this->properties($record) : [];
        } catch (\InvalidArgumentException) {
            // For example an IPv6 address looked up in an IPv4-only database: no answer, not a failure.
            return [];
        } catch (Throwable $e) {
            $this->failed($e);

            return [];
        }
    }

    public function close(): void
    {
        $this->reader?->close();
        $this->reader = null;
        $this->loadedMtime = null;
    }

    private function reader(): ?Reader
    {
        $now = $this->now();

        if ($this->everChecked && $now - $this->checkedAt < $this->retrySeconds) {
            return $this->reader;
        }

        $firstCheck = ! $this->everChecked;
        $this->everChecked = true;
        $this->checkedAt = $now;

        $mtime = is_file($this->path) ? @filemtime($this->path) : false;

        if ($mtime === false) {
            // Gone (or never there). Keep a reader that is already open: it still answers from the old inode.
            if ($this->reader === null && $firstCheck && $this->warnings->allow()) {
                Log::info('GeoIP database not found, geoip enrichment is off until the file appears', ['path' => $this->path]);
            }

            return $this->reader;
        }

        if ($this->reader !== null && $mtime === $this->loadedMtime) {
            return $this->reader;
        }

        try {
            $fresh = new Reader($this->path);
        } catch (Throwable $e) {
            $this->failed($e);

            // A reader that was open before the replacement stays in use; a first open that fails leaves none.
            return $this->reader;
        }

        $this->reader?->close();
        $this->reader = $fresh;
        $this->loadedMtime = $mtime;

        return $this->reader;
    }

    /**
     * @param  array<array-key, mixed>  $record
     * @return array<string, string>
     */
    private function properties(array $record): array
    {
        $properties = [];

        $country = is_array($record['country'] ?? null) ? $record['country'] : [];
        $code = $country['iso_code'] ?? null;
        if (is_string($code) && preg_match('/^[A-Z]{2}$/', $code) === 1) {
            $properties['$geoip_country_code'] = $code;
        }
        $this->put($properties, '$geoip_country_name', $this->name($country));

        $city = is_array($record['city'] ?? null) ? $record['city'] : [];
        $this->put($properties, '$geoip_city_name', $this->name($city));

        $subdivisions = $record['subdivisions'] ?? null;
        $first = is_array($subdivisions) && is_array($subdivisions[0] ?? null) ? $subdivisions[0] : [];
        $this->put($properties, '$geoip_subdivision_1_code', $this->text($first['iso_code'] ?? null, 16));
        $this->put($properties, '$geoip_subdivision_1_name', $this->name($first));

        $location = is_array($record['location'] ?? null) ? $record['location'] : [];
        $zone = $location['time_zone'] ?? null;
        if (is_string($zone) && isset(self::timeZones()[$zone])) {
            $properties['$geoip_time_zone'] = $zone;
        }

        return $properties;
    }

    /** @param  array<array-key, mixed>  $entity  a record part with a `names` map */
    private function name(array $entity): ?string
    {
        $names = $entity['names'] ?? null;

        return is_array($names) ? $this->text($names['en'] ?? null, self::MAX_NAME_BYTES) : null;
    }

    private function text(mixed $value, int $maxBytes): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > $maxBytes) {
            return null;
        }

        // Valid UTF-8 and no control characters: this ends up in a JSON property and a ClickHouse String.
        return preg_match('/^[^\x00-\x1F\x7F]+$/u', $value) === 1 ? $value : null;
    }

    /** @param  array<string, string>  $properties */
    private function put(array &$properties, string $name, ?string $value): void
    {
        if ($value !== null) {
            $properties[$name] = $value;
        }
    }

    private function failed(Throwable $e): void
    {
        if ($this->warnings->allow()) {
            Log::warning('GeoIP database unusable, geoip enrichment is skipped', [
                'path' => $this->path,
                'exception' => $e::class,
            ]);
        }
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    /** @return array<string, true> */
    private static function timeZones(): array
    {
        return self::$timeZones ??= array_fill_keys(DateTimeZone::listIdentifiers(), true);
    }
}
