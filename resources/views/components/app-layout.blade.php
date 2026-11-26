{{--
    The organizer's shell. Separate from x-guest-layout because that one is a
    centred max-w-md card built for a login form, and the event list, the CRUD
    and the registrations table were being squeezed into it.

    Same caveat as guest-layout: a Blade component has an isolated scope, so
    the SEO props have to be forwarded to partials.head explicitly.
--}}
@props([
    'title' => null,
    'description' => null,
    'noindex' => true,
])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', array_filter([
            'title' => $title,
            'description' => $description,
            'noindex' => $noindex,
        ], fn ($value) => $value !== null))
    </head>
    <body class="flex min-h-screen flex-col bg-bg font-sans text-ink antialiased">
        <a href="#contenido" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-surface focus:px-4 focus:py-2 focus:text-link">
            {{ __('Skip to content') }}
        </a>

        {{-- The canonical v2 header, the same on every layout: with a session
             the wordmark goes to the panel and the account menu (and the phone
             sheet) carries «Tus eventos» and «Cerrar sesión». --}}
        <x-nexo-header />

        <main id="contenido" class="flex-1 px-4 py-8">
            <div class="mx-auto w-full max-w-3xl rounded-2xl bg-surface p-6 shadow-sm sm:p-8">
                {{ $slot }}
            </div>
        </main>

        <x-nexo-footer />
    </body>
</html>
