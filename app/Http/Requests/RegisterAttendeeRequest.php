<?php

namespace App\Http\Requests;

use App\Support\AttendeePhone;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            $rules['phone_country'] = ['nullable', 'string', Rule::in(AttendeePhone::countryCodes())];
            $rules['phone'] = [
                'bail',
                'nullable',
                'string',
                'max:'.AttendeePhone::MAX,
                'regex:'.AttendeePhone::PATTERN,
                // PLAN 10.16: it has to be a number its country hands out, so
                // the owner never gets one WhatsApp cannot open. The person is
                // still on the form and can fix it — or leave it empty.
                function (string $attribute, mixed $value, Closure $fail): void {
                    $typed = (string) $value;

                    if (AttendeePhone::parse($typed, $this->phoneCountry()) !== null) {
                        return;
                    }

                    $fail(str_starts_with($typed, '+')
                        ? __('That doesn\'t look like a valid phone number. Check the country code and the area code.')
                        : __('That doesn\'t look like a valid number for :country. Check the area code, or choose another country.', [
                            'country' => AttendeePhone::countryName($this->phoneCountry()),
                        ]));
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
     * The phone to store: E.164 ("+5491123456789") since PLAN 10.16, empty as
     * null, and always null while the instance switch is off.
     */
    public function phone(): ?string
    {
        if (! AttendeePhone::enabled()) {
            return null;
        }

        $phone = trim((string) $this->input('phone', ''));

        return $phone === '' ? null : AttendeePhone::normalize($phone, $this->phoneCountry());
    }

    /**
     * The country the number was typed for: the selector's, or the instance's
     * when the selector is missing or names no listed country. A number that
     * starts with "+" carries its own code and ignores this.
     */
    private function phoneCountry(): string
    {
        $country = strtoupper(trim((string) $this->input('phone_country', '')));

        return in_array($country, AttendeePhone::countryCodes(), true) ? $country : AttendeePhone::defaultCountry();
    }
}
