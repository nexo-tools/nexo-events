{{-- Phone sheet, chrome v2 — D6 / CONTRATO-F3 §5.1. Under 768px the header keeps
     the wordmark, the switcher and ONE button: the avatar with a session, ☰
     without. It opens a full-width sheet with everything else:

       signed in   identity · own nav · Tu panel · Tu cuenta Nexo ID · Centro de
                   ayuda · Idioma · Tema · Cerrar sesión
       guest       Iniciar sesión · Crear cuenta · Centro de ayuda · Idioma · Tema

     Before v2 there was no menu at all on phones: «Iniciar sesión» and the help
     link were hidden, and «Cerrar sesión» was clipped or off-screen at 320px in
     four apps (audit 2026-11-24).

     A dialog, not a menu: role="dialog" + aria-modal, focus trapped inside
     (nexoMenu modal mode), Escape and the close button both close it and return
     focus to the trigger. The header renders it; a layout never does. --}}
@props([
    'brand' => null,
    'mark' => null,
    'panelUrl' => null,
    'panelLabel' => null,
    'nav' => null,
    'extra' => null,
])
@php
    $user = auth()->user();
    $brand ??= \App\Support\NexoChrome::brand();
    $mark ??= \App\Support\NexoChrome::mark();
    $panelUrl ??= \App\Support\NexoChrome::panelUrl();
    $panelLabel ??= \App\Support\NexoChrome::panelLabel();
    $nexoIdUrl = \App\Support\NexoChrome::nexoIdAccountUrl();
    $helpUrl = \App\Support\NexoChrome::helpUrl();
    $logoutUrl = \App\Support\NexoChrome::logoutUrl();
    $signInUrl = \App\Support\NexoChrome::signInUrl();
    $signUpUrl = \App\Support\NexoChrome::signUpUrl();
    $name = (string) data_get($user, 'name', '');
    $email = (string) data_get($user, 'email', '');
    $sheetId = 'nexo-sheet';
@endphp

<div class="nexo-header__mobile" data-nexo-mobile-menu x-data="nexoMenu({ modal: true })" @keydown="onKeydown($event)">
    <button
        type="button"
        class="nexo-btn nexo-btn--ghost nexo-btn--icon"
        x-ref="trigger"
        @click="toggle()"
        :aria-expanded="open"
        aria-expanded="false"
        aria-haspopup="dialog"
        aria-controls="{{ $sheetId }}"
        aria-label="{{ __('nexo.mobile.open') }}"
    >
        @if ($user)
            <span class="nexo-avatar" aria-hidden="true">{{ \App\Support\NexoChrome::initials($user) }}</span>
        @else
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        @endif
    </button>

    <div
        id="{{ $sheetId }}"
        class="nexo-sheet"
        x-ref="panel"
        tabindex="-1"
        x-show="open"
        x-cloak
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('nexo.mobile.title') }}"
        data-nexo-sheet
    >
        <div class="nexo-sheet__bar">
            <span class="nexo-sheet__brand">
                <img src="{{ $mark }}" alt="" width="32" height="32" />
                <span class="nexo-sheet__title">{{ $brand }}</span>
            </span>
            <button type="button" class="nexo-btn nexo-btn--ghost nexo-btn--icon" @click="close()" aria-label="{{ __('nexo.mobile.close') }}" data-nexo-sheet-close>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        <div class="nexo-sheet__body">
            @if ($user)
                <div class="nexo-sheet__identity" data-nexo-sheet-identity>
                    @if ($name !== '')<strong>{{ $name }}</strong>@endif
                    <small>{{ $email }}</small>
                </div>

                @if ($nav)
                    <nav class="nexo-sheet__nav nexo-sheet__section" aria-label="{{ __('nexo.nav.primary') }}" data-nexo-sheet-nav>{{ $nav }}</nav>
                @endif

                <div class="nexo-sheet__section">
                    <a href="{{ $panelUrl }}" class="nexo-sheet__item" data-nexo-sheet-panel>{{ $panelLabel }}</a>
                    @if ($nexoIdUrl)
                        <a href="{{ $nexoIdUrl }}" class="nexo-sheet__item" data-nexo-sheet-nexoid>{{ __('nexo.account.nexo_id') }}</a>
                    @endif
                    @if ($helpUrl)
                        <a href="{{ $helpUrl }}" class="nexo-sheet__item" data-nexo-sheet-help
                           @if (request()->routeIs('help')) aria-current="page" @endif>{{ __('nexo.help.title') }}</a>
                    @endif
                </div>
            @else
                <div class="nexo-sheet__section nexo-sheet__section--first">
                    @if ($signInUrl)
                        <a href="{{ $signInUrl }}" class="nexo-btn nexo-btn--primary" data-nexo-sheet-sign-in>{{ __('nexo.account.sign_in') }}</a>
                    @endif
                    @if ($signUpUrl)
                        <a href="{{ $signUpUrl }}" class="nexo-sheet__item" data-nexo-sheet-sign-up>{{ __('nexo.account.sign_up') }}</a>
                    @endif
                    @if ($helpUrl)
                        <a href="{{ $helpUrl }}" class="nexo-sheet__item" data-nexo-sheet-help
                           @if (request()->routeIs('help')) aria-current="page" @endif>{{ __('nexo.help.title') }}</a>
                    @endif
                </div>

                @if ($nav)
                    <nav class="nexo-sheet__nav nexo-sheet__section" aria-label="{{ __('nexo.nav.primary') }}" data-nexo-sheet-nav>{{ $nav }}</nav>
                @endif
            @endif

            @if ($extra)
                <div class="nexo-sheet__section nexo-sheet__row" data-nexo-sheet-extra>{{ $extra }}</div>
            @endif

            <div class="nexo-sheet__section" data-nexo-sheet-language>
                <p class="nexo-sheet__label" id="{{ $sheetId }}-language">{{ __('nexo.mobile.language') }}</p>
                <div class="nexo-sheet__row">
                    <x-nexo-locale-switcher inline aria-labelledby="{{ $sheetId }}-language" />
                </div>
            </div>

            <div class="nexo-sheet__section" data-nexo-sheet-theme>
                <p class="nexo-sheet__label">{{ __('nexo.mobile.theme') }}</p>
                <x-nexo-theme-toggle labelled class="nexo-sheet__item" />
            </div>

            @if ($user && $logoutUrl)
                <div class="nexo-sheet__section">
                    <form method="POST" action="{{ $logoutUrl }}" class="nexo-sheet__form" data-nexo-sheet-logout>
                        @csrf
                        <button type="submit" class="nexo-sheet__item">{{ __('nexo.account.sign_out') }}</button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</div>
