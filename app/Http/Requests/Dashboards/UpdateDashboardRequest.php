<?php

namespace App\Http\Requests\Dashboards;

use App\Domain\Dashboards\Labels;
use App\Http\Requests\Concerns\ChecksJsonBody;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** The body of `PATCH /api/projects/{project}/dashboards/{dashboard}`: name and/or description (at least one). */
class UpdateDashboardRequest extends FormRequest
{
    use ChecksJsonBody;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('dashboard')) === true;
    }

    /** @return list<string> */
    protected function allowedKeys(): array
    {
        return ['name', 'description'];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['name' => ['sometimes', ...Labels::name()], 'description' => ['sometimes', ...Labels::description()]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'A name of 1 to '.Labels::NAME_MAX_LENGTH.' characters is required.',
            'name.max' => 'The name can be at most '.Labels::NAME_MAX_LENGTH.' characters.',
            'description.max' => 'The description can be at most '.Labels::DESCRIPTION_MAX_LENGTH.' characters.',
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
            if ($validator->errors()->isEmpty() && array_intersect($this->allowedKeys(), array_keys($this->json()->all())) === []) {
                $validator->errors()->add('body', 'Send at least one of: '.implode(', ', $this->allowedKeys()).'.');
            }
        }];
    }

    /** @return array{name?: string, description?: ?string} */
    public function changes(): array
    {
        $sent = $this->json()->all();
        $changes = [];
        foreach ($this->allowedKeys() as $key) {
            if (array_key_exists($key, $sent)) {
                $changes[$key] = $this->input($key);
            }
        }

        return $changes;
    }
}
