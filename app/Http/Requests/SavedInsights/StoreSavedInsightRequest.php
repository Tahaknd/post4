<?php

namespace App\Http\Requests\SavedInsights;

use App\Domain\Dashboards\Labels;
use App\Domain\Insights\InsightTypes;
use App\Http\Requests\Concerns\ChecksJsonBody;
use App\Models\SavedInsight;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The body of `POST /api/projects/{project}/saved-insights`:
 *   name         1..120 characters, trimmed
 *   description  null or up to 500 characters                 optional
 *   type         trends | funnel | retention                  (a key of App\Domain\Insights\InsightTypes)
 *   query        the body of `POST insights/{type}` for that type, validated by that type's own request class
 * Unknown keys are refused. Strings arrive untouched (the API skips TrimStrings and ConvertEmptyStringsToNull for this
 * route, see ApiErrors::keepsStringsExact): the name and description are trimmed here and by the action, and the strings
 * of the definition keep their exact value.
 */
class StoreSavedInsightRequest extends FormRequest
{
    use ChecksJsonBody;

    /** @var array<string, mixed>|null */
    private ?array $queryInput = null;

    public function authorize(): bool
    {
        return $this->user()?->can('create', [SavedInsight::class, $this->route('project')]) === true;
    }

    /** @return list<string> */
    protected function allowedKeys(): array
    {
        return ['name', 'description', 'type', 'query'];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => Labels::name(),
            'description' => ['sometimes', ...Labels::description()],
            'type' => ['required', 'string', Rule::in(InsightTypes::keys())],
            'query' => ['required', 'array'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'A name of 1 to '.Labels::NAME_MAX_LENGTH.' characters is required.',
            'name.max' => 'The name can be at most '.Labels::NAME_MAX_LENGTH.' characters.',
            'description.max' => 'The description can be at most '.Labels::DESCRIPTION_MAX_LENGTH.' characters.',
            'type' => 'The type must be one of '.implode(', ', InsightTypes::keys()).'.',
            'query.required' => 'The query must be an object: the body of the insight request of this type.',
            'query.array' => 'The query must be an object: the body of the insight request of this type.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->checkJsonBody();
        $this->normaliseLabels();
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkBodyKeys($validator)];
    }

    /** The definition is validated by the request class of its type, once the fields around it passed. */
    protected function passedValidation(): void
    {
        $this->queryInput = NestedInsightInput::validate($this, (string) $this->input('type'), json_decode($this->getContent())->query);
    }

    /** The validated input of the definition (the body of the insight request of the type). @return array<string, mixed> */
    public function queryInput(): array
    {
        return $this->queryInput ?? throw new \LogicException('The query input is available after validation only.');
    }
}
