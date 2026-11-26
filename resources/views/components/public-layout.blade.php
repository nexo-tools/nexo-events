{{-- The public CONTENT page's shell (D8, CONTRATO-F3 §5.4): the event page
     /e/{slug}. Whoever opens it is an attendee of that organizer's event, not a
     user of Nexo — before v2 they got the tool's header (the switcher of Nexo
     tools, a wordmark leading to the organizers' landing; H-nexoevents-18).

     Now: no tool header. The page itself in the same card it always had, and
     <x-nexo-public-footer> with language, theme, «Hecho con Nexo Events» and —
     only for the owner or a collaborator with a session — the «Editar» chip
     (PublicFooterTest guards the component, PublicEventChromeTest this page).

     Same SEO plumbing as the guest layout (a Blade component has an isolated
     scope, so the props are forwarded to partials.head explicitly). --}}
@props([
    'title' => null,
    'description' => null,
    'noindex' => false,
    'seoType' => null,
    'seoImage' => null,
    'seoJsonLd' => null,
    // The editor of this page for whoever may change it, or null (everybody else).
    'editUrl' => null,
])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', array_filter([
            'title' => $title,
            'description' => $description,
            'noindex' => $noindex,
            'seoType' => $seoType,
            'seoImage' => $seoImage,
            'seoJsonLd' => $seoJsonLd,
        ], fn ($value) => $value !== null))
    </head>
    <body class="flex min-h-screen flex-col bg-bg font-sans text-ink antialiased">
        <a href="#contenido" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-surface focus:px-4 focus:py-2 focus:text-link">
            {{ __('Skip to content') }}
        </a>

        <main id="contenido" class="flex flex-1 flex-col items-center justify-center px-4 py-10">
            <div class="w-full max-w-md rounded-2xl bg-surface p-6 shadow-sm sm:p-8">
                {{ $slot }}
            </div>
        </main>

        <x-nexo-public-footer :edit-url="$editUrl" />
    </body>
</html>
