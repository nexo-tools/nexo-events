{{-- The attendee phone (PLAN 10.13, 10.16): the country, starting on the
     instance's, then the number. One visible label for the pair; the
     selector is named for screen readers on its own. Stacked on a phone, so
     "Argentina (+54)" is never clipped; side by side from sm up. A number
     typed with "+" keeps its own country whatever the selector says. --}}
@props(['label', 'hint'])

@php
    $countries = \App\Support\AttendeePhone::countryOptions();
    $default = (string) array_key_first($countries);
    $selected = (string) old('phone_country', $default);
    $hasError = $errors->has('phone') || $errors->has('phone_country');
    $describedBy = 'phone-hint'.($hasError ? ' phone-error' : '');
    $control = 'rounded-lg border-control bg-surface text-ink shadow-sm focus:border-ring focus:ring-ring';
@endphp
<div>
    <label for="phone" class="mb-1 block text-sm font-medium">{{ $label }}</label>
    <div class="flex flex-col gap-2 sm:flex-row">
        <label for="phone_country" class="sr-only">{{ __('Phone country') }}</label>
        <select id="phone_country" name="phone_country"
                @if ($errors->has('phone_country')) aria-invalid="true" aria-describedby="phone-error" @endif
                class="{{ $control }} w-full sm:w-44 sm:shrink-0">
            <option value="{{ $default }}" @selected($selected === $default)>{{ $countries[$default] }}</option>
            <optgroup label="{{ __('All countries') }}">
                @foreach ($countries as $code => $option)
                    @continue($code === $default)
                    <option value="{{ $code }}" @selected($selected === $code)>{{ $option }}</option>
                @endforeach
            </optgroup>
        </select>
        <input id="phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" maxlength="{{ \App\Support\AttendeePhone::MAX }}"
               value="{{ old('phone') }}"
               @if ($errors->has('phone')) aria-invalid="true" @endif
               aria-describedby="{{ $describedBy }}"
               class="{{ $control }} w-full sm:min-w-0 sm:flex-1">
    </div>
    <p id="phone-hint" class="mt-1 text-xs text-muted">{{ $hint }}</p>
    @if ($hasError)
        <p id="phone-error" class="mt-1 text-sm text-danger-subtle-fg">{{ $errors->first('phone') ?: $errors->first('phone_country') }}</p>
    @endif
</div>
