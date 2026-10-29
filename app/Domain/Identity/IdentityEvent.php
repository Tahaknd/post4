<?php

namespace App\Domain\Identity;

/**
 * One normalized `$identify`, `$create_alias` or `$merge_dangerously` event, reduced to what identity needs: the
 * project, the kind, the event's own distinct id (the id whose person SURVIVES a merge) and the other id.
 *
 * Pure: it parses and nothing else. Whether the two ids are acceptable (DistinctIdRules) and what the event does to
 * the persons is IdentityResolver's decision.
 */
final readonly class IdentityEvent
{
    public function __construct(
        public int $projectId,
        public IdentityKind $kind,
        public string $distinctId,
        public string $otherDistinctId,
        public ?string $eventUuid = null,
    ) {}

    /**
     * Parses a normalized event (the Kafka message of the events topic: `event`, `distinct_id`, `project_id`,
     * `properties` as a JSON string, `uuid`). Null when the event is not an identity event, or carries no id to link
     * (`$identify` without `$anon_distinct_id` is a plain identify that only sets properties; `$create_alias` without
     * `alias` has nothing to alias).
     *
     * The other id is converted the way JavaScript's `String()` does, because that is what PostHog does with it and
     * what the SDKs' mistakes look like: a number becomes its text, `true` becomes "true", `null` becomes "null" and
     * an object becomes "[object Object]". Those texts are then refused by DistinctIdRules instead of being linked.
     *
     * @param  array<string, mixed>  $event
     */
    public static function fromNormalized(array $event): ?self
    {
        $name = $event['event'] ?? null;
        $kind = is_string($name) ? IdentityKind::fromEventName($name) : null;
        $distinctId = $event['distinct_id'] ?? null;
        $projectId = $event['project_id'] ?? null;

        if ($kind === null || ! is_string($distinctId) || ! is_int($projectId) || $projectId <= 0) {
            return null;
        }

        $properties = self::properties($event['properties'] ?? null);
        $other = self::counterpart($kind, $properties);

        if ($other === null) {
            return null;
        }

        $uuid = $event['uuid'] ?? null;

        return new self($projectId, $kind, $distinctId, $other, is_string($uuid) ? $uuid : null);
    }

    /** @return list<string> both ids, the event's own first */
    public function distinctIds(): array
    {
        return [$this->distinctId, $this->otherDistinctId];
    }

    /** The first id DistinctIdRules refuses, or null when both are acceptable. */
    public function invalidDistinctId(): ?string
    {
        foreach ($this->distinctIds() as $id) {
            if (! DistinctIdRules::isValid($id)) {
                return $id;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function properties(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The `alias` of PostHog wins; the alternative property (see IdentityKind) is read only when it is absent.
     *
     * @param  array<string, mixed>  $properties
     */
    private static function counterpart(IdentityKind $kind, array $properties): ?string
    {
        foreach ([$kind->counterpartProperty(), $kind->alternativeCounterpartProperty()] as $property) {
            if ($property !== null && array_key_exists($property, $properties)) {
                return self::stringify($properties[$property]);
            }
        }

        return null;
    }

    /** JavaScript's String(value) for a decoded JSON value. */
    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_null($value) => 'null',
            default => '[object Object]',
        };
    }
}
