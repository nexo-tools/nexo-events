{{--
    Blade components have an isolated scope: a $title or $noindex set by the
    controller does NOT reach partials.head through here. They have to be
    declared as props and forwarded explicitly, or every page silently falls
    back to the generic site metadata (which is exactly what happened until
    SeoBaseTest caught it).
--}}
@props([
    'title' => null,
    'description' => null,
    'noindex' => false,
    'seoType' => null,
    'seoImage' => null,
    'seoJsonLd' => null,
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
        <a href="#contenido" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-surface focus:px-4 focus:py-2 focus:text-brand-700">
            {{ __('Saltar al contenido') }}
        </a>

        <x-nexo-header brand="Nexo Events" mark="/ecosystem/nexoevents.svg" :home="route('home')" />

        <main id="contenido" class="flex flex-1 flex-col items-center justify-center px-4 py-10">
            <div class="w-full max-w-md rounded-2xl bg-surface p-6 shadow-sm sm:p-8">
                {{ $slot }}
            </div>
        </main>

        <x-nexo-footer />
    </body>
</html>
