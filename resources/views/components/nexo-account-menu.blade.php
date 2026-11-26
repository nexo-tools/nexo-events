{{-- Account menu (desktop), chrome v2 — CONTRATO-F3 §5.2. The SAME order in the
     6 apps, so what a person learns in one works in all:

       1. name + email (not clickable)
       2. Tu panel, with this app's own name (panel-label)
       3. Tu cuenta Nexo ID → <issuer>/profile (not in Nexo ID itself, not standalone)
       4. Centro de ayuda
       5. separator
       6. Cerrar sesión (POST + CSRF to nexo-sso.logout with SSO on, else logout)

     The header renders it; a layout never does. Needs nexo-ui.js (`nexoMenu`:
     arrows, Home/End, Escape, focus back to the trigger). The data-nexo-account-*
     markers are what AccountMenuTest reads to assert the order. --}}
@props([
    'panelUrl' => null,
    'panelLabel' => null,
])
@php
    $user = auth()->user();
    $panelUrl ??= \App\Support\NexoChrome::panelUrl();
    $panelLabel ??= \App\Support\NexoChrome::panelLabel();
    $nexoIdUrl = \App\Support\NexoChrome::nexoIdAccountUrl();
    $helpUrl = \App\Support\NexoChrome::helpUrl();
    $logoutUrl = \App\Support\NexoChrome::logoutUrl();
    $name = (string) data_get($user, 'name', '');
    $email = (string) data_get($user, 'email', '');
@endphp

@if ($user)
    <div class="nexo-menu" data-nexo-account-menu x-data="nexoMenu" @keydown="onKeydown($event)" @click.outside="close({ restoreFocus: false })">
        <button
            type="button"
            class="nexo-btn nexo-btn--ghost nexo-account__trigger"
            x-ref="trigger"
            @click="toggle()"
            :aria-expanded="open"
            aria-expanded="false"
            aria-haspopup="menu"
            aria-label="{{ __('nexo.account.menu_for', ['name' => $name !== '' ? $name : $email]) }}"
        >
            <span class="nexo-avatar" aria-hidden="true">{{ \App\Support\NexoChrome::initials($user) }}</span>
            <svg class="nexo-account__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </button>

        <div class="nexo-menu__panel nexo-menu__panel--account" x-show="open" x-cloak x-transition role="menu" aria-label="{{ __('nexo.account.menu') }}">
            <div class="nexo-menu__identity" role="none" data-nexo-account-identity>
                @if ($name !== '')<strong>{{ $name }}</strong>@endif
                <small>{{ $email }}</small>
            </div>

            <div class="nexo-menu__sep" role="separator"></div>

            <a href="{{ $panelUrl }}" class="nexo-menu__item" role="menuitem" data-nexo-account-panel>{{ $panelLabel }}</a>

            @if ($nexoIdUrl)
                <a href="{{ $nexoIdUrl }}" class="nexo-menu__item" role="menuitem" data-nexo-account-nexoid>{{ __('nexo.account.nexo_id') }}</a>
            @endif

            @if ($helpUrl)
                <a href="{{ $helpUrl }}" class="nexo-menu__item" role="menuitem" data-nexo-account-help>{{ __('nexo.help.title') }}</a>
            @endif

            @if ($logoutUrl)
                <div class="nexo-menu__sep" role="separator" data-nexo-account-sep></div>

                <form method="POST" action="{{ $logoutUrl }}" class="nexo-menu__form" role="none" data-nexo-account-logout>
                    @csrf
                    <button type="submit" class="nexo-menu__item" role="menuitem">{{ __('nexo.account.sign_out') }}</button>
                </form>
            @endif
        </div>
    </div>
@endif
