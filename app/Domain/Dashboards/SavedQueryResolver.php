<?php

namespace App\Domain\Dashboards;

use App\Domain\Insights\InsightTypes;
use App\Domain\Insights\Query\DatePreset;
use App\Domain\Insights\Query\InvalidInsightQuery;
use App\Models\SavedInsight;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * The state policy of stored definitions. A saved insight is validated when it is written (SavedQueryDefinition) and
 * AGAIN every time it is read or run, because the code that understands a definition changes after it was stored.
 *
 *  1. The type must still be registered (`unknown_type`).
 *  2. A relative range (`date_preset`) is re-resolved to TODAY in the stored zone and replaces the range of the stored
 *     canonical array (every registered type keeps its range under `range`); an unusable preset or zone is
 *     `invalid_date`. An absolute range stays as saved.
 *  3. The result goes through the type's `fromCanonical`, which checks the shape and every rule of the type. A
 *     type that has been given a new VERSION and still reads the old one in `fromCanonical` (a real migration, written
 *     by whoever changed the meaning) yields `ok` with `migrated`; one that does not throws, and the row is
 *     `outdated_version` when its stored version differs from the type's current one, else `invalid_definition`.
 *
 * Nothing here ever throws or runs a query: the answer is data, so a list or a dashboard with a broken insight is
 * still a 200 with a clearly marked entry, never a 500, and a run request for it is refused (422) instead of running
 * the old definition under new semantics.
 */
final class SavedQueryResolver
{
    public function resolve(SavedInsight $insight, DateTimeInterface $now): SavedQueryState
    {
        $type = (string) $insight->type;
        if (! InsightTypes::has($type)) {
            return SavedQueryState::needsReview(SavedQueryState::REASON_UNKNOWN_TYPE);
        }

        $canonical = $insight->query;
        if (! is_array($canonical)) {
            return SavedQueryState::needsReview(SavedQueryState::REASON_INVALID_DEFINITION);
        }

        $preset = $insight->date_preset;
        if ($preset !== null) {
            $resolved = $this->resolveRange((string) $preset, (string) $insight->timezone, $now);
            if ($resolved === null) {
                return SavedQueryState::needsReview(SavedQueryState::REASON_INVALID_DATE);
            }
            $canonical['range'] = $resolved;
        }

        try {
            $query = InsightTypes::query($type)::fromCanonical($canonical);
        } catch (InvalidInsightQuery|Throwable) {
            return SavedQueryState::needsReview(
                (int) $insight->query_version !== self::currentVersion($type)
                    ? SavedQueryState::REASON_OUTDATED_VERSION
                    : SavedQueryState::REASON_INVALID_DEFINITION,
            );
        }

        return SavedQueryState::ok($query, migrated: (int) $insight->query_version !== self::currentVersion($type));
    }

    /** The VERSION the type writes today (a constant of its query class). */
    public static function currentVersion(string $type): int
    {
        return (int) constant(InsightTypes::query($type).'::VERSION');
    }

    /** @return array{from: string, to: string}|null */
    private function resolveRange(string $preset, string $timezone, DateTimeInterface $now): ?array
    {
        $case = DatePreset::tryFrom($preset);
        if ($case === null || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return null;
        }

        try {
            return $case->resolve($now, new DateTimeZone($timezone))->canonical();
        } catch (InvalidInsightQuery) {
            return null;
        }
    }
}
