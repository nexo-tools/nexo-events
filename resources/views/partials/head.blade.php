<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>{{ isset($title) ? $title.' — '.config('app.name') : config('app.name') }}</title>

<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
<meta name="theme-color" content="#7c3aed">

{{-- OpenGraph / Twitter social card. og:image is the generated public/og-image.png
     (1200×630) — emitted as an absolute URL so scrapers can resolve it. --}}
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:title" content="{{ isset($title) ? $title.' — '.config('app.name') : config('app.name') }}">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:image" content="{{ url('/og-image.png') }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ config('app.name') }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ isset($title) ? $title.' — '.config('app.name') : config('app.name') }}">
<meta name="twitter:image" content="{{ url('/og-image.png') }}">

@include('partials.theme-init')

@vite(['resources/css/app.css', 'resources/js/app.js'])
