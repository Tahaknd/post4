<?php

namespace App\Domain\Dashboards;

use App\Domain\Insights\InsightQuery;

/**
 * Whether a saved insight can be run NOW, and why not. `ok` carries the query as it must run today (a relative range
 * resolved to today); `needs_review` carries a machine readable reason and no query: such an insight is shown, listed
 * and editable, but never run, refreshed or served from a cache.
 */
final readonly class SavedQueryState
{
    public const string OK = 'ok';

    public const string NEEDS_REVIEW = 'needs_review';

    /** The type key of the row is not (or no longer) registered. */
    public const string REASON_UNKNOWN_TYPE = 'unknown_type';

    /** Stored under another definition version than the current one, and the type cannot read that version. */
    public const string REASON_OUTDATED_VERSION = 'outdated_version';

    /** The stored definition no longer passes the rules of its type (or never matched its shape). */
    public const string REASON_INVALID_DEFINITION = 'invalid_definition';

    /** The relative range or its zone cannot be resolved. */
    public const string REASON_INVALID_DATE = 'invalid_date';

    private function __construct(
        public string $status,
        public ?string $reason,
        public ?InsightQuery $query,
        /** The type read an older stored version (its `fromCanonical` accepts it); the row is rewritten on the next save. */
        public bool $migrated = false,
    ) {}

    public static function ok(InsightQuery $query, bool $migrated = false): self
    {
        return new self(self::OK, null, $query, $migrated);
    }

    public static function needsReview(string $reason): self
    {
        return new self(self::NEEDS_REVIEW, $reason, null);
    }

    public function isRunnable(): bool
    {
        return $this->query !== null;
    }
}
