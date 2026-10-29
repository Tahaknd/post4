<?php

namespace App\Http\Requests\Insights;

use App\Domain\Insights\Query\FilterOperator;
use App\Domain\Insights\Query\InvalidDateSelection;
use App\Domain\Insights\Query\InvalidInsightQuery;
use App\Domain\Insights\Query\PropertyFilter;
use App\Domain\Insights\Trends\Breakdown;
use App\Domain\Insights\Trends\BreakdownColumn;
use App\Domain\Insights\Trends\Interval;
use App\Domain\Insights\Trends\Metric;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Models\Project;
use DateTimeZone;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * The body of `POST /api/projects/{project}/insights/trends`: a trends definition WITHOUT the project (it comes from
 * the URL and the `{project}` binder, never from the body) and with a SERVER-resolved date range.
 *
 * Fields (all keys outside this list are rejected with 422):
 *   events       list of 1..10 distinct event names, 1..200 characters each                      required
 *   metric       total_events | unique_users                                                      default total_events
 *   interval     hour | day | week | month                                                        default day
 *   timezone     an IANA identifier; the zone of the buckets AND of the day arithmetic below      default: the project's zone
 *   date_preset  last_7_days | last_30_days | last_90_days | this_month | last_month              \ exactly one of the two modes:
 *   date_from    YYYY-MM-DD, first local day (inclusive)                                           / a preset, or date_from + date_to
 *   date_to      YYYY-MM-DD, last local day (inclusive)                                           /
 *   breakdown    null | {column | property, top_n 1..25 (default 10)}                              default none
 *   filters      list of up to 10 {key, operator: equals|not_equals|contains|is_set, value}       default []
 *
 * Date ranges are WHOLE LOCAL DAYS in the zone: a preset is resolved here (today in that zone), absolute dates are
 * calendar days, so the canonical definition (and with it the result cache key) is the same for every request of the
 * same local day and changes when the day rolls over. No time of day is accepted.
 *
 * The definition is built through TrendsQuery::fromCanonical, so every constructor rule applies (series and filter
 * limits, 366 days, the row cap ...). Messages are FIXED sentences; nothing the caller sent is ever echoed back.
 * Strings arrive untouched (the API skips TrimStrings and ConvertEmptyStringsToNull, see bootstrap/app.php): an
 * event name keeps its spaces and `equals ''` keeps its empty value.
 */
class TrendsInsightRequest extends InsightRequest
{
    private const array FIELDS = ['events', 'metric', 'interval', 'timezone', 'date_preset', 'date_from', 'date_to', 'breakdown', 'filters'];

    private ?TrendsQuery $trends = null;

    /**
     * `filled` on every text field that must not be empty: the validator skips most rules for an empty string, so
     * without it `metric: ""` would reach the domain and answer with the generic "combination" sentence instead of
     * the field's own. A filter VALUE may be empty (`equals ''` is meaningful); a filter KEY may not (`required`).
     * `filled` cannot be combined with `nullable` (it rejects null), so the two nullable breakdown texts are checked
     * for emptiness in checkShape() instead.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:'.TrendsQuery::MAX_SERIES],
            'events.*' => ['required', 'string', 'min:1', 'max:'.TrendsQuery::MAX_EVENT_LENGTH, 'distinct:strict'],
            'metric' => ['sometimes', 'filled', 'string', Rule::in(array_column(Metric::cases(), 'value'))],
            'interval' => ['sometimes', 'filled', 'string', Rule::in(array_column(Interval::cases(), 'value'))],
            'breakdown' => ['sometimes', 'nullable', 'array'],
            'breakdown.column' => ['nullable', 'string', Rule::in(array_column(BreakdownColumn::cases(), 'value'))],
            'breakdown.property' => ['nullable', 'string', 'max:'.Breakdown::MAX_KEY_LENGTH],
            'breakdown.top_n' => ['sometimes', 'integer:strict', 'min:1', 'max:'.Breakdown::MAX_TOP_N],
            'filters' => ['sometimes', 'array', 'max:'.TrendsQuery::MAX_FILTERS],
            'filters.*' => ['array'],
            'filters.*.key' => ['required', 'string', 'max:'.PropertyFilter::MAX_KEY_LENGTH],
            'filters.*.operator' => ['required', 'string', Rule::in(array_column(FilterOperator::cases(), 'value'))],
            'filters.*.value' => ['nullable', 'string', 'max:'.PropertyFilter::MAX_VALUE_LENGTH],
        ] + $this->dateRules();
    }

    /**
     * Fixed sentences keyed by attribute (every failing rule of the attribute gets the same one).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        // Laravel matches a wildcard key against `attribute.rule` too, so `events.*` would also answer a failed `min` of
        // `events` itself: the list-level rules get their own keys, which are looked up first.
        $events = 'Provide a list of 1 to '.TrendsQuery::MAX_SERIES.' event names.';
        $filters = 'Provide a list of at most '.TrendsQuery::MAX_FILTERS.' filters.';

        return [
            'events.required' => $events, 'events.array' => $events, 'events.min' => $events, 'events.max' => $events,
            'filters.array' => $filters, 'filters.max' => $filters,
            'events' => $events,
            'events.*' => 'Each event name must be 1 to '.TrendsQuery::MAX_EVENT_LENGTH.' characters of text, and names must be distinct.',
            'metric' => 'The metric must be total_events or unique_users.',
            'interval' => 'The interval must be hour, day, week or month.',
            'breakdown' => 'The breakdown must be null or an object.',
            'breakdown.column' => 'The breakdown column must be one of '.implode(', ', array_column(BreakdownColumn::cases(), 'value')).'.',
            'breakdown.property' => 'The breakdown property must be 1 to '.Breakdown::MAX_KEY_LENGTH.' characters of text.',
            'breakdown.top_n' => 'The breakdown top_n must be a whole number from 1 to '.Breakdown::MAX_TOP_N.'.',
            'filters' => 'Provide a list of at most '.TrendsQuery::MAX_FILTERS.' filters.',
            'filters.*' => 'Each filter must be an object with key, operator and value.',
            'filters.*.key' => 'A filter key must be 1 to '.PropertyFilter::MAX_KEY_LENGTH.' characters of text.',
            'filters.*.operator' => 'A filter operator must be equals, not_equals, contains or is_set.',
            'filters.*.value' => 'A filter value must be text of at most '.PropertyFilter::MAX_VALUE_LENGTH.' characters, or null.',
        ] + $this->dateMessages();
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkShape($validator)];
    }

    /**
     * Assembly belongs to the domain ({@see TrendsQuery::fromInput()}); this only feeds it the validated input and
     * turns its two exception types into fixed field-level messages. Runs after every rule passed.
     */
    protected function passedValidation(): void
    {
        /** @var Project $project */
        $project = $this->route('project');

        try {
            $this->trends = TrendsQuery::fromInput($this->validated(), new DateTimeZone($project->timezone), $this->now());
        } catch (InvalidDateSelection) {
            throw $this->invalidDateSelection();
        } catch (InvalidInsightQuery) {
            // The exception text names the broken rule but is not shown: one fixed sentence per request.
            throw ValidationException::withMessages(['definition' => 'This combination is not allowed. Use at most '.TrendsQuery::MAX_SERIES.' series, a smaller breakdown, or a shorter range or larger interval (a result may have at most '.TrendsQuery::MAX_ROWS.' rows).']);
        }
    }

    /** The validated definition. Only meaningful after validation passed (the controller runs after it). */
    public function definition(): TrendsQuery
    {
        return $this->trends ?? throw new \LogicException('The definition is available after validation only.');
    }

    /** Whole-body rules that single-field rules cannot say: unknown keys, lists vs maps, one-of groups. */
    private function checkShape(Validator $validator): void
    {
        $input = $this->json()->all();

        if (array_diff(array_keys($input), self::FIELDS) !== []) {
            $validator->errors()->add('body', 'The request contains fields that are not supported.');
        }
        if (isset($input['events']) && is_array($input['events']) && ! array_is_list($input['events'])) {
            $validator->errors()->add('events', 'Provide a list of 1 to '.TrendsQuery::MAX_SERIES.' event names.');
        }

        $breakdown = $input['breakdown'] ?? null;
        if (is_array($breakdown)) {
            if (array_diff(array_keys($breakdown), ['column', 'property', 'top_n']) !== []) {
                $validator->errors()->add('breakdown', 'The breakdown has fields that are not supported.');
            } elseif (array_filter(['column', 'property'], fn (string $key) => is_string($breakdown[$key] ?? null) && trim($breakdown[$key]) === '') !== []) {
                foreach (['column', 'property'] as $key) {
                    if (is_string($breakdown[$key] ?? null) && trim($breakdown[$key]) === '') {
                        $validator->errors()->add("breakdown.{$key}", $this->messages()["breakdown.{$key}"]);
                    }
                }
            } elseif ((($breakdown['column'] ?? null) === null) === (($breakdown['property'] ?? null) === null)) {
                $validator->errors()->add('breakdown', 'A breakdown needs exactly one of column and property.');
            }
        }

        $filters = $input['filters'] ?? [];
        if (is_array($filters)) {
            if (! array_is_list($filters)) {
                $validator->errors()->add('filters', 'Provide a list of at most '.TrendsQuery::MAX_FILTERS.' filters.');
            }
            foreach ($filters as $i => $filter) {
                if (! is_array($filter) || ! is_int($i)) {
                    continue;
                }
                if (array_diff(array_keys($filter), ['key', 'operator', 'value']) !== []) {
                    $validator->errors()->add("filters.{$i}", 'Each filter must be an object with key, operator and value.');

                    continue;
                }
                $operator = is_string($filter['operator'] ?? null) ? FilterOperator::tryFrom($filter['operator']) : null;
                if ($operator !== null && $operator->needsValue() !== (($filter['value'] ?? null) !== null)) {
                    $validator->errors()->add("filters.{$i}.value", $operator->needsValue()
                        ? 'This filter operator needs a value.'
                        : 'The is_set operator takes no value.');
                }
            }
        }

        $this->checkDateShape($validator, $input);
    }
}
