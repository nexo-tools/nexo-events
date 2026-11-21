<?php

namespace App\Http\Requests;

use App\Support\AttendeePhone;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class RegisterAttendeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:0'], // honeypot: real users leave it empty
        ];

        // PLAN 10.13: only validated where the instance asks for it. With the
        // switch off the field is not a rule at all, so phone() below never
        // reads it — a number posted to an instance that does not collect
        // numbers is dropped, not stored.
        if (AttendeePhone::enabled()) {
            $rules['phone'] = [
                'nullable',
                'string',
                'max:'.AttendeePhone::MAX,
                'regex:'.AttendeePhone::PATTERN,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (preg_match_all('/\d/', (string) $value) < AttendeePhone::MIN_DIGITS) {
                        $fail(__('The phone needs at least :count digits.', ['count' => AttendeePhone::MIN_DIGITS]));
                    }
                },
            ];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => __('Use only digits, spaces, parentheses, dashes, dots and an optional + at the start.'),
        ];
    }

    /**
     * The phone to store: trimmed, empty as null, and always null while the
     * instance switch is off.
     */
    public function phone(): ?string
    {
        if (! AttendeePhone::enabled()) {
            return null;
        }

        $phone = trim((string) $this->input('phone', ''));

        return $phone === '' ? null : $phone;
    }
}
