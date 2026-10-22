<?php

namespace App\Domain\Capture;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class EventNormalizer
{
    /** ClickHouse materialized-view guard: lower bound (inclusive). */
    private const MIN_TIMESTAMP = '2000-01-01 00:00:00';

    /** ClickHouse materialized-view guard: upper bound is now + this many days (inclusive). */
    private const MAX_DAYS_AHEAD = 366;

    /**
     * @param  array<string, mixed>  $raw
     * @return array{uuid:string,project_id:int,event:string,distinct_id:string,properties:string,timestamp:string}|null
     */
    public function normalize(
        array $raw,
        int $project,
        CarbonImmutable $now,
        ?CarbonImmutable $sentAt = null,
        ?string $ip = null,
    ): ?array {
        $event = $raw['event'] ?? null;
        $properties = is_array($raw['properties'] ?? null) ? $raw['properties'] : [];
        $distinctId = $raw['distinct_id'] ?? $properties['distinct_id'] ?? null;

        if (! is_string($event) || $event === '') {
            return null;
        }
        if (! (is_string($distinctId) || is_int($distinctId)) || (string) $distinctId === '') {
            return null;
        }

        unset($properties['token']);
        if ($ip !== null && ! array_key_exists('$ip', $properties)) {
            $properties['$ip'] = $ip;
        }

        $uuid = $raw['uuid'] ?? null;

        return [
            'uuid' => is_string($uuid) && Str::isUuid($uuid) ? $uuid : (string) Str::uuid(),
            'project_id' => $project,
            'event' => $event,
            'distinct_id' => (string) $distinctId,
            'properties' => json_encode((object) $properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timestamp' => $this->clamp($this->timestamp($raw, $now, $sentAt), $now)->utc()->format('Y-m-d H:i:s.v'),
        ];
    }

    /** @param array<string, mixed> $raw */
    private function timestamp(array $raw, CarbonImmutable $now, ?CarbonImmutable $sentAt): CarbonImmutable
    {
        if (is_numeric($raw['offset'] ?? null)) {
            return $now->subMilliseconds((int) $raw['offset']);
        }

        if (! is_string($raw['timestamp'] ?? null)) {
            return $now;
        }

        try {
            $clientTime = CarbonImmutable::parse($raw['timestamp'], 'UTC');
        } catch (\Throwable) {
            return $now;
        }

        if ($sentAt === null) {
            return $clientTime;
        }

        // sunucu zamanı + (olay zamanı - istemcinin gönderim zamanı)
        return $now->subMilliseconds((int) $clientTime->diffInMilliseconds($sentAt, false));
    }

    /**
     * Timestamps outside [2000-01-01 UTC, now + 366 days] would be routed to
     * events_errors by ClickHouse; fall back to $now instead of losing the event.
     */
    private function clamp(CarbonImmutable $timestamp, CarbonImmutable $now): CarbonImmutable
    {
        $min = CarbonImmutable::parse(self::MIN_TIMESTAMP, 'UTC');
        $max = $now->addDays(self::MAX_DAYS_AHEAD);

        return $timestamp->lt($min) || $timestamp->gt($max) ? $now : $timestamp;
    }
}
