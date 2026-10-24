<?php

namespace App\Http\Requests\Projects;

use App\Models\Project;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Deleting a project destroys its events, so the form makes the administrator type the project's name and the
 * server checks it again: the typed confirmation is not only a front-end nicety.
 */
class DeleteProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $this->user()?->can('delete', $project) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [
            'confirm_name' => [
                'required',
                'string',
                fn (string $attribute, mixed $value, Closure $fail) => is_string($value) && trim($value) === $project->name
                    ? null
                    : $fail('Type the project name exactly to confirm.'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['confirm_name.required' => 'Type the project name exactly to confirm.'];
    }
}
