{{-- Sign-in options, chrome v2 — CONTRATO-F3 §5.4 / §2.7. THE login component
     of every Nexo app (CONTRATO-F3 §0.1; the sso-client template has no login
     view of its own since v2.1), and LoginNexoIdFirstTest checks the screen in
     the three modes. Goes INSIDE the
     nexo-auth-card of the login screen; the slot is the app's own email +
     password form. With SSO on, «Continuar con Nexo ID» is THE primary action,
     first and alone in violet; what happens to the password form depends on
     NEXO_AUTH_MODE (NexoChrome::authMode()):

       local       Nexo ID first, a divider, then the password form (the slot)
       transition  Nexo ID first; the password form folded under «Usar email y
                   contraseña», with the cut-over date
       sso         Nexo ID only: the slot is not rendered at all
       SSO off     just the slot (standalone self-host, unchanged)

       <x-nexo-auth-card :title="__('Sign in')">
           <x-nexo-sign-in-options>
               <form method="POST" action="{{ route('login') }}">…</form>
           </x-nexo-sign-in-options>
       </x-nexo-auth-card>

     Before v2 every tool put its local form first and Nexo ID last, under an
     «o» (H-cruce-08) — so people typed their Nexo ID password into a form that
     could never accept it. The slot's own submit should be a ghost button when
     NexoChrome::ssoSignInUrl() is set: one violet button per screen.

     «Recordarme» belongs to the slot, and only where
     NexoChrome::allowsRemember() says so (local mode, or SSO off): in
     transition and sso a tool emits no remember-me of its own.

       @if (\App\Support\NexoChrome::allowsRemember())
           <label><input type="checkbox" name="remember"> {{ __('Remember me') }}</label>
       @endif

     `password-open` (v2.2, optional, default false) opens the transition fold
     from the start. The login view passes it when the person already chose
     «Ya tengo una cuenta con otro email» on «¿Primera vez en <App>?»: they
     came here to type their password, so the fold would be one more click.
     The expression to pass is the sso-client's (see its README):

       <x-nexo-sign-in-options :password-open="…">…</x-nexo-sign-in-options>

     A failed password attempt opens the fold too, as before. In local mode
     the form is never folded, and in sso there is none: the prop changes
     nothing there.

     It also closes the card with «¿No tienes una cuenta? Crear cuenta»: the
     local sign-up in local mode, and outside it Nexo ID's sign-up through this
     tool, so the new account comes back here (NexoChrome::signUpUrl(), v2.2).
     The mode comes from NexoChrome::authMode(), which defers to the sso-client's
     NexoAuthMode once session v2 is in. --}}
@props(['passwordOpen' => false])
@php
    $mode = \App\Support\NexoChrome::authMode();
    $ssoUrl = \App\Support\NexoChrome::ssoSignInUrl();
    $signUpUrl = \App\Support\NexoChrome::signUpUrl();
    $lastDay = \App\Support\NexoChrome::transitionLastDay();
    $hasForm = trim((string) $slot) !== '';
    $passwordErrors = $errors->any() && ! $errors->has('nexo_sso');
    $passwordOpen = filter_var($passwordOpen, FILTER_VALIDATE_BOOLEAN);
@endphp

<div {{ $attributes }} data-nexo-sign-in-options data-nexo-auth-mode="{{ $mode }}">
    @if ($ssoUrl)
        @error('nexo_sso')
            <p class="nexo-flash nexo-flash--danger nexo-auth-alert" role="alert">{{ $message }}</p>
        @enderror

        <a href="{{ $ssoUrl }}" class="nexo-btn nexo-btn--primary nexo-btn--block" data-nexo-sso-primary>
            {{ __('nexo.auth.continue') }}
        </a>

        @if ($hasForm && $mode === 'local')
            <p class="nexo-divider">{{ __('nexo.auth.or_password') }}</p>
            {{ $slot }}
        @elseif ($hasForm && $mode === 'transition')
            <details class="nexo-disclosure" data-nexo-password-fold @if ($passwordErrors || $passwordOpen) open @endif>
                <summary>{{ __('nexo.auth.use_password') }}</summary>
                @if ($lastDay)
                    <p class="nexo-auth-note">{{ __('nexo.auth.transition_until', ['date' => $lastDay]) }}</p>
                @endif
                {{ $slot }}
            </details>
        @endif
    @else
        {{ $slot }}
    @endif

    @if ($signUpUrl)
        <p class="nexo-auth-foot">
            {{ __('nexo.auth.no_account') }}
            <a href="{{ $signUpUrl }}" data-nexo-sign-up>{{ __('nexo.account.sign_up') }}</a>
        </p>
    @endif
</div>
