<?php

namespace App\Http\Requests\Insights;

use App\Domain\Insights\Query\EventCondition;
use App\Domain\Insights\Query\EventName;
use App\Domain\Insights\Query\FilterOperator;
use App\Domain\Insights\Query\InvalidDateSelection;
use App\Domain\Insights\Query\InvalidInsightQuery;
use App\Domain\Insights\Query\PropertyFilter;
use App\Domain\Insights\Retention\RetentionPeriod;
use App\Domain\Insights\Retention\RetentionQuery;
use App\Domain\Insights\Retention\RetentionType;
use App\Models\Project;
use DateTimeZone;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * The body of `POST /api/projects/{project}/insights/retention`: a retention definition WITHOUT the project (it comes from
 * the URL and the `{project}` binder, never from the body) and with a SERVER-resolved date range ({@see InsightRequest}).
 *
 * Fields (all keys outside this list are rejected with 422):
 *   target          {event, filters}: the event that puts a person in a cohort; `event` 1..200 characters, `filters`
 *                   (optional) up to 5 of {key, operator: equals|not_equals|contains|is_set, value}              required
 *   returning       {event, filters}: the event that counts as coming back, the same shape; may equal `target`   required
 *   period          day | week | month (weeks start on Monday)                                                  default day
 *   periods         1..30, how many periods after the cohort period are counted                                 default 8
 *   retention_type  first_time | recurring (see RetentionType)                                                  default first_time
 *   timezone        an IANA identifier; where days, weeks and months begin                                      default: the project's zone
 *   date_preset     last_7_days | last_30_days | last_90_days | this_month | last_month                          \ exactly one of the two modes:
 *   date_from       YYYY-MM-DD, first local day (inclusive)                                                      / a preset, or date_from + date_to
 *   date_to         YYYY-MM-DD, last local day (inclusive)                                                       /
 * The cohorts are the periods that overlap the date range (RetentionQuery).
 *
 * The definition is built through RetentionQuery::fromInput, so every constructor rule applies. Messages are FIXED
 * sentences; nothing the caller sent is ever echoed.
 */
class RetentionInsightRequest extends InsightRequest
{
    private const array FIELDS = ['target', 'returning', 'period', 'periods', 'retention_type', 'timezone', 'date_preset', 'date_from', 'date_to'];

    private ?RetentionQuery $retention = null;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $rules = [
            'period' => ['sometimes', 'filled', 'string', Rule::in(array_column(RetentionPeriod::cases(), 'value'))],
            'periods' => ['sometimes', 'integer:strict', 'min:'.RetentionQuery::MIN_PERIODS, 'max:'.RetentionQuery::MAX_PERIODS],
            'retention_type' => ['sometimes', 'filled', 'string', Rule::in(array_column(RetentionType::cases(), 'value'))],
        ];
        foreach (['target', 'returning'] as $role) {
            $rules += [
                $role => ['required', 'array'],
                "{$role}.event" => ['required', 'string', 'min:1', 'max:'.EventName::MAX_LENGTH],
                "{$role}.filters" => ['sometimes', 'array', 'max:'.EventCondition::MAX_FILTERS],
                "{$role}.filters.*" => ['array'],
                "{$role}.filters.*.key" => ['required', 'string', 'max:'.PropertyFilter::MAX_KEY_LENGTH],
                "{$role}.filters.*.operator" => ['required', 'string', Rule::in(array_column(FilterOperator::cases(), 'value'))],
                "{$role}.filters.*.value" => ['nullable', 'string', 'max:'.PropertyFilter::MAX_VALUE_LENGTH],
            ];
        }

        return $rules + $this->dateRules();
    }

    /**
     * Fixed sentences keyed by attribute (the list level rules of `filters` get their own keys, which are looked up first).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'period' => 'The period must be '.implode(', ', array_column(RetentionPeriod::cases(), 'value')).'.',
            'periods' => 'The number of periods must be a whole number from '.RetentionQuery::MIN_PERIODS.' to '.RetentionQuery::MAX_PERIODS.'.',
            'retention_type' => 'The retention type must be '.implode(' or ', array_column(RetentionType::cases(), 'value')).'.',
        ];
        $filters = 'Provide a list of at most '.EventCondition::MAX_FILTERS.' filters per event.';
        foreach (['target' => 'target event', 'returning' => 'returning event'] as $role => $name) {
            $messages += [
                $role => "Provide the {$name} as an object with an event and optional filters.",
                "{$role}.event" => "The {$name} needs a name of 1 to ".EventName::MAX_LENGTH.' characters of text.',
                "{$role}.filters" => $filters,
                "{$role}.filters.array" => $filters,
                "{$role}.filters.max" => $filters,
                "{$role}.filters.*" => 'Each filter must be an object with key, operator and value.',
                "{$role}.filters.*.key" => 'A filter key must be 1 to '.PropertyFilter::MAX_KEY_LENGTH.' characters of text.',
                "{$role}.filters.*.operator" => 'A filter operator must be equals, not_equals, contains or is_set.',
                "{$role}.filters.*.value" => 'A filter value must be text of at most '.PropertyFilter::MAX_VALUE_LENGTH.' characters, or null.',
            ];
        }

        return $messages + $this->dateMessages();
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkShape($validator)];
    }

    /** Builds the definition through the domain once every rule passed; two exception types become fixed sentences. */
    protected function passedValidation(): void
    {
        /** @var Project $project */
        $project = $this->route('project');

        try {
            $this->retention = RetentionQuery::fromInput($this->validated(), new DateTimeZone($project->timezone), $this->now());
        } catch (InvalidDateSelection) {
            throw $this->invalidDateSelection();
        } catch (InvalidInsightQuery) {
            // The exception text names the broken rule but is not shown: one fixed sentence per request.
            throw ValidationException::withMessages(['definition' => 'This combination is not allowed. Use 1 to '.RetentionQuery::MAX_PERIODS.' periods, at most '.EventCondition::MAX_FILTERS.' filters per event and a range of at most 366 days.']);
        }
    }

    /** The validated definition. Only meaningful after validation passed (the controller runs after it). */
    public function definition(): RetentionQuery
    {
        return $this->retention ?? throw new \LogicException('The definition is available after validation only.');
    }

    /** Whole-body rules that single-field rules cannot say: unknown keys, lists vs maps, one-of groups. */
    private function checkShape(Validator $validator): void
    {
        $input = $this->json()->all();

        if (array_diff(array_keys($input), self::FIELDS) !== []) {
            $validator->errors()->add('body', 'The request contains fields that are not supported.');
        }

        foreach (['target', 'returning'] as $role) {
            $condition = $input[$role] ?? null;
            if (! is_array($condition)) {
                continue;
            }
            if (array_diff(array_keys($condition), ['event', 'filters']) !== []) {
                $validator->errors()->add($role, "Provide the {$role} event as an object with an event and optional filters.");

                continue;
            }
            $this->checkFilterShapes($validator, $condition['filters'] ?? [], "{$role}.filters", 'Provide a list of at most '.EventCondition::MAX_FILTERS.' filters per event.');
        }

        $this->checkDateShape($validator, $input);
    }
}
