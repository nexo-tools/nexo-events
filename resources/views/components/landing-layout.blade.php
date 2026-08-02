{{-- The public landing's document scaffold.

     It exists rather than reusing x-guest-layout for two reasons. The guest
     layout wraps its slot in a fixed `max-w-md` card and accepts no width prop,
     and login, register, forgot/reset-password, verify-email, e/* and t/* all
     depend on that card looking exactly as it does — so widening it there would
     redesign six surfaces to fix one. And the body class that pins the footer
     uses min-h-screen, which the family guardian greps for across the whole
     landing view file; keeping the scaffold here leaves welcome.blade.php as
     nothing but the five canonical sections.

     Everything else is the same page furniture as the guest layout: same head
     partial, same skip link, same shared chrome. --}}
@props([
    'title' => null,
    'description' => null,
])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', array_filter([
            'title' => $title,
            'description' => $description,
        ], fn ($value) => $value !== null))
    </head>
    <body class="flex min-h-screen flex-col bg-bg font-sans text-ink antialiased">
        <a href="#contenido" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-surface focus:px-4 focus:py-2 focus:text-link">
            {{ __('Skip to content') }}
        </a>

        {{-- Only the hero carries a primary CTA (design.md, "CTA voice"), so the
             header's action is the ghost sign-in. --}}
        <x-nexo-header brand="Nexo Events" mark="/ecosystem/nexoevents.svg" :home="route('home')">
            <x-slot:actions>
                @auth
                    <x-nexo-account-menu />
                @else
                    <a href="{{ route('login') }}" class="nexo-btn nexo-btn--ghost">{{ __('Sign in') }}</a>
                @endauth
            </x-slot:actions>
        </x-nexo-header>

        <main id="contenido" class="flex-1">
            {{ $slot }}
        </main>

        <x-nexo-footer />
    </body>
</html>
