<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')],
            'password' => ['required', 'confirmed', Password::defaults()],
            // honeypot (SPEC-abuse AC-ABUSE-6): real users leave it empty. Without it,
            // scripted sign-ups with third parties' addresses made the instance mail them.
            'website' => ['nullable', 'string', 'max:0'],
        ];
    }
}
