<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

{{-- One component emits title/description/canonical/OG/twitter/hreflang/JSON-LD
     for every page (nexo-ui standard), which is what stops the per-page drift
     this file used to have. Pages set $title/$description/$seo* before including
     the layout; private pages pass $noindex. --}}
<x-nexo-seo
    :title="isset($title) ? $title.' — '.config('app.name') : config('app.name')"
    :description="$description ?? __('Create free events, take registrations by email and validate tickets with a QR code at the door.')"
    :image="$seoImage ?? '/og-image.png'"
    :type="$seoType ?? 'website'"
    :noindex="$noindex ?? false"
    :jsonld="($jsonld ?? true) && ! ($noindex ?? false)"
/>

@isset($seoJsonLd)
    {{-- Page-specific structured data (e.g. schema.org/Event) on top of the
         component's WebSite block. --}}
    <script type="application/ld+json">{!! json_encode($seoJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endisset

<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">

@include('partials.theme-init')
@include('partials.beacon')

{{-- @vite builds the woff2 files but never asks for them: the @font-face rules
     only ship if Vite::fonts() emits them. Without this line the family face is
     published to public/build/assets and nobody requests it, so every page falls
     back to the system stack. It goes before @vite so the face is known when the
     CSS lands. --}}
{{ Vite::fonts() }}
@vite(['resources/css/app.css', 'resources/js/app.js'])
