<?php

namespace App\Http\Requests\SavedInsights;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The optional body of `POST /api/projects/{project}/saved-insights/{insight}/run`: no body at all, or `{"fresh": true}` to
 * skip the dashboard result cache (the answer then comes from the interactive cache or a new run).
 */
class RunSavedInsightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('insight')) === true;
    }

    protected function prepareForValidation(): void
    {
        $body = $this->getContent();
        if ($body === '') {
            return;
        }

        abort_unless($this->isJson(), 415);
        abort_if(strlen($body) > 1024, 413);
        abort_unless(json_validate($body), 400);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['fresh' => ['sometimes', 'boolean:strict']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['fresh' => 'fresh must be true or false.'];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $decoded = $this->getContent() === '' ? [] : json_decode($this->getContent(), true);
            if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded)) || array_diff(array_keys($decoded), ['fresh']) !== []) {
                $validator->errors()->add('body', 'The request body is empty or {"fresh": true|false}.');
            }
        }];
    }

    public function fresh(): bool
    {
        return $this->validated('fresh', false) === true;
    }
}
