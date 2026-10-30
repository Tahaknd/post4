<?php

namespace App\Http\Requests\Concerns;

use App\Domain\Dashboards\Labels;
use Illuminate\Validation\Validator;

/**
 * For form requests whose body is a JSON object: 415, 413 and 400 are answered BEFORE validation (the same order as the
 * insight requests), an unknown key is a 422, and the name and description are trimmed the way the actions trim them
 * (the API skips TrimStrings for these routes, so the strings of a definition stay exact). Call `checkJsonBody()` and
 * `normaliseLabels()` from `prepareForValidation()` and `checkBodyKeys()` from `after()`.
 */
trait ChecksJsonBody
{
    /** Largest body accepted (a definition is at most about 15 KB; names and descriptions add a little). */
    protected function maxBodyBytes(): int
    {
        return 70000;
    }

    protected function checkJsonBody(): void
    {
        $body = $this->getContent();

        abort_unless($this->isJson(), 415);
        abort_if(strlen($body) > $this->maxBodyBytes(), 413);
        abort_unless($body !== '' && json_validate($body), 400);
    }

    protected function normaliseLabels(): void
    {
        $merge = [];
        if (is_string($this->input('name'))) {
            $merge['name'] = Labels::normaliseName($this->input('name'));
        }
        if (is_string($this->input('description'))) {
            $merge['description'] = Labels::normaliseDescription($this->input('description'));
        }
        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /** @return list<string> */
    abstract protected function allowedKeys(): array;

    /** The body is a JSON OBJECT with only the allowed keys (a list or a scalar is not a body). */
    protected function checkBodyKeys(Validator $validator): void
    {
        $decoded = json_decode($this->getContent(), true);

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded)) || array_diff(array_keys($decoded), $this->allowedKeys()) !== []) {
            $validator->errors()->add('body', 'The request must be a JSON object with only these fields: '.implode(', ', $this->allowedKeys()).'.');
        }
    }
}
