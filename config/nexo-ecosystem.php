<?php

// Nexo ecosystem registry — the single source for the app-switcher and the
// footer's ecosystem links. Copy into a tool's config/ as `nexo-ecosystem.php`
// and set `current` to that tool's key (default = the tool's own slug, so a
// self-hoster that forgets the env still gets the switcher marked right).
// URLs are env-overridable so self-hosters can point at their own instances.
// Marks come from nexo-brand/marks/<key>.svg (copied into the tool's public/).
//
// THIS BLOCK IS IDENTICAL IN THE 6 TOOLS. Changing a tagline, a status or the
// order means propagating the same block to every repo — never editing one.
// `scripts/nexo-doctor.mjs` (in the `alvaro` repo) enforces exactly that.

return [

    // Which tool is this? The app-switcher marks it as current. One of the keys
    // in `tools` below. When copying into a tool, replace the default with that
    // tool's own key ('nexolinks', 'nexoid', …); null only for a standalone
    // self-host with no ecosystem identity.
    'current' => env('NEXO_ECOSYSTEM_CURRENT', 'nexoevents'),

    // Portada (non-dev entry point) and the developer entry points.
    'hub_url' => env('NEXO_HUB_URL', 'https://nexotools.alvarocdev.com'),
    'github_org_url' => env('NEXO_GITHUB_ORG', 'https://github.com/nexo-tools'),
    'author_url' => env('NEXO_ATTRIBUTION_URL', 'https://alvarocdev.com'),

    // The tools. `status`: 'live' | 'soon'. `mark`: public path to the isotype.
    // `tagline` is the Spanish fallback: the chrome shows the translation from
    // lang/{es,en,pt}/nexo.php → nexo.ecosystem.<key> (NexoChrome::tagline).
    // `entry`: path that opens the tool's panel already signed in. The switcher
    // uses `url + entry` when there is a session and SSO is on (chrome v2); the
    // tools' entry is the sso-client route `nexo-sso.enter`, Nexo ID's is the
    // account itself.
    // Nexo Short's URL is its app (nexoshort.alvarocdev.com) — nxo.li is the
    // short-link host and its root 404s by design, so it is never the "live" URL.
    'tools' => [
        'nexotools' => [
            'name' => 'Nexo Tools',
            'tagline' => 'El hub abierto del ecosistema Nexo.',
            'url' => env('NEXO_URL_TOOLS', 'https://nexotools.alvarocdev.com'),
            'mark' => '/ecosystem/nexotools.svg',
            'entry' => '/auth/nexo/enter',
            'status' => 'live',
        ],
        'nexoid' => [
            'name' => 'Nexo ID',
            'tagline' => 'Una sola cuenta para todo el ecosistema Nexo.',
            'url' => env('NEXO_URL_ID', 'https://nexoid.alvarocdev.com'),
            'mark' => '/ecosystem/nexoid.svg',
            'entry' => '/profile',
            'status' => 'live',
        ],
        'nexolinks' => [
            'name' => 'Nexo Links',
            'tagline' => 'Comparte todos tus links en un solo lugar.',
            'url' => env('NEXO_URL_LINKS', 'https://nexolinks.alvarocdev.com'),
            'mark' => '/ecosystem/nexolinks.svg',
            'entry' => '/auth/nexo/enter',
            'status' => 'live',
        ],
        'nexoagenda' => [
            'name' => 'Nexo Agenda',
            'tagline' => 'Recibe reservas para tu negocio en minutos.',
            'url' => env('NEXO_URL_AGENDA', 'https://nexoagenda.alvarocdev.com'),
            'mark' => '/ecosystem/nexoagenda.svg',
            'entry' => '/auth/nexo/enter',
            'status' => 'live',
        ],
        'nexoshort' => [
            'name' => 'Nexo Short',
            'tagline' => 'Acorta enlaces con métricas sin cookies.',
            'url' => env('NEXO_URL_SHORT', 'https://nexoshort.alvarocdev.com'),
            'mark' => '/ecosystem/nexoshort.svg',
            'entry' => '/auth/nexo/enter',
            'status' => 'live',
        ],
        'nexoevents' => [
            'name' => 'Nexo Events',
            'tagline' => 'Crea eventos gratis y valida entradas con QR.',
            'url' => env('NEXO_URL_EVENTS', 'https://nexoevents.alvarocdev.com'),
            'mark' => '/ecosystem/nexoevents.svg',
            'entry' => '/auth/nexo/enter',
            'status' => 'live',
        ],
    ],
];
