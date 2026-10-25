<?php

namespace App\Http\Requests\Insights;

use App\Domain\Insights\Trends\BreakdownColumn;
use App\Domain\Insights\Trends\DatePreset;
use App\Domain\Insights\Trends\DateRange;
use App\Domain\Insights\Trends\FilterOperator;
use App\Domain\Insights\Trends\Interval;
use App\Domain\Insights\Trends\InvalidTrendsQuery;
use App\Domain\Insights\Trends\Metric;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Models\Project;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
class TrendsInsightRequest extends FormRequest
{
    /** Largest body accepted: the legal maximum is about 15 KB. */
    public const int MAX_BODY_BYTES = 65536;

    private const array FIELDS = ['events', 'metric', 'interval', 'timezone', 'date_preset', 'date_from', 'date_to', 'breakdown', 'filters'];

    private ?TrendsQuery $trends = null;

    protected function prepareForValidation(): void
    {
        $body = $this->getContent();

        abort_unless($this->isJson(), 415);
        abort_if(strlen($body) > self::MAX_BODY_BYTES, 413);
        abort_unless($body !== '' && json_validate($body), 400);
    }

    public function authorize(): bool
    {
        // The binder already answered 404 for a project the user cannot open; this is the independent second check.
        return $this->user()?->can('view', $this->route('project')) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:'.TrendsQuery::MAX_SERIES],
            'events.*' => ['required', 'string', 'min:1', 'max:'.TrendsQuery::MAX_EVENT_LENGTH, 'distinct:strict'],
            'metric' => ['sometimes', 'string', Rule::in(array_column(Metric::cases(), 'value'))],
            'interval' => ['sometimes', 'string', Rule::in(array_column(Interval::cases(), 'value'))],
            'timezone' => ['sometimes', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'date_preset' => ['sometimes', 'string', Rule::in(array_column(DatePreset::cases(), 'value'))],
            'date_from' => ['sometimes', 'string', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/'],
            'date_to' => ['sometimes', 'string', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/'],
            'breakdown' => ['sometimes', 'nullable', 'array'],
            'breakdown.column' => ['nullable', 'string', Rule::in(array_column(BreakdownColumn::cases(), 'value'))],
            'breakdown.property' => ['nullable', 'string', 'min:1', 'max:200'],
            'breakdown.top_n' => ['sometimes', 'integer:strict', 'min:1', 'max:25'],
            'filters' => ['sometimes', 'array', 'max:'.TrendsQuery::MAX_FILTERS],
            'filters.*' => ['array'],
            'filters.*.key' => ['required', 'string', 'min:1', 'max:200'],
            'filters.*.operator' => ['required', 'string', Rule::in(array_column(FilterOperator::cases(), 'value'))],
            'filters.*.value' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Fixed sentences keyed by attribute (every failing rule of the attribute gets the same one).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'events' => 'Provide a list of 1 to '.TrendsQuery::MAX_SERIES.' event names.',
            'events.*' => 'Each event name must be 1 to '.TrendsQuery::MAX_EVENT_LENGTH.' characters of text, and names must be distinct.',
            'metric' => 'The metric must be total_events or unique_users.',
            'interval' => 'The interval must be hour, day, week or month.',
            'timezone' => 'Use an IANA time zone name, for example Europe/Istanbul.',
            'date_preset' => 'The date preset must be one of '.implode(', ', array_column(DatePreset::cases(), 'value')).'.',
            'date_from' => 'Dates must look like 2026-03-01.',
            'date_to' => 'Dates must look like 2026-03-01.',
            'breakdown' => 'The breakdown must be null or an object.',
            'breakdown.column' => 'The breakdown column must be one of '.implode(', ', array_column(BreakdownColumn::cases(), 'value')).'.',
            'breakdown.property' => 'The breakdown property must be 1 to 200 characters of text.',
            'breakdown.top_n' => 'The breakdown top_n must be a whole number from 1 to 25.',
            'filters' => 'Provide a list of at most '.TrendsQuery::MAX_FILTERS.' filters.',
            'filters.*' => 'Each filter must be an object with key, operator and value.',
            'filters.*.key' => 'A filter key must be 1 to 200 characters of text.',
            'filters.*.operator' => 'A filter operator must be equals, not_equals, contains or is_set.',
            'filters.*.value' => 'A filter value must be text of at most 1000 characters, or null.',
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->checkShape($validator);

            if ($validator->errors()->isEmpty()) {
                $this->buildQuery($validator);
            }
        }];
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

        $preset = array_key_exists('date_preset', $input);
        $absolute = array_key_exists('date_from', $input) || array_key_exists('date_to', $input);
        if ($preset === $absolute || ($absolute && ! (array_key_exists('date_from', $input) && array_key_exists('date_to', $input)))) {
            $validator->errors()->add('date_preset', 'Send either a date_preset, or both date_from and date_to.');
        }
    }

    private function buildQuery(Validator $validator): void
    {
        $input = $this->json()->all();
        /** @var Project $project */
        $project = $this->route('project');
        $timezone = $input['timezone'] ?? $project->timezone;

        try {
            $zone = new DateTimeZone($timezone);
            $range = array_key_exists('date_preset', $input)
                ? DatePreset::from($input['date_preset'])->resolve(now(), $zone)
                : DateRange::localDays($input['date_from'], $input['date_to'], $zone);
        } catch (InvalidTrendsQuery|\ValueError|\Exception) {
            $validator->errors()->add('date_from', 'Use real calendar days: the end must not be before the start, and the range can be at most '.DateRange::MAX_DAYS.' days.');

            return;
        }

        $breakdown = $input['breakdown'] ?? null;

        $canonical = [
            'v' => TrendsQuery::VERSION,
            'events' => $input['events'],
            'metric' => $input['metric'] ?? Metric::TotalEvents->value,
            'range' => $range->canonical(),
            'interval' => $input['interval'] ?? Interval::Day->value,
            'timezone' => $timezone,
            'breakdown' => $breakdown === null ? null : [
                'column' => $breakdown['column'] ?? null,
                'property' => $breakdown['property'] ?? null,
                'top_n' => $breakdown['top_n'] ?? 10,
            ],
            'filters' => array_map(fn (array $f) => ['key' => $f['key'], 'operator' => $f['operator'], 'value' => $f['value'] ?? null], $input['filters'] ?? []),
        ];

        try {
            $this->trends = TrendsQuery::fromCanonical($canonical);
        } catch (InvalidTrendsQuery) {
            // The exception text names the broken rule but is not shown: one fixed sentence per request.
            $validator->errors()->add('definition', 'This combination is not allowed. Use at most '.TrendsQuery::MAX_SERIES.' series, a smaller breakdown, or a shorter range or larger interval (a result may have at most '.TrendsQuery::MAX_ROWS.' rows).');
        }
    }
}
