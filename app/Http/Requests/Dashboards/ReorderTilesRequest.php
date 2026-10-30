<?php

namespace App\Http\Requests\Dashboards;

use App\Http\Requests\Concerns\ChecksJsonBody;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The body of `PUT /api/projects/{project}/dashboards/{dashboard}/tiles/order`: `tiles`, the tile uuids in the new order.
 * It must be EXACTLY the current set of tiles (checked by the action under the dashboard lock).
 */
class ReorderTilesRequest extends FormRequest
{
    use ChecksJsonBody;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('dashboard')) === true;
    }

    /** @return list<string> */
    protected function allowedKeys(): array
    {
        return ['tiles'];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'tiles' => ['present', 'array', 'max:'.max(1, (int) config('dashboards.max_tiles_per_dashboard'))],
            'tiles.*' => ['required', 'string', 'uuid', 'distinct:strict'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tiles' => 'tiles must be the list of the tile ids of the dashboard, in the new order.',
            'tiles.*' => 'Each entry must be the uuid of a tile, once.',
        ];
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
            if (is_array($this->input('tiles')) && ! array_is_list($this->input('tiles'))) {
                $validator->errors()->add('tiles', 'tiles must be a list.');
            }
        }];
    }

    /** @return list<string> */
    public function order(): array
    {
        return array_values(array_map('strval', $this->validated('tiles')));
    }
}
