<?php

namespace App\Http\Requests\SavedInsights;

use App\Domain\Dashboards\Labels;
use App\Domain\Insights\InsightTypes;
use App\Http\Requests\Concerns\ChecksJsonBody;
use App\Models\SavedInsight;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * The body of `PATCH /api/projects/{project}/saved-insights/{insight}`: any of name, description and query (at least one).
 * A `query` replaces the whole definition and must be valid for the type the insight already has; the type itself never
 * changes. This is also how an insight that `needs_review` is repaired. Without `query` the stored definition is untouched.
 */
class UpdateSavedInsightRequest extends FormRequest
{
    use ChecksJsonBody;

    /** @var array<string, mixed>|null */
    private ?array $queryInput = null;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('insight')) === true;
    }

    /** @return list<string> */
    protected function allowedKeys(): array
    {
        return ['name', 'description', 'query'];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', ...Labels::name()],
            'description' => ['sometimes', ...Labels::description()],
            'query' => ['sometimes', 'array'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'A name of 1 to '.Labels::NAME_MAX_LENGTH.' characters is required.',
            'name.max' => 'The name can be at most '.Labels::NAME_MAX_LENGTH.' characters.',
            'description.max' => 'The description can be at most '.Labels::DESCRIPTION_MAX_LENGTH.' characters.',
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
        return [function (Validator $validator): void {
            $this->checkBodyKeys($validator);
            if ($validator->errors()->isEmpty() && array_diff($this->allowedKeys(), array_keys($this->json()->all())) === $this->allowedKeys()) {
                $validator->errors()->add('body', 'Send at least one of: '.implode(', ', $this->allowedKeys()).'.');
            }
        }];
    }

    protected function passedValidation(): void
    {
        if (! array_key_exists('query', $this->json()->all())) {
            return;
        }

        /** @var SavedInsight $insight */
        $insight = $this->route('insight');
        if (! InsightTypes::has((string) $insight->type)) {
            throw ValidationException::withMessages(['query' => 'The type of this saved insight is no longer supported: create a new one.']);
        }

        $this->queryInput = NestedInsightInput::validate($this, (string) $insight->type, json_decode($this->getContent())->query);
    }

    /**
     * What the action changes: the keys that were sent, with the validated definition input for `query`.
     *
     * @return array{name?: string, description?: ?string, query?: array<string, mixed>}
     */
    public function changes(): array
    {
        $sent = $this->json()->all();
        $changes = [];
        foreach (['name', 'description'] as $key) {
            if (array_key_exists($key, $sent)) {
                $changes[$key] = $this->input($key);
            }
        }
        if ($this->queryInput !== null) {
            $changes['query'] = $this->queryInput;
        }

        return $changes;
    }
}
