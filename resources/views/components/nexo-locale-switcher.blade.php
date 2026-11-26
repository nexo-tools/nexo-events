{{-- Locale switcher (es/en/pt). Default hrefs use ?lang=<code>; tools whose
     locale routing differs pass a prepared :locales map (code => label) and set
     the href convention when applying. Needs nexo-ui.js (`nexoMenu`).
     i18n: nexo.locale.label

     `inline` (chrome v2): the same links as a plain row instead of a dropdown,
     for the phone sheet and the public footer (D8) — a dropdown inside a sheet,
     or one opening off the bottom of a footer, is a menu that leaves the screen. --}}
@props([
    'locales' => ['es' => 'Español', 'en' => 'English', 'pt' => 'Português'],
    'current' => null,
    'inline' => false,
])
@php $current = $current ?? app()->getLocale(); @endphp

@if ($inline)
    <ul {{ $attributes->merge(['class' => 'nexo-locale-list']) }} data-nexo-locale-switcher data-nexo-locale-inline>
        @foreach ($locales as $code => $label)
            <li>
                <a href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}" lang="{{ $code }}" hreflang="{{ $code }}"
                   @if ($code === $current) aria-current="true" @endif>{{ $label }}</a>
            </li>
        @endforeach
    </ul>
@else
{{-- data-nexo-locale-switcher marks this menu apart from the app-switcher and
     the account menu, which share the .nexo-menu shell: a guardian asserting
     "the language control is on this page" needs to name this one. --}}
<div class="nexo-menu" data-nexo-locale-switcher x-data="nexoMenu" @keydown="onKeydown($event)" @click.outside="close({ restoreFocus: false })">
    <button
        type="button"
        class="nexo-btn nexo-btn--ghost"
        @click="toggle()"
        :aria-expanded="open"
        aria-haspopup="true"
        aria-label="{{ __('nexo.locale.label') }}"
    >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18" />
        </svg>
        <span>{{ strtoupper($current) }}</span>
    </button>

    <div class="nexo-menu__panel" x-show="open" x-cloak x-transition role="menu">
        @foreach ($locales as $code => $label)
            <a
                href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}"
                class="nexo-menu__item {{ $code === $current ? 'nexo-menu__item--current' : '' }}"
                role="menuitem"
                @if ($code === $current) aria-current="true" @endif
            >{{ $label }}</a>
        @endforeach
    </div>
</div>
@endif
