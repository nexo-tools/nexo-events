<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\NexoSso\NexoAuthMode;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // This tool's own "remember me" is a credential Nexo ID can't revoke:
        // honoured in local mode only (nexo-sso-client, NEXO_AUTH_MODE).
        $remember = $this->boolean('remember') && NexoAuthMode::allowsRemember();

        // Transition: an account already linked to Nexo ID signs in there. The
        // callback only runs once the password matched, so the answer reveals
        // nothing to someone who doesn't know it.
        $linked = false;
        $signedIn = Auth::attemptWhen($this->only('email', 'password'), function (Authenticatable $user) use (&$linked): bool {
            $linked = $user instanceof User && NexoAuthMode::refusesPasswordFor($user->nexo_id_sub);

            return ! $linked;
        }, $remember);

        if ($linked) {
            throw ValidationException::withMessages([
                'email' => __('This account already signs in with Nexo ID.'),
            ]);
        }

        if (! $signedIn) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        throw ValidationException::withMessages([
            'email' => __('Too many attempts. Try again in :seconds seconds.', [
                'seconds' => RateLimiter::availableIn($this->throttleKey()),
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
