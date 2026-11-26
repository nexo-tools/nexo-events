{{-- nexo-sso-client: the end of every sign-out (GET /auth/nexo/signed-out).
       reason=signed_out (default) — the person signed out; Nexo ID ended this
                                     device's session in every Nexo app.
       reason=ended                — Nexo ID ended the session (signed out
                                     elsewhere, revoked, expired): this tool
                                     closed its own to match.
     ADAPTATION POINT: the layout component (<x-panel-layout> is Nexo Short's)
     — wrap it in this tool's chrome. Everything inside the layout is shared. --}}
@php
    $ended = ($reason ?? 'signed_out') === 'ended';
    $heading = $ended ? __('Your Nexo ID session ended') : __('You signed out of all of Nexo');
@endphp
<x-guest-layout :title="$heading" :noindex="true" :bare="true">
    <div class="mx-auto flex w-full max-w-md flex-col justify-center px-4 py-10 sm:py-16">
        <x-nexo-auth-card :title="$heading" data-nexo-sso-signed-out="{{ $ended ? 'ended' : 'signed_out' }}">
            <p class="text-sm text-muted">
                {{ $ended
                    ? __('For your security, we also closed your session here. Sign in again to continue.')
                    : __('Your session ended in Nexo ID and in every Nexo tool on this device.') }}
            </p>

            <div class="mt-6 flex flex-col gap-3">
                <a href="{{ route('nexo-sso.enter') }}" class="nexo-btn nexo-btn--primary w-full">{{ __('Sign in again') }}</a>
                <a href="{{ url('/') }}" class="inline-flex min-h-11 items-center justify-center text-sm font-medium text-link hover:text-link-hover hover:underline">{{ __('Go to the home page') }}</a>
            </div>
        </x-nexo-auth-card>
    </div>
</x-guest-layout>
