{{-- Shared shell for every error page. Keeps the tool's chrome so a 404 still
     feels like the product — and, just as much, so the person who landed on it
     keeps the controls the rest of the site gives them: theme, language, the
     way back home. The 2026-08-02 audit found the inverse drift on both sides
     of the same system: nexoagenda and nexoevents served errors as a bare
     document with the favicon and the app name (no header, no footer, no
     toggles), while nexolinks served its auth pages that way and its errors
     with full chrome.

     Copy to resources/views/components/error-layout.blade.php. Since chrome v2
     the header takes no props (brand and mark come from the registry), so the
     only line an app may still adapt is the head include below (how its
     partial takes noindex). Adding a status code is one line in
     resources/views/errors/.

     The 404 must know who is signed in (HeaderEverywhereTest): an unmatched URL
     renders BEFORE the `web` group runs, with no session. The last route of
     routes/web.php is therefore `Route::fallback(fn () => abort(404))->name('fallback')`,
     excluded from the silent SSO attempt (see pages/legal/routes-snippet.php).

     noindex: an error page must never be indexed. It goes to partials.head,
     which forwards it to <x-nexo-seo>; a tool whose head partial does not
     accept $noindex adds it there — StaticPagesTest asserts the rendered meta,
     not the include, so a partial that ignores the flag is caught.

     Exception — isolated hosts (the nxo.li short host): their 404 uses the
     self-contained minimal shell instead, with no chrome, tokens or build.
     Guarded by HostChromeIsolationTest. --}}
@props(['code', 'title', 'message'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['noindex' => true])
    </head>
    <body class="flex min-h-screen flex-col bg-bg font-sans text-ink antialiased">
        <a href="#contenido" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-surface focus:px-4 focus:py-2 focus:text-link">
            {{ __('Skip to content') }}
        </a>

        <x-nexo-header />

        <main id="contenido" class="flex flex-1 flex-col items-center justify-center px-4 py-10 text-center">
            <p class="text-6xl font-bold tabular-nums text-primary">{{ $code }}</p>
            <h1 class="mt-4 text-2xl font-semibold">{{ $title }}</h1>
            <p class="mt-2 max-w-sm text-muted">{{ $message }}</p>

            <a href="{{ url('/') }}" class="nexo-btn nexo-btn--primary mt-8">
                {{ __('Back to home') }}
            </a>

            {{ $slot }}
        </main>

        <x-nexo-footer />
    </body>
</html>
