<?php

namespace App\Http\Requests\Concerns;

use App\Models\Project;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * For form requests that take a project id from the BODY or query instead of the URL. Route-bound `{project}`
 * is already limited to Project::visibleTo(user) (AppServiceProvider), but an id in the payload is not bound
 * by anything: it must be resolved the same way, and a project the user cannot open must look exactly like one
 * that does not exist (404, never 403, never a validation message that confirms the id is real).
 *
 * Use it from authorize():
 *
 *     public function authorize(): bool
 *     {
 *         $this->visibleProject($this->input('project_id'));
 *
 *         return true;
 *     }
 */
trait ResolvesVisibleProject
{
    /** @throws HttpResponseException 404 when the id is malformed, missing or not visible to the user */
    protected function visibleProject(mixed $id): Project
    {
        $user = $this->user();

        abort_unless(
            $user !== null && (is_int($id) || (is_string($id) && preg_match('/\A[0-9]{1,18}\z/', $id) === 1)),
            404,
        );

        return Project::query()->visibleTo($user)->whereKey($id)->firstOr(fn () => abort(404));
    }
}
