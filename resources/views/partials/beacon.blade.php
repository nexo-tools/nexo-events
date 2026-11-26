{{-- Cookieless beacon emitter config. IDENTICAL IN THE 6 APPS
     (templates/nexo-ui/beacon/): copy to resources/views/partials/beacon.blade.php
     and include it from the <head> of the surfaces that count (the app's own
     chrome; an app can leave a public storefront out by not including it there).

     Rendered ONLY when this instance opts in (NEXO_BEACON_ENABLED). The emitter
     (resources/js/nexo-beacon.js) reads these metas and, unless Do Not Track is
     set, posts a pageview on load. CSP-safe: the emitter ships in the app
     bundle, no inline script. The origin is this app's registry key
     (nexo-ecosystem.current) unless config('nexo.beacon.origin') overrides it;
     the hub only stores origins of its registry. --}}
@if (config('nexo.beacon.enabled'))
    <meta name="nexo:beacon-endpoint" content="{{ config('nexo.beacon.endpoint') }}">
    <meta name="nexo:beacon-origin" content="{{ config('nexo.beacon.origin') ?: config('nexo-ecosystem.current') }}">
@endif
