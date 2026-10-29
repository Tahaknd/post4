<?php

namespace App\Http\Requests\Insights;

use App\Domain\Insights\Query\DatePreset;
use App\Domain\Insights\Query\DateRange;
use App\Domain\Insights\Query\FilterOperator;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * What every `POST /api/projects/{project}/insights/{type}` body has in common: it is JSON of at most
 * {@see self::MAX_BODY_BYTES} bytes (415, 413 and 400 are answered BEFORE validation), the user may view the project, and
 * the time of the definition is a server-resolved range of WHOLE LOCAL DAYS of a zone: a `date_preset`, or both
 * `date_from` and `date_to` (`YYYY-MM-DD`, inclusive), optionally in another `timezone` than the project's.
 *
 * A type's request adds its own fields and, in `passedValidation()`, builds the definition through the type's
 * `fromInput` (every constructor rule applies there). Messages are FIXED sentences; nothing the caller sent is ever
 * echoed. Strings arrive untouched (the API skips TrimStrings and ConvertEmptyStringsToNull for these routes, see
 * bootstrap/app.php).
 */
abstract class InsightRequest extends FormRequest
{
    /** Largest body accepted: the legal maximum of a definition is about 15 KB. */
    public const int MAX_BODY_BYTES = 65536;

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

    /** The clock of date presets; a test (or a different clock source) overrides this one method. */
    protected function now(): DateTimeInterface
    {
        return now();
    }

    /** @return array<string, list<mixed>> */
    protected function dateRules(): array
    {
        return [
            'timezone' => ['sometimes', 'filled', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'date_preset' => ['sometimes', 'filled', 'string', Rule::in(array_column(DatePreset::cases(), 'value'))],
            'date_from' => ['sometimes', 'filled', 'string', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/'],
            'date_to' => ['sometimes', 'filled', 'string', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/'],
        ];
    }

    /** @return array<string, string> */
    protected function dateMessages(): array
    {
        return [
            'timezone' => 'Use an IANA time zone name, for example Europe/Istanbul.',
            'date_preset' => 'The date preset must be one of '.implode(', ', array_column(DatePreset::cases(), 'value')).'.',
            'date_from' => 'Dates must look like 2026-03-01.',
            'date_to' => 'Dates must look like 2026-03-01.',
        ];
    }

    /**
     * Exactly one of the two modes: a preset, or both days.
     *
     * @param  array<array-key, mixed>  $input
     */
    protected function checkDateShape(Validator $validator, array $input): void
    {
        $preset = array_key_exists('date_preset', $input);
        $absolute = array_key_exists('date_from', $input) || array_key_exists('date_to', $input);
        if ($preset === $absolute || ($absolute && ! (array_key_exists('date_from', $input) && array_key_exists('date_to', $input)))) {
            $validator->errors()->add('date_preset', 'Send either a date_preset, or both date_from and date_to.');
        }
    }

    /**
     * The shape rules of a list of property filters that single-field rules cannot say: it is a LIST, each filter has
     * exactly key, operator and value, and the value is present exactly when the operator takes one. `$path` is where the
     * list sits in the body (`filters`, `steps.0.filters`), `$listMessage` the fixed sentence for a list that is a map.
     */
    protected function checkFilterShapes(Validator $validator, mixed $filters, string $path, string $listMessage): void
    {
        if (! is_array($filters)) {
            return;
        }
        if (! array_is_list($filters)) {
            $validator->errors()->add($path, $listMessage);
        }
        foreach ($filters as $i => $filter) {
            if (! is_array($filter) || ! is_int($i)) {
                continue;
            }
            if (array_diff(array_keys($filter), ['key', 'operator', 'value']) !== []) {
                $validator->errors()->add("{$path}.{$i}", 'Each filter must be an object with key, operator and value.');

                continue;
            }
            $operator = is_string($filter['operator'] ?? null) ? FilterOperator::tryFrom($filter['operator']) : null;
            if ($operator !== null && $operator->needsValue() !== (($filter['value'] ?? null) !== null)) {
                $validator->errors()->add("{$path}.{$i}.value", $operator->needsValue()
                    ? 'This filter operator needs a value.'
                    : 'The is_set operator takes no value.');
            }
        }
    }

    /** The one sentence for an unusable date part (impossible day, end before start, longer than the maximum). */
    protected function invalidDateSelection(): ValidationException
    {
        return ValidationException::withMessages(['date_from' => 'Use real calendar days: the end must not be before the start, and the range can be at most '.DateRange::MAX_DAYS.' days.']);
    }
}
