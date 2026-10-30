<?php

namespace App\Http\Requests\Dashboards;

use App\Http\Requests\Concerns\ChecksJsonBody;
use App\Models\SavedInsight;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The body of `POST /api/projects/{project}/dashboards/{dashboard}/tiles`: `saved_insight_id`, the uuid of a saved insight
 * OF THE SAME PROJECT. One that does not exist there (or is of another project) is the same 422, so the answer tells
 * nothing about other projects.
 */
class AddTileRequest extends FormRequest
{
    use ChecksJsonBody;

    private ?SavedInsight $insight = null;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('dashboard')) === true;
    }

    /** @return list<string> */
    protected function allowedKeys(): array
    {
        return ['saved_insight_id'];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['saved_insight_id' => ['required', 'string', 'uuid']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['saved_insight_id' => 'saved_insight_id must be the uuid of a saved insight of this project.'];
    }

    protected function prepareForValidation(): void
    {
        $this->checkJsonBody();
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->checkBodyKeys($validator);
            if ($validator->errors()->isEmpty()) {
                $project = $this->route('project');
                $this->insight = SavedInsight::query()->where('project_id', $project->getKey())->where('uuid', (string) $this->input('saved_insight_id'))->first();
                if ($this->insight === null) {
                    $validator->errors()->add('saved_insight_id', 'This saved insight does not exist in this project.');
                }
            }
        }];
    }

    public function savedInsight(): SavedInsight
    {
        return $this->insight ?? throw new \LogicException('The saved insight is available after validation only.');
    }
}
