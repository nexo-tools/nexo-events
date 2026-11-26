{{-- Light/dark toggle. Needs nexo-ui.js (Alpine) + partials/theme-init in head.
     i18n keys: nexo.theme.light / nexo.theme.dark (add to lang files).

     data-nexo-theme-toggle is how a guardian finds it: the rendered button is
     otherwise indistinguishable from any other ghost icon button, and
     AuthChromeTest has to prove a person can still change theme on a screen
     that deliberately drops the header (nexoid's focused auth).

     `labelled` (chrome v2): shows the action as text next to the icon, for the
     phone sheet and the public footer, where an unlabeled moon reads as decor.
     Pass `class` to restyle it (the sheet uses nexo-sheet__item). --}}
@props(['labelled' => false])

<button
    type="button"
    {{ $attributes->merge(['class' => $labelled ? 'nexo-btn nexo-btn--ghost' : 'nexo-btn nexo-btn--ghost nexo-btn--icon']) }}
    data-nexo-theme-toggle
    x-data="nexoTheme"
    @click="toggle()"
    @unless ($labelled) :aria-label="dark ? @js(__('nexo.theme.light')) : @js(__('nexo.theme.dark'))" @endunless
>
    {{-- Sun (shown in dark mode: click to go light) --}}
    <svg x-show="dark" x-cloak width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
    </svg>
    {{-- Moon (shown in light mode: click to go dark) --}}
    <svg x-show="!dark" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" />
    </svg>
    @if ($labelled)
        <span x-text="dark ? @js(__('nexo.theme.light')) : @js(__('nexo.theme.dark'))">{{ __('nexo.theme.dark') }}</span>
    @endif
</button>
