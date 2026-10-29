<x-guest-layout :auth-card="true" :noindex="true">
    <h1 class="mb-1 text-xl font-semibold">{{ __('Create your account') }}</h1>
    <p class="mb-6 text-sm text-muted">{{ __('Create and manage your events in minutes.') }}</p>

    {{-- Same note as the login page, for the band that has no account yet (ADR-011). --}}
    @if (str_contains((string) session('url.intended'), '/app/invitaciones/'))
        <p class="nexo-flash nexo-flash--info mb-4" role="status" data-invitation-help>
            {{ __('To accept the invitation, sign in or create your account with the same email it was sent to.') }}
        </p>
    @endif

    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf

        <x-field :label="__('Your name')" name="name" required autocomplete="name" />
        <x-field :label="__('Email')" name="email" type="email" required autocomplete="username" />
        <x-field :label="__('Password')" name="password" type="password" required autocomplete="new-password" />
        <x-field :label="__('Confirm password')" name="password_confirmation" type="password" required autocomplete="new-password" />

        <x-button>{{ __('Create account') }}</x-button>
    </form>

    <p class="mt-4 text-center text-sm text-muted">
        {{ __('Already have an account?') }}
        <a href="{{ route('login') }}" class="font-medium text-link hover:underline">{{ __('Sign in') }}</a>
    </p>
</x-guest-layout>
