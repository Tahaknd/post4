<?php

namespace App\Actions\SavedInsights;

use App\Domain\Dashboards\DashboardAudit;
use App\Domain\Dashboards\Labels;
use App\Domain\Dashboards\Limits;
use App\Domain\Dashboards\SavedQueryDefinition;
use App\Domain\Insights\InsightTypes;
use App\Domain\Insights\Query\InvalidInsightQuery;
use App\Models\Project;
use App\Models\SavedInsight;
use App\Models\User;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The only place that inserts a saved insight (tests/Unit/DashboardWritesArchTest.php keeps it so). The definition is
 * built from the INPUT of the insight API through the type's own `fromInput`, so a saved insight obeys exactly the
 * rules of a live one; what is stored is the canonical array, its version, and how a relative range is resolved.
 * The limit per project is counted under a lock on the project row, so concurrent creations cannot overshoot it.
 */
final class CreateSavedInsight
{
    /**
     * @param  array<array-key, mixed>  $input  the body of `POST insights/{type}` (already shape-checked by the request; the domain rules are checked here again)
     *
     * @throws ValidationException
     */
    public function handle(Project $project, string $name, ?string $description, string $type, array $input, ?User $actor = null, ?DateTimeInterface $now = null): SavedInsight
    {
        $data = Validator::make(
            ['name' => Labels::normaliseName($name), 'description' => Labels::normaliseDescription($description), 'type' => $type],
            ['name' => Labels::name(), 'description' => Labels::description(), 'type' => ['required', 'string', Rule::in(InsightTypes::keys())]],
        )->validate();

        try {
            $definition = SavedQueryDefinition::fromInput($data['type'], $input, new DateTimeZone($project->timezone), $now ?? now());
        } catch (InvalidInsightQuery) {
            throw ValidationException::withMessages(['query' => 'This definition is not allowed.']);
        }

        return DB::transaction(function () use ($project, $data, $definition, $actor): SavedInsight {
            // The lock that makes the count exact: creations of one project queue up here.
            Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();

            if (SavedInsight::query()->where('project_id', $project->getKey())->count() >= Limits::savedInsights()) {
                throw Limits::reached('saved insights', Limits::savedInsights());
            }

            $insight = SavedInsight::query()->create([
                'uuid' => (string) Str::uuid7(),
                'project_id' => $project->getKey(),
                'name' => $data['name'],
                'description' => $data['description'],
                'type' => $definition->type,
                'query' => $definition->canonical,
                'query_version' => $definition->version,
                'date_preset' => $definition->datePreset,
                'timezone' => $definition->timezone,
                'created_by' => $actor?->getKey(),
            ]);

            DashboardAudit::record('saved_insight.create', $actor, (int) $project->getKey(), ['saved_insight' => $insight->uuid, 'type' => $definition->type]);

            return $insight;
        });
    }
}
