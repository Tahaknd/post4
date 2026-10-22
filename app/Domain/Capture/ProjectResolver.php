<?php

namespace App\Domain\Capture;

use App\Models\Project;
use Illuminate\Support\Facades\Cache;

final class ProjectResolver
{
    private const KEY_FORMAT = '/^phc_[A-Za-z0-9]{43}\z/';

    private const POSITIVE_TTL_SECONDS = 300;

    private const NEGATIVE_TTL_SECONDS = 60;

    public function resolve(string $apiKey): ?int
    {
        // Format check first: garbage never reaches the cache or the database.
        if (! $this->wellFormed($apiKey)) {
            return null;
        }

        $id = Cache::get($this->positiveKey($apiKey));
        if (is_int($id)) {
            return $id;
        }

        if (Cache::has($this->negativeKey($apiKey))) {
            return null;
        }

        $id = Project::where('api_key', $apiKey)->value('id');
        if ($id === null) {
            Cache::put($this->negativeKey($apiKey), 1, self::NEGATIVE_TTL_SECONDS);

            return null;
        }

        $id = (int) $id;
        Cache::put($this->positiveKey($apiKey), $id, self::POSITIVE_TTL_SECONDS);

        return $id;
    }

    /** Drops both cache entries, e.g. after the key was rotated. */
    public function forget(string $apiKey): void
    {
        Cache::forget($this->positiveKey($apiKey));
        Cache::forget($this->negativeKey($apiKey));
    }

    private function wellFormed(string $apiKey): bool
    {
        return strlen($apiKey) === 47 && preg_match(self::KEY_FORMAT, $apiKey) === 1;
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
