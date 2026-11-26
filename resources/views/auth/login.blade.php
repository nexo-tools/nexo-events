{{-- Sign-in, chrome v2: «Continuar con Nexo ID» first and primary when SSO is
     on (x-nexo-sign-in-options, STANDARD «Login», LoginNexoIdFirstTest); the
     local form follows as the secondary way in, with a ghost submit so the
     screen has one violet button. The auth mode (NEXO_AUTH_MODE) decides
     whether the local form shows, folds or disappears: in sso mode this page
     is only the Nexo ID button. --}}
@php($ssoFirst = \App\Support\NexoChrome::ssoSignInUrl() !== null)
<x-guest-layout :auth-card="true" :noindex="true" :title="__('Sign in')">
    <h1 class="mb-6 text-xl font-semibold">{{ __('Sign in to your account') }}</h1>

    {{-- Arriving from a collaborator invitation (ADR-011): the one rule people
         trip on is the email address. Generic on purpose — it never says
         whether that address already has an account. --}}
    @if (str_contains((string) session('url.intended'), '/app/invitaciones/'))
        <p class="nexo-flash nexo-flash--info mb-4" role="status" data-invitation-help>
            {{ __('To accept the invitation, sign in or create your account with the same email it was sent to.') }}
        </p>
    @endif

    @if (session('status'))
        <p class="nexo-flash mb-4" role="status">{{ session('status') }}</p>
    @endif

    {{-- «Ya tengo una cuenta con otro email» waiting (transition): says which
         Nexo ID a password sign-in will offer to join. Renders nothing otherwise. --}}
    <x-nexo-sso.link-notice />

    {{-- password-open: after «Ya tengo una cuenta con otro email» (transition)
         the person came to type a password, so the fold starts open. --}}
    <x-nexo-sign-in-options :password-open="\App\Services\NexoSso\NexoSsoNewAccount::awaitsPasswordSignIn(request())">
        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf

            <x-field :label="__('Email')" name="email" type="email" required autocomplete="username" />
            <x-field :label="__('Password')" name="password" type="password" required autocomplete="current-password" />

            @if (\App\Support\NexoChrome::allowsRemember())
                <label class="flex min-h-11 items-center gap-2 text-sm">
                    <input type="checkbox" name="remember" class="rounded border-control bg-surface text-primary focus:ring-ring">
                    {{ __('Remember me') }}
                </label>
            @endif

            @if ($ssoFirst)
                <button type="submit" class="nexo-btn nexo-btn--ghost w-full">{{ __('Sign in') }}</button>
            @else
                <x-button>{{ __('Sign in') }}</x-button>
            @endif

            <p class="text-center text-sm">
                <a href="{{ route('password.request') }}" class="inline-flex min-h-11 items-center text-link hover:underline">
                    {{ __('Forgot your password?') }}
                </a>
            </p>
        </form>
    </x-nexo-sign-in-options>
</x-guest-layout>
