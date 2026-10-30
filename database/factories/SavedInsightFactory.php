<?php

namespace Database\Factories;

use App\Domain\Insights\InsightTypes;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Models\Project;
use App\Models\SavedInsight;
use DateTimeZone;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Builds the row directly (fixtures only: the application writes through App\Actions\SavedInsights). The default is a
 * trends insight over the last 7 days.
 *
 * @extends Factory<SavedInsight>
 */
class SavedInsightFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $query = TrendsQuery::fromInput(['events' => ['signup'], 'date_preset' => 'last_7_days'], new DateTimeZone('UTC'), now());

        return [
            'uuid' => (string) Str::uuid7(),
            'project_id' => Project::factory(),
            'name' => fake()->words(3, true),
            'description' => null,
            'type' => InsightTypes::TRENDS,
            'query' => $query->canonical(),
            'query_version' => TrendsQuery::VERSION,
            'date_preset' => 'last_7_days',
            'timezone' => 'UTC',
            'created_by' => null,
        ];
    }

    /** Stores a raw canonical array (possibly one that no longer validates). @param  array<string, mixed>  $query */
    public function withQuery(array $query, ?int $version = null): static
    {
        return $this->state(fn () => ['query' => $query, 'query_version' => $version ?? ($query['v'] ?? 1)]);
    }
}
