<?php

namespace App\Domain\Capture;

/**
 * What a feature flag request (`/flags`, `/decide`) asks, with its project already resolved. Built from the decoded
 * body of posthog-js (or a server SDK); a field that is missing or has the wrong type becomes its empty default,
 * the evaluator never sees raw SDK input.
 */
final readonly class FlagRequest
{
    /**
     * @param  array<string, mixed>  $groups  group type => group key
     * @param  array<string, mixed>  $personProperties
     * @param  array<string, mixed>  $groupProperties  group type => properties
     * @param  list<string>|null  $flagKeysToEvaluate  null = every flag
     */
    public function __construct(
        public int $projectId,
        public ?string $distinctId,
        public ?string $anonDistinctId,
        public array $groups,
        public array $personProperties,
        public array $groupProperties,
        public ?array $flagKeysToEvaluate,
        public ?string $timezone,
    ) {}

    /** @param array<string, mixed> $body */
    public static function fromBody(int $projectId, array $body): self
    {
        $text = fn (string $key): ?string => is_string($body[$key] ?? null) && $body[$key] !== '' ? $body[$key] : null;
        $map = fn (string $key): array => is_array($body[$key] ?? null) && ! array_is_list($body[$key]) ? $body[$key] : [];

        $keys = $body['flag_keys_to_evaluate'] ?? $body['flag_keys'] ?? null;

        return new self(
            $projectId,
            $text('distinct_id'),
            $text('$anon_distinct_id'),
            $map('groups'),
            $map('person_properties'),
            $map('group_properties'),
            is_array($keys) && array_is_list($keys) ? array_values(array_filter($keys, 'is_string')) : null,
            $text('timezone'),
        );
    }
}
