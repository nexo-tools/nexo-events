{{-- Account menu for the authenticated header. Logout used to exist only as a
     text link at the foot of the dashboard, so from the event form or the door
     scanner there was no way out without walking back first.
     Needs nexo-ui.js (Alpine `nexoMenu`). --}}
@auth
    <div class="nexo-menu" x-data="nexoMenu" @keydown="onKeydown($event)" @click.outside="close({ restoreFocus: false })">
        <button
            type="button"
            class="nexo-btn nexo-btn--ghost nexo-btn--icon"
            @click="toggle()"
            :aria-expanded="open"
            aria-haspopup="true"
            aria-label="{{ __('Tu cuenta') }}"
        >
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" />
            </svg>
        </button>

        <div class="nexo-menu__panel" x-show="open" x-cloak x-transition role="menu">
            <p class="nexo-menu__label">{{ auth()->user()->email }}</p>

            <a href="{{ route('dashboard') }}" class="nexo-menu__item" role="menuitem">{{ __('Tus eventos') }}</a>
            <a href="{{ route('help') }}" class="nexo-menu__item" role="menuitem">{{ __('nexo.help.title') }}</a>

            <div class="nexo-menu__sep"></div>

            <form method="POST" action="{{ config('nexo-sso.enabled') ? route('nexo-sso.logout') : route('logout') }}">
                @csrf
                <button type="submit" class="nexo-menu__item" role="menuitem">{{ __('Cerrar sesión') }}</button>
            </form>
        </div>
    </div>
@endauth
