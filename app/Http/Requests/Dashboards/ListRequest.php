<?php

namespace App\Http\Requests\Dashboards;

use App\Domain\Insights\InsightTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The query string of the list endpoints: `page` (from 1) and `per_page` (1 to dashboards.max_page_size, default
 * dashboards.default_page_size); the saved insight list also takes `type`. Authorisation is the controller's (the policy of
 * the listed thing); a bad value is a 422, never silently clamped.
 */
class ListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.max(1, (int) config('dashboards.max_page_size'))],
            'type' => ['sometimes', 'string', Rule::in(InsightTypes::keys())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'page' => 'page must be a whole number from 1.',
            'per_page' => 'per_page must be a whole number from 1 to '.config('dashboards.max_page_size').'.',
            'type' => 'type must be one of '.implode(', ', InsightTypes::keys()).'.',
        ];
    }

    public function page(): int
    {
        return (int) $this->validated('page', 1);
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', config('dashboards.default_page_size'));
    }

    public function type(): ?string
    {
        $type = $this->validated('type');

        return is_string($type) ? $type : null;
    }
}
