<?php

namespace App\Domain\Dashboards;

use App\Domain\Insights\InsightTypes;
use App\Domain\Insights\Query\InvalidInsightQuery;
use DateTimeInterface;
use DateTimeZone;

/**
 * What is stored of a saved insight's definition: the canonical array of the type, its version, and how the range is to
 * be resolved when it runs (`datePreset` for a relative range, null for absolute days; `timezone` is the zone of that
 * resolution). Built from the same INPUT the insight API takes, through the type's own `fromInput`, so a saved
 * definition obeys exactly the rules of a live one and cannot be stored in a shape that would not run.
 */
final readonly class SavedQueryDefinition
{
    /** @param  array<string, mixed>  $canonical */
    public function __construct(
        public string $type,
        public array $canonical,
        public int $version,
        public ?string $datePreset,
        public string $timezone,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input  the validated body of `POST insights/{type}`
     *
     * @throws InvalidInsightQuery the type is unknown or a rule of the definition is violated
     */
    public static function fromInput(string $type, array $input, DateTimeZone $projectZone, DateTimeInterface $now): self
    {
        if (! InsightTypes::has($type)) {
            throw new InvalidInsightQuery('Unknown insight type');
        }

        $query = InsightTypes::query($type)::fromInput($input, $projectZone, $now);
        $canonical = $query->canonical();

        $timezone = $input['timezone'] ?? $projectZone->getName();
        $preset = $input['date_preset'] ?? null;

        return new self(
            $type,
            $canonical,
            is_int($canonical['v'] ?? null) ? $canonical['v'] : throw new InvalidInsightQuery('A canonical definition carries its version'),
            is_string($preset) ? $preset : null,
            is_string($timezone) ? $timezone : $projectZone->getName(),
        );
    }
}
