<?php

namespace App\Http\Resources;

use App\Domain\Dashboards\SavedQueryResolver;
use App\Models\SavedInsight;
use DateTimeInterface;

/**
 * The JSON of a saved insight. Ids are uuids (never the auto-increment id). `state` is evaluated at THE MOMENT OF THE
 * READ ({@see SavedQueryResolver}): `ok`, or `needs_review` with a reason code, so a client can show a broken insight and
 * offer to repair it instead of failing; `migrated` says the type read an older stored version. `query` is the stored
 * canonical definition (only in the detail: lists and tiles carry the summary), `date` how its range is resolved.
 */
final class SavedInsightResource
{
    private function __construct() {}

    /** @return array<string, mixed> */
    public static function summary(SavedInsight $insight, SavedQueryResolver $resolver, DateTimeInterface $now): array
    {
        $state = $resolver->resolve($insight, $now);

        return [
            'id' => $insight->uuid,
            'name' => $insight->name,
            'description' => $insight->description,
            'type' => $insight->type,
            'date' => ['preset' => $insight->date_preset, 'timezone' => $insight->timezone],
            'state' => ['status' => $state->status, 'reason' => $state->reason, 'migrated' => $state->migrated],
            'created_by' => $insight->creator?->name,
            'created_at' => $insight->created_at?->toIso8601String(),
            'updated_at' => $insight->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(SavedInsight $insight, SavedQueryResolver $resolver, DateTimeInterface $now): array
    {
        $summary = self::summary($insight, $resolver, $now);

        return [
            ...array_slice($summary, 0, 4, true),
            'query' => $insight->query,
            'query_version' => $insight->query_version,
            ...array_slice($summary, 4, null, true),
        ];
    }
}
