<?php

namespace App\Http\Requests\Projects;

use App\Domain\Projects\ProjectRules;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['name' => ProjectRules::name(), 'timezone' => ProjectRules::timezone()];
    }

    /**
     * Fixed sentences: nothing the user typed is echoed back.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a project name.',
            'name.string' => 'Enter a project name.',
            'name.max' => 'The name must be '.ProjectRules::NAME_MAX_LENGTH.' characters or fewer.',
            'name.regex' => 'The name contains characters that are not allowed.',
            'timezone.required' => 'Choose a timezone.',
            'timezone.string' => 'Choose a timezone.',
            'timezone.in' => 'Choose a timezone from the list.',
        ];
    }
}
