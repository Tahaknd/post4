<?php

namespace App\Http\Requests\Insights;

use App\Domain\Insights\Events\EventNames;
use App\Domain\Insights\Events\EventNamesSqlBuilder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The query string of `GET /api/projects/{project}/insights/events`: one optional text, `q`, a case-insensitive
 * substring of the event name. At most {@see EventNamesSqlBuilder::MAX_SEARCH_LENGTH} characters of plain text (no control
 * characters, valid UTF-8; the web group's RejectMalformedUtf8 already answers 400 for broken UTF-8 before this runs,
 * the regex rule is the independent second check). Unknown query parameters are ignored. Trimming is done HERE by
 * hand ({@see EventNames::normalise()}), the same way for a missing, empty and whitespace-only `q`: no search.
 * Messages are fixed sentences; what the caller typed is never echoed.
 */
class EventNamesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The binder already answered 404 for a project the user cannot open; this is the independent second check.
        return $this->user()?->can('view', $this->route('project')) === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:'.EventNamesSqlBuilder::MAX_SEARCH_LENGTH, 'regex:/\A[^\p{Cc}]*\z/u'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $message = 'The search text must be at most '.EventNamesSqlBuilder::MAX_SEARCH_LENGTH.' characters of plain text.';

        return ['q' => $message];
    }

    /** The normalised search text, or null when there is none. Only meaningful after validation passed. */
    public function search(): ?string
    {
        $q = $this->query('q');

        return EventNames::normalise(is_string($q) ? $q : null);
    }
}
