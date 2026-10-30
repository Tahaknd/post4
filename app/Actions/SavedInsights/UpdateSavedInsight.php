<?php

namespace App\Actions\SavedInsights;

use App\Domain\Dashboards\DashboardAudit;
use App\Domain\Dashboards\Labels;
use App\Domain\Dashboards\SavedQueryDefinition;
use App\Domain\Insights\Query\InvalidInsightQuery;
use App\Models\SavedInsight;
use App\Models\User;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Renames, re-describes or re-defines a saved insight. A new `query` (an input of the insight's OWN type: the type never
 * changes, a different type is a different insight) is validated through the type's `fromInput` and replaces the stored
 * canonical array, version and date handling as a whole: this is also how an insight in the `needs_review` state is
 * fixed. Without `query` the stored definition is not touched, so a rename works on an outdated insight too.
 */
final class UpdateSavedInsight
{
    /**
     * @param  array{name?: string, description?: ?string, query?: array<array-key, mixed>}  $changes  only the keys present are changed
     *
     * @throws ValidationException
     * @throws ModelNotFoundException the insight was deleted meanwhile
     */
    public function handle(SavedInsight $insight, array $changes, ?User $actor = null, ?DateTimeInterface $now = null): SavedInsight
    {
        $rules = [];
        $values = [];
        if (array_key_exists('name', $changes)) {
            $values['name'] = Labels::normaliseName((string) $changes['name']);
            $rules['name'] = Labels::name();
        }
        if (array_key_exists('description', $changes)) {
            $values['description'] = Labels::normaliseDescription($changes['description']);
            $rules['description'] = Labels::description();
        }
        $data = $rules === [] ? [] : Validator::make($values, $rules)->validate();

        return DB::transaction(function () use ($insight, $changes, $data, $actor, $now): SavedInsight {
            $locked = SavedInsight::query()->whereKey($insight->getKey())->lockForUpdate()->firstOrFail();

            $attributes = $data;
            if (array_key_exists('query', $changes)) {
                try {
                    $definition = SavedQueryDefinition::fromInput((string) $locked->type, $changes['query'], new DateTimeZone((string) $locked->project()->value('timezone')), $now ?? now());
                } catch (InvalidInsightQuery) {
                    throw ValidationException::withMessages(['query' => 'This definition is not allowed.']);
                }
                $attributes += [
                    'query' => $definition->canonical,
                    'query_version' => $definition->version,
                    'date_preset' => $definition->datePreset,
                    'timezone' => $definition->timezone,
                ];
            }

            if ($attributes !== []) {
                $locked->update($attributes);
                DashboardAudit::record('saved_insight.update', $actor, (int) $locked->project_id, ['saved_insight' => $locked->uuid, 'fields' => implode(',', array_keys($attributes))]);
            }

            return $locked;
        });
    }
}
