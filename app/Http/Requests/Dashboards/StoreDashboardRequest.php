<?php

namespace App\Http\Requests\Dashboards;

use App\Domain\Dashboards\Labels;
use App\Http\Requests\Concerns\ChecksJsonBody;
use App\Models\Dashboard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** The body of `POST /api/projects/{project}/dashboards`: name (1..120, trimmed) and an optional description (up to 500). */
class StoreDashboardRequest extends FormRequest
{
    use ChecksJsonBody;

    public function authorize(): bool
    {
        return $this->user()?->can('create', [Dashboard::class, $this->route('project')]) === true;
    }

    /** @return list<string> */
    protected function allowedKeys(): array
    {
        return ['name', 'description'];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['name' => Labels::name(), 'description' => ['sometimes', ...Labels::description()]];
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
        return [fn (Validator $validator) => $this->checkBodyKeys($validator)];
    }
}
