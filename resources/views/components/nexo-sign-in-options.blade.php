{{-- Sign-in options, chrome v2 — CONTRATO-F3 §5.4 / §2.7. THE login component
     of every Nexo app (CONTRATO-F3 §0.1): the sso-client's <x-nexo-sso.login>
     leaves that template, so a login screen never renders it, and
     LoginNexoIdFirstTest checks the screen in the three modes. Goes INSIDE the
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

     It also closes the card with «¿No tienes una cuenta? Crear cuenta», which
     goes to Nexo ID's sign-up outside local mode (NexoChrome::signUpUrl()).
     The mode comes from NexoChrome::authMode(), which defers to the sso-client's
     NexoAuthMode once session v2 is in. --}}
@php
    $mode = \App\Support\NexoChrome::authMode();
    $ssoUrl = \App\Support\NexoChrome::ssoSignInUrl();
    $signUpUrl = \App\Support\NexoChrome::signUpUrl();
    $lastDay = \App\Support\NexoChrome::transitionLastDay();
    $hasForm = trim((string) $slot) !== '';
    $passwordErrors = $errors->any() && ! $errors->has('nexo_sso');
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
            <details class="nexo-disclosure" data-nexo-password-fold @if ($passwordErrors) open @endif>
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
