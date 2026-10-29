<?php

namespace App\Http\Requests\Insights;

use App\Domain\Insights\Funnel\ConversionWindow;
use App\Domain\Insights\Funnel\FunnelOrder;
use App\Domain\Insights\Funnel\FunnelQuery;
use App\Domain\Insights\Funnel\FunnelStep;
use App\Domain\Insights\Funnel\WindowUnit;
use App\Domain\Insights\Query\EventName;
use App\Domain\Insights\Query\FilterOperator;
use App\Domain\Insights\Query\InvalidDateSelection;
use App\Domain\Insights\Query\InvalidInsightQuery;
use App\Domain\Insights\Query\PropertyFilter;
use App\Models\Project;
use DateTimeZone;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * The body of `POST /api/projects/{project}/insights/funnel`: a funnel definition WITHOUT the project (it comes from the
 * URL and the `{project}` binder, never from the body) and with a SERVER-resolved date range ({@see InsightRequest}).
 *
 * Fields (all keys outside this list are rejected with 422):
 *   steps        list of 2..8 objects {event, filters}; `event` 1..200 characters, `filters` (optional) up to 5 of
 *                {key, operator: equals|not_equals|contains|is_set, value} that the event must also satisfy      required
 *   window       {value, unit: minute|hour|day|week}, from 1 minute to 90 days; time from the first step          default 14 days
 *   order        ordered | strict_order (see FunnelOrder)                                                          default ordered
 *   timezone     an IANA identifier; the zone of the day arithmetic below                                         default: the project's zone
 *   date_preset  last_7_days | last_30_days | last_90_days | this_month | last_month                              \ exactly one of the two modes:
 *   date_from    YYYY-MM-DD, first local day (inclusive)                                                           / a preset, or date_from + date_to
 *   date_to      YYYY-MM-DD, last local day (inclusive)                                                           /
 * The cohort is the people whose first step happens in the date range; the window runs past its end (FunnelQuery).
 *
 * The definition is built through FunnelQuery::fromInput, so every constructor rule applies (the number of steps and
 * filters, the 366 days ...). Messages are FIXED sentences; nothing the caller sent is ever echoed.
 */
class FunnelInsightRequest extends InsightRequest
{
    private const array FIELDS = ['steps', 'window', 'order', 'timezone', 'date_preset', 'date_from', 'date_to'];

    private ?FunnelQuery $funnel = null;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'steps' => ['required', 'array', 'min:'.FunnelQuery::MIN_STEPS, 'max:'.FunnelQuery::MAX_STEPS],
            'steps.*' => ['array'],
            'steps.*.event' => ['required', 'string', 'min:1', 'max:'.EventName::MAX_LENGTH],
            'steps.*.filters' => ['sometimes', 'array', 'max:'.FunnelStep::MAX_FILTERS],
            'steps.*.filters.*' => ['array'],
            'steps.*.filters.*.key' => ['required', 'string', 'max:'.PropertyFilter::MAX_KEY_LENGTH],
            'steps.*.filters.*.operator' => ['required', 'string', Rule::in(array_column(FilterOperator::cases(), 'value'))],
            'steps.*.filters.*.value' => ['nullable', 'string', 'max:'.PropertyFilter::MAX_VALUE_LENGTH],
            'window' => ['sometimes', 'array'],
            'window.value' => ['required_with:window', 'integer:strict', 'min:1', 'max:'.ConversionWindow::MAX_VALUE],
            'window.unit' => ['required_with:window', 'string', Rule::in(array_column(WindowUnit::cases(), 'value'))],
            'order' => ['sometimes', 'filled', 'string', Rule::in(array_column(FunnelOrder::cases(), 'value'))],
        ] + $this->dateRules();
    }

    /**
     * Fixed sentences keyed by attribute. Laravel matches a wildcard key against `attribute.rule` too, so the list level
     * rules of `steps` and `steps.*.filters` get their own keys, which are looked up first.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $steps = 'Provide a list of '.FunnelQuery::MIN_STEPS.' to '.FunnelQuery::MAX_STEPS.' steps.';
        $filters = 'Provide a list of at most '.FunnelStep::MAX_FILTERS.' filters per step.';

        return [
            'steps.required' => $steps, 'steps.array' => $steps, 'steps.min' => $steps, 'steps.max' => $steps,
            'steps.*.filters.array' => $filters, 'steps.*.filters.max' => $filters,
            'steps' => $steps,
            'steps.*' => 'Each step must be an object with an event and optional filters.',
            'steps.*.event' => 'Each step needs an event name of 1 to '.EventName::MAX_LENGTH.' characters of text.',
            'steps.*.filters' => $filters,
            'steps.*.filters.*' => 'Each filter must be an object with key, operator and value.',
            'steps.*.filters.*.key' => 'A filter key must be 1 to '.PropertyFilter::MAX_KEY_LENGTH.' characters of text.',
            'steps.*.filters.*.operator' => 'A filter operator must be equals, not_equals, contains or is_set.',
            'steps.*.filters.*.value' => 'A filter value must be text of at most '.PropertyFilter::MAX_VALUE_LENGTH.' characters, or null.',
            'window' => 'The window must be an object with a value and a unit.',
            'window.value' => 'The window value must be a whole number from 1 to '.ConversionWindow::MAX_VALUE.'.',
            'window.unit' => 'The window unit must be minute, hour, day or week.',
            'order' => 'The order must be '.implode(' or ', array_column(FunnelOrder::cases(), 'value')).'.',
        ] + $this->dateMessages();
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
            $this->funnel = FunnelQuery::fromInput($this->validated(), new DateTimeZone($project->timezone), $this->now());
        } catch (InvalidDateSelection) {
            throw $this->invalidDateSelection();
        } catch (InvalidInsightQuery) {
            // The exception text names the broken rule but is not shown: one fixed sentence per request.
            throw ValidationException::withMessages(['definition' => 'This combination is not allowed. Use '.FunnelQuery::MIN_STEPS.' to '.FunnelQuery::MAX_STEPS.' steps with at most '.FunnelQuery::MAX_FILTERS.' filters in all, a window from 1 minute to 90 days and a range of at most 366 days.']);
        }
    }

    /** The validated definition. Only meaningful after validation passed (the controller runs after it). */
    public function definition(): FunnelQuery
    {
        return $this->funnel ?? throw new \LogicException('The definition is available after validation only.');
    }

    /** Whole-body rules that single-field rules cannot say: unknown keys, lists vs maps, one-of groups. */
    private function checkShape(Validator $validator): void
    {
        $input = $this->json()->all();

        if (array_diff(array_keys($input), self::FIELDS) !== []) {
            $validator->errors()->add('body', 'The request contains fields that are not supported.');
        }

        $steps = $input['steps'] ?? null;
        if (is_array($steps)) {
            if (! array_is_list($steps)) {
                $validator->errors()->add('steps', 'Provide a list of '.FunnelQuery::MIN_STEPS.' to '.FunnelQuery::MAX_STEPS.' steps.');
            }
            foreach ($steps as $i => $step) {
                if (! is_array($step) || ! is_int($i)) {
                    continue;
                }
                if (array_diff(array_keys($step), ['event', 'filters']) !== []) {
                    $validator->errors()->add("steps.{$i}", 'Each step must be an object with an event and optional filters.');

                    continue;
                }
                $this->checkFilterShapes($validator, $step['filters'] ?? [], "steps.{$i}.filters", 'Provide a list of at most '.FunnelStep::MAX_FILTERS.' filters per step.');
            }
        }

        $window = $input['window'] ?? null;
        if (is_array($window) && array_diff(array_keys($window), ['value', 'unit']) !== []) {
            $validator->errors()->add('window', 'The window must be an object with a value and a unit.');
        }

        $this->checkDateShape($validator, $input);
    }
}
