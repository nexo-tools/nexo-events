<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'reporter_email' => ['nullable', 'string', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:0'], // honeypot
        ];
    }
}
