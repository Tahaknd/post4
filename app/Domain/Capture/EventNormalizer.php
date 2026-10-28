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

    /** Longest accepted `event` / `distinct_id`, in characters. Longer values are dropped, never truncated. */
    private const MAX_NAME_LENGTH = 200;

    /** ClickHouse project_id column is UInt32. */
    private const MAX_PROJECT_ID = 4294967295;

    private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    /**
     * @param  int  $maxPropertiesBytes  Kafka's default message.max.bytes is 1 MB and the whole
     *                                   message (not only properties) must fit.
     * @param  int  $maxMessageBytes  Cap on the final JSON-encoded Kafka message: `properties` is
     *                                encoded again inside it, which escapes every `"` and `\`.
     */
    public function __construct(
        private readonly int $maxPropertiesBytes = 900_000,
        private readonly int $maxMessageBytes = 900_000,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     * @return array{uuid:string,project_id:int,event:string,distinct_id:string,properties:string,timestamp:string}|null
     */
    public function normalize(
        array $raw,
        int $project,
        CarbonImmutable $now,
        ?CarbonImmutable $sentAt = null,
    ): ?array {
        if ($project <= 0 || $project > self::MAX_PROJECT_ID) {
            throw new \InvalidArgumentException('project must be between 1 and 4294967295');
        }

        $event = $raw['event'] ?? null;
        $properties = is_array($raw['properties'] ?? null) ? $raw['properties'] : [];
        $distinctId = $raw['distinct_id'] ?? $properties['distinct_id'] ?? null;

        if (! is_string($event) || ! $this->validName($event)) {
            return null;
        }
        if (! is_string($distinctId) && ! is_int($distinctId)) {
            return null;
        }
        $distinctId = (string) $distinctId;
        if (! $this->validName($distinctId)) {
            return null;
        }

        unset($properties['token']);

        try {
            $propertiesJson = json_encode((object) $properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (strlen($propertiesJson) > $this->maxPropertiesBytes) {
            return null;
        }

        $message = [
            'uuid' => $this->uuid($raw['uuid'] ?? null),
            'project_id' => $project,
            'event' => $event,
            'distinct_id' => $distinctId,
            'properties' => $propertiesJson,
            'timestamp' => $this->clamp($this->timestamp($raw, $now, $sentAt), $now)->utc()->format('Y-m-d H:i:s.v'),
        ];

        // Same flags as the Kafka producer: this is the size that actually goes on the wire.
        try {
            $messageBytes = strlen(json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return null;
        }
        if ($messageBytes > $this->maxMessageBytes) {
            return null;
        }

        return $message;
    }

    /** Non-blank, at most 200 characters, and free of NUL / C0 control characters. */
    private function validName(string $value): bool
    {
        // 4 bytes per character is the UTF-8 maximum: cheap check before mb_strlen.
        if ($value === '' || strlen($value) > self::MAX_NAME_LENGTH * 4) {
            return false;
        }

        return trim($value) !== ''
            && mb_strlen($value) <= self::MAX_NAME_LENGTH
            && ! preg_match('/[\x00-\x1F\x7F]/', $value);
    }

    /** A client uuid is kept only when valid and not nil (ClickHouse routes nil to events_errors). */
    private function uuid(mixed $candidate): string
    {
        if (is_string($candidate) && Str::isUuid($candidate) && $candidate !== self::NIL_UUID) {
            return strtolower($candidate);
        }

        return (string) Str::uuid();
    }

    /** @param array<string, mixed> $raw */
    private function timestamp(array $raw, CarbonImmutable $now, ?CarbonImmutable $sentAt): CarbonImmutable
    {
        if (is_numeric($raw['offset'] ?? null)) {
            return $now->subMilliseconds((int) $raw['offset']);
        }

        // Carbon::parse also accepts relative strings ("tomorrow", "+1 day", "@123") that resolve
        // against the wall clock instead of the injected $now: only ISO-like input is parsed.
        if (! is_string($raw['timestamp'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $raw['timestamp'])) {
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
