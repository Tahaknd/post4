<?php

namespace App\Domain\Capture;

use App\Models\Project;
use Illuminate\Support\Facades\Cache;

final class ProjectResolver
{
    /** How long a resolved key stays cached, so also how long a deleted or re-keyed key may still be accepted if forgetting it fails. */
    public const POSITIVE_TTL_SECONDS = 300;

    private const NEGATIVE_TTL_SECONDS = 10;

    /**
     * A cache outage only costs a database lookup; a database failure propagates to the caller.
     */
    public function resolve(string $apiKey): ?int
    {
        // Format check first: garbage never reaches the cache or the database.
        if (! $this->wellFormed($apiKey)) {
            return null;
        }

        try {
            $id = Cache::get($this->positiveKey($apiKey));
            if (is_int($id)) {
                return $id;
            }

            if (Cache::has($this->negativeKey($apiKey))) {
                return null;
            }
        } catch (\Throwable) {
            // Cache unavailable: fall through to the database.
        }

        $id = Project::where('api_key', $apiKey)->value('id');
        if ($id === null) {
            $this->remember($this->negativeKey($apiKey), 1, self::NEGATIVE_TTL_SECONDS);

            return null;
        }

        $id = (int) $id;
        $this->remember($this->positiveKey($apiKey), $id, self::POSITIVE_TTL_SECONDS);

        return $id;
    }

    /** Drops both cache entries, e.g. after the key was rotated. */
    public function forget(string $apiKey): void
    {
        Cache::forget($this->positiveKey($apiKey));
        Cache::forget($this->negativeKey($apiKey));
    }

    private function remember(string $key, int $value, int $ttlSeconds): void
    {
        try {
            Cache::put($key, $value, $ttlSeconds);
        } catch (\Throwable) {
            // Cache unavailable: the next request simply asks the database again.
        }
    }

    private function wellFormed(string $apiKey): bool
    {
        return strlen($apiKey) === strlen(Project::API_KEY_PREFIX) + Project::API_KEY_RANDOM_LENGTH
            && preg_match(Project::API_KEY_PATTERN, $apiKey) === 1;
    }

    private function positiveKey(string $apiKey): string
    {
        return 'project_id_by_key:'.$apiKey;
    }

    private function negativeKey(string $apiKey): string
    {
        return 'project_missing:'.sha1($apiKey);
    }
}
