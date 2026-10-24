<?php

namespace App\Http\Requests\Auth;

use App\Actions\Users\UserRules;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Registration being open is decided by EnsureRegistrationOpen and, authoritatively, RegisterFirstUser.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => User::normaliseEmail($this->input('email'))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => UserRules::name(),
            'email' => UserRules::email(),
            'password' => [...UserRules::password(), 'confirmed'],
        ];
    }
}
