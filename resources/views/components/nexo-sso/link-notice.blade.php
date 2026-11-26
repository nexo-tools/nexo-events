{{-- nexo-sso-client: on the login screen, while «I already have an account
     with another email» is waiting (transition mode, 15 minutes), says what
     signing in with a password leads to: «Link this account?», where the
     person who signed in confirms the join. Renders nothing otherwise. Place it inside
     the login card, above the sign-in options:

       <x-nexo-sso.link-notice class="mb-4" /> --}}
@php
    $request = request();
    $email = config('nexo-sso.enabled') && \App\Services\NexoSso\NexoSsoNewAccount::armed($request) !== null
        ? \App\Services\NexoSso\NexoSsoNewAccount::email($request)
        : null;
@endphp
@if ($email !== null)
    <p role="note" {{ $attributes->merge(['class' => 'rounded-md border border-line bg-bg-subtle px-3 py-2 text-sm text-ink']) }} data-nexo-sso-link-notice>
        {{ __('Sign in with the email and password of your :app account. Then you can link it to the Nexo ID :email.', ['app' => config('app.name'), 'email' => $email]) }}
    </p>
@endif
