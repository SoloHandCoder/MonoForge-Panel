<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Pterodactyl\Actions\Users\CreateUser;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The username and email rules of every new account, a confirmed password
     * that passes the panel's password policy, and the accepted rules.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        $rules = CreateUser::rules();

        return [
            'username' => $rules['username'],
            'email' => $rules['email'],
            'password' => ['required', 'confirmed', ...CreateUser::passwordRules()],
            'terms' => ['accepted'],
        ];
    }

    /** Rename fields to be more clear in error messages. */
    public function attributes(): array
    {
        return ['terms' => 'rules'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['terms.accepted' => 'You must agree to the rules to create an account.'];
    }
}
