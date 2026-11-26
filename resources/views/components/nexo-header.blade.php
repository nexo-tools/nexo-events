{{-- Standard ecosystem header, chrome v2 (2026-11-26, CONTRATO-F3 §5.1).

       <x-nexo-header>                                   ← every layout, the same call
           <x-slot:nav> ...this app's own links... </x-slot:nav>   (optional)
           <x-slot:extra> ...one extra control... </x-slot:extra>  (optional)
       </x-nexo-header>

     ONE header for every layout of the app — panel, landing, help, legal, errors,
     login, «sesión cerrada». Before v2 each app had a «panel» header and a
     «public» one, and the account appeared in 5 shapes across 6 apps (audit
     2026-11-24); off the panel nothing even said you were signed in.

       desktop ≥ 768   [■ Nexo X]  <nav>        [Ayuda] [▦] [🌐 ES] [◐] [AV ▾]   (guest: [Iniciar sesión])
       phone   < 768   [■ Nexo X]                                  [▦] [AV]      (guest: [☰])

     Everything is derived (App\Support\NexoChrome), so a layout passes nothing:
       brand / mark  ← the registry entry of `current` (config/nexo-ecosystem.php)
       home          ← '/', the landing; with a session the wordmark goes to panel-url
       panel-url     ← route(config('nexo.chrome.panel_route'))
       panel-label   ← __('nexo.app.panel') («Tus herramientas», «Tus eventos»…)
     Pass a prop only to override it on a page that truly needs it.

     There is no `actions` slot any more: the account IS part of the header.
     `extra` is for one app-specific control; on the phone it moves into the sheet.
     Below 360px the wordmark shows only its tile (the link keeps its aria-label). --}}
@props([
    'brand' => null,
    'mark' => null,
    'home' => null,
    'panelUrl' => null,
    'panelLabel' => null,
])
@php
    $brand ??= \App\Support\NexoChrome::brand();
    $mark ??= \App\Support\NexoChrome::mark();
    $home ??= url('/');
    $panelUrl ??= \App\Support\NexoChrome::panelUrl();
    $panelLabel ??= \App\Support\NexoChrome::panelLabel();
    $signedIn = auth()->check();
    $helpUrl = \App\Support\NexoChrome::helpUrl();
    $signInUrl = \App\Support\NexoChrome::signInUrl();
@endphp

<header {{ $attributes->merge(['class' => 'nexo-header']) }} data-nexo-header>
    <x-nexo-wordmark :href="$signedIn ? $panelUrl : $home" :label="$brand" :mark="$mark" />

    @isset($nav)
        <nav class="nexo-header__nav" aria-label="{{ __('nexo.nav.primary') }}">{{ $nav }}</nav>
    @endisset

    <div class="nexo-header__spacer"></div>

    <div class="nexo-header__actions">
        <div class="nexo-header__desktop">
            @if ($helpUrl)
                <a href="{{ $helpUrl }}" class="nexo-header__link" data-nexo-help-link
                   @if (request()->routeIs('help')) aria-current="page" @endif>{{ __('nexo.help.title') }}</a>
            @endif
        </div>

        <x-nexo-app-switcher />

        <div class="nexo-header__desktop">
            <x-nexo-locale-switcher />
            <x-nexo-theme-toggle />
            @isset($extra){{ $extra }}@endisset

            @if ($signedIn)
                <x-nexo-account-menu :panel-url="$panelUrl" :panel-label="$panelLabel" />
            @elseif ($signInUrl)
                <a href="{{ $signInUrl }}" class="nexo-btn nexo-btn--ghost" data-nexo-sign-in>{{ __('nexo.account.sign_in') }}</a>
            @endif
        </div>

        <x-nexo-mobile-menu :brand="$brand" :mark="$mark" :panel-url="$panelUrl" :panel-label="$panelLabel"
                            :nav="$nav ?? null" :extra="$extra ?? null" />
    </div>
</header>
