{{-- Ecosystem app-switcher. Source: config/nexo-ecosystem.php. Marks the current
     tool, and carries the "discover all on nexotools" invite + the developers
     (GitHub org) link — the standing bridge from every panel to the hub.
     Needs nexo-ui.js (Alpine `nexoMenu`). i18n: nexo.switcher.*, nexo.ecosystem.*

     Chrome v2 (2026-11-26):
       - with a session and SSO on, each row opens that tool's panel already
         signed in (`url + entry`, NexoChrome::switcherHref); a visitor still
         lands on the tool's page. Switching used to cost 3-4 clicks and always
         ended on a marketing page (H-cruce-04).
       - the descriptions come translated (nexo.ecosystem.<key>).
       - on phones the panel opens edge to edge (.nexo-menu--wide). --}}
@php
    $eco = config('nexo-ecosystem', []);
    $current = $eco['current'] ?? null;
    $tools = $eco['tools'] ?? [];
@endphp

<div class="nexo-menu nexo-menu--wide" data-nexo-app-switcher x-data="nexoMenu" @keydown="onKeydown($event)" @click.outside="close({ restoreFocus: false })">
    <button
        type="button"
        class="nexo-btn nexo-btn--ghost nexo-btn--icon"
        @click="toggle()"
        :aria-expanded="open"
        aria-haspopup="true"
        aria-label="{{ __('nexo.switcher.label') }}"
    >
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <rect x="3" y="3" width="7" height="7" rx="2" /><rect x="14" y="3" width="7" height="7" rx="2" />
            <rect x="3" y="14" width="7" height="7" rx="2" /><rect x="14" y="14" width="7" height="7" rx="2" />
        </svg>
    </button>

    <div class="nexo-menu__panel" x-show="open" x-cloak x-transition role="menu">
        <p class="nexo-menu__label">{{ __('nexo.switcher.title') }}</p>

        @foreach ($tools as $key => $tool)
            <a
                href="{{ \App\Support\NexoChrome::switcherHref($key, $tool) }}"
                class="nexo-menu__item {{ $key === $current ? 'nexo-menu__item--current' : '' }}"
                role="menuitem"
                @if ($key === $current) aria-current="true" @endif
            >
                <img src="{{ $tool['mark'] }}" alt="" width="24" height="24" />
                <span>
                    {{ $tool['name'] }}
                    <small>{{ \App\Support\NexoChrome::tagline($key, $tool) }}</small>
                </span>
                @if (($tool['status'] ?? 'live') === 'soon')
                    <span class="nexo-badge-soon">{{ __('nexo.switcher.soon') }}</span>
                @endif
            </a>
        @endforeach

        <div class="nexo-menu__sep"></div>
        <div class="nexo-menu__foot">
            <svg class="nexo-menu__glyph" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <a href="{{ $eco['hub_url'] ?? '#' }}">{{ __('nexo.switcher.discover') }}</a>
        </div>
        <div class="nexo-menu__foot">
            <svg class="nexo-menu__glyph" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18-6-6 6-6"/><path d="m15 6 6 6-6 6"/></svg>
            <a href="{{ $eco['github_org_url'] ?? '#' }}" rel="noopener">{{ __('nexo.switcher.developers') }}</a>
        </div>
    </div>
</div>
